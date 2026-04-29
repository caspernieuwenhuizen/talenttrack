<?php
namespace TT\Modules\Methodology\Repositories;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * MethodologyAssetsRepository — `tt_methodology_assets` data access.
 *
 * Polymorphic image attachments. The `entity_type` column is one of:
 *   - principle, set_piece, position, vision
 *   - framework, phase, learning_goal, influence_factor, football_action
 *
 * `attachment_id` references a `wp_posts` row (post_type='attachment').
 * `is_primary` selects the hero image when an entity has multiple.
 *
 * Methods:
 *   listFor(type, id)        — all non-archived assets, primary first
 *   primaryFor(type, id)     — single hero asset or null
 *   create(data)             — insert
 *   update(id, data)         — partial update
 *   archive(id), restore(id) — soft delete
 *   setPrimary(type, id, asset_id) — flips is_primary in transaction
 */
final class MethodologyAssetsRepository {

    public const TYPE_PRINCIPLE        = 'principle';
    public const TYPE_SET_PIECE        = 'set_piece';
    public const TYPE_POSITION         = 'position';
    public const TYPE_VISION           = 'vision';
    public const TYPE_FRAMEWORK        = 'framework';
    public const TYPE_PHASE            = 'phase';
    public const TYPE_LEARNING_GOAL    = 'learning_goal';
    public const TYPE_INFLUENCE_FACTOR = 'influence_factor';
    public const TYPE_FOOTBALL_ACTION  = 'football_action';
    public const TYPE_FORMATION        = 'formation';

    private function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'tt_methodology_assets';
    }

    /** @return object[] */
    public function listFor( string $entity_type, int $entity_id, bool $include_archived = false ): array {
        global $wpdb;
        $t = $this->table();
        $where = $include_archived ? '' : ' AND archived_at IS NULL';
        return (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$t} WHERE entity_type = %s AND entity_id = %d AND club_id = %d{$where}
             ORDER BY is_primary DESC, sort_order ASC, id ASC",
            $entity_type, $entity_id, CurrentClub::id()
        ) );
    }

    public function primaryFor( string $entity_type, int $entity_id ): ?object {
        global $wpdb;
        $t = $this->table();
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$t}
             WHERE entity_type = %s AND entity_id = %d AND club_id = %d AND archived_at IS NULL
             ORDER BY is_primary DESC, sort_order ASC, id ASC LIMIT 1",
            $entity_type, $entity_id, CurrentClub::id()
        ) );
        return $row ?: null;
    }

    public function find( int $id ): ?object {
        global $wpdb;
        $t = $this->table();
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$t} WHERE id = %d AND club_id = %d",
            $id, CurrentClub::id()
        ) );
        return $row ?: null;
    }

    /** @param array<string,mixed> $data */
    public function create( array $data ): int {
        global $wpdb;
        $row = [
            'club_id'       => CurrentClub::id(),
            'entity_type'   => isset( $data['entity_type'] ) ? sanitize_key( (string) $data['entity_type'] ) : '',
            'entity_id'     => isset( $data['entity_id'] )   ? (int) $data['entity_id']                       : 0,
            'attachment_id' => isset( $data['attachment_id'] ) ? (int) $data['attachment_id']               : 0,
            'caption_json'  => $this->encodeJson( $data['caption_json'] ?? null ),
            'sort_order'    => isset( $data['sort_order'] ) ? (int) $data['sort_order'] : 0,
            'is_primary'    => ! empty( $data['is_primary'] ) ? 1 : 0,
            'is_shipped'    => ! empty( $data['is_shipped'] ) ? 1 : 0,
        ];
        if ( $row['attachment_id'] <= 0 || $row['entity_id'] <= 0 || $row['entity_type'] === '' ) return 0;
        $wpdb->insert( $this->table(), $row );
        $new_id = (int) $wpdb->insert_id;
        if ( $new_id > 0 && $row['is_primary'] ) {
            $this->demoteOtherPrimaries( $row['entity_type'], $row['entity_id'], $new_id );
        }
        return $new_id;
    }

    /** @param array<string,mixed> $data */
    public function update( int $id, array $data ): bool {
        global $wpdb;
        $row = [];
        if ( array_key_exists( 'caption_json', $data ) ) $row['caption_json'] = $this->encodeJson( $data['caption_json'] );
        if ( array_key_exists( 'sort_order',   $data ) ) $row['sort_order']   = (int) $data['sort_order'];
        if ( array_key_exists( 'is_primary',   $data ) ) $row['is_primary']   = ! empty( $data['is_primary'] ) ? 1 : 0;
        if ( empty( $row ) ) return true;
        $ok = $wpdb->update( $this->table(), $row, [ 'id' => $id, 'club_id' => CurrentClub::id() ] ) !== false;
        if ( $ok && ! empty( $row['is_primary'] ) ) {
            $current = $this->find( $id );
            if ( $current ) $this->demoteOtherPrimaries( (string) $current->entity_type, (int) $current->entity_id, $id );
        }
        return $ok;
    }

    public function archive( int $id ): bool {
        global $wpdb;
        return $wpdb->update(
            $this->table(),
            [ 'archived_at' => current_time( 'mysql', true ) ],
            [ 'id' => $id, 'club_id' => CurrentClub::id() ]
        ) !== false;
    }

    public function setPrimary( string $entity_type, int $entity_id, int $asset_id ): bool {
        global $wpdb;
        $t = $this->table();
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$t} SET is_primary = 0
             WHERE entity_type = %s AND entity_id = %d AND club_id = %d AND id <> %d",
            $entity_type, $entity_id, CurrentClub::id(), $asset_id
        ) );
        return $wpdb->update( $t, [ 'is_primary' => 1 ], [ 'id' => $asset_id, 'club_id' => CurrentClub::id() ] ) !== false;
    }

    private function demoteOtherPrimaries( string $entity_type, int $entity_id, int $keep_id ): void {
        global $wpdb;
        $t = $this->table();
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$t} SET is_primary = 0
             WHERE entity_type = %s AND entity_id = %d AND club_id = %d AND id <> %d",
            $entity_type, $entity_id, CurrentClub::id(), $keep_id
        ) );
    }

    private function encodeJson( $value ): ?string {
        if ( $value === null )          return null;
        if ( is_string( $value ) )      return $value;
        if ( is_array( $value ) )       return (string) wp_json_encode( $value );
        return null;
    }
}
