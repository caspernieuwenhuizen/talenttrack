<?php
namespace TT\Modules\Methodology\Repositories;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * MethodologyVisionRepository — `tt_methodology_visions` data access.
 *
 * The vision is intended as one-record-per-club. v1 stores a `club_scope`
 * VARCHAR (NULL for the TT-shipped sample, a club identifier when added);
 * `activeForClub()` is the canonical reader for "show me this site's
 * vision."
 */
class MethodologyVisionRepository {

    private function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'tt_methodology_visions';
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

    /**
     * Return the active vision row for this site. Order:
     *
     *   1. Most-recently-updated club-authored row (is_shipped = 0).
     *   2. Otherwise the shipped sample.
     *   3. Null if neither exists.
     */
    public function activeForClub(): ?object {
        global $wpdb;
        $t = $this->table();
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$t} WHERE club_id = %d AND archived_at IS NULL AND is_shipped = 0 ORDER BY updated_at DESC LIMIT 1",
            CurrentClub::id()
        ) );
        if ( $row ) return $row;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$t} WHERE club_id = %d AND archived_at IS NULL AND is_shipped = 1 ORDER BY updated_at DESC LIMIT 1",
            CurrentClub::id()
        ) );
        return $row ?: null;
    }

    /** @return object[] */
    public function listAll(): array {
        global $wpdb;
        $t = $this->table();
        return (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$t} WHERE club_id = %d AND archived_at IS NULL ORDER BY is_shipped ASC, updated_at DESC",
            CurrentClub::id()
        ) );
    }

    /** @param array<string,mixed> $data */
    public function create( array $data ): int {
        global $wpdb;
        $row = $this->normalize( $data, true );
        $row['club_id'] = CurrentClub::id();
        $wpdb->insert( $this->table(), $row );
        return (int) $wpdb->insert_id;
    }

    /** @param array<string,mixed> $data */
    public function update( int $id, array $data ): bool {
        global $wpdb;
        $row = $this->normalize( $data, false );
        if ( empty( $row ) ) return true;
        return $wpdb->update( $this->table(), $row, [ 'id' => $id, 'club_id' => CurrentClub::id() ] ) !== false;
    }

    public function archive( int $id ): bool {
        global $wpdb;
        return $wpdb->update(
            $this->table(),
            [ 'archived_at' => current_time( 'mysql', true ) ],
            [ 'id' => $id, 'club_id' => CurrentClub::id() ]
        ) !== false;
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function normalize( array $data, bool $for_insert ): array {
        $out = [];
        if ( $for_insert ) {
            $out['club_scope']        = isset( $data['club_scope'] ) ? sanitize_text_field( (string) $data['club_scope'] ) : null;
            $out['style_of_play_key'] = isset( $data['style_of_play_key'] ) ? sanitize_key( (string) $data['style_of_play_key'] ) : null;
            $out['is_shipped']        = ! empty( $data['is_shipped'] ) ? 1 : 0;
        } else {
            if ( array_key_exists( 'style_of_play_key', $data ) ) {
                $out['style_of_play_key'] = $data['style_of_play_key'] === null ? null : sanitize_key( (string) $data['style_of_play_key'] );
            }
        }
        if ( array_key_exists( 'formation_id', $data ) ) {
            $out['formation_id'] = $data['formation_id'] === null ? null : (int) $data['formation_id'];
        }
        foreach ( [ 'way_of_playing_json', 'important_traits_json', 'notes_json' ] as $col ) {
            if ( array_key_exists( $col, $data ) ) {
                $out[ $col ] = is_array( $data[ $col ] ) ? (string) wp_json_encode( $data[ $col ] ) : (string) $data[ $col ];
            }
        }
        return $out;
    }
}
