<?php
namespace TT\Modules\Methodology\Repositories;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * FootballActionsRepository — `tt_football_actions`.
 *
 * Catalogue of voetbalhandelingen (football actions). Goals can link
 * to one action via `tt_goals.linked_action_id`. Action category keys
 * mirror the methodology framework's categorisation:
 *
 *   - with_ball     — aannemen, passen, dribbelen, schieten, koppen
 *   - without_ball  — vrijlopen, knijpen, jagen, dekken
 *   - support       — spelinzicht, communicatie
 */
final class FootballActionsRepository {

    public const CAT_WITH_BALL    = 'with_ball';
    public const CAT_WITHOUT_BALL = 'without_ball';
    public const CAT_SUPPORT      = 'support';

    /** @return array<string,string> slug => translated label */
    public static function categories(): array {
        return [
            self::CAT_WITH_BALL    => __( 'Met balcontact',    'talenttrack' ),
            self::CAT_WITHOUT_BALL => __( 'Zonder balcontact', 'talenttrack' ),
            self::CAT_SUPPORT      => __( 'Ondersteunend',     'talenttrack' ),
        ];
    }

    private function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'tt_football_actions';
    }

    /** @return object[] */
    public function listAll( bool $include_archived = false ): array {
        global $wpdb;
        $t = $this->table();
        $where = $include_archived ? '' : ' WHERE archived_at IS NULL';
        return (array) $wpdb->get_results( "SELECT * FROM {$t}{$where} ORDER BY category_key ASC, sort_order ASC, slug ASC" );
    }

    public function find( int $id ): ?object {
        global $wpdb;
        $t = $this->table();
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ) );
        return $row ?: null;
    }

    public function findBySlug( string $slug ): ?object {
        global $wpdb;
        $t = $this->table();
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE slug = %s LIMIT 1", $slug ) );
        return $row ?: null;
    }

    /** @param array<string,mixed> $data */
    public function create( array $data ): int {
        global $wpdb;
        $row = $this->normalize( $data, true );
        $wpdb->insert( $this->table(), $row );
        return (int) $wpdb->insert_id;
    }

    /** @param array<string,mixed> $data */
    public function update( int $id, array $data ): bool {
        global $wpdb;
        $row = $this->normalize( $data, false );
        if ( empty( $row ) ) return true;
        return $wpdb->update( $this->table(), $row, [ 'id' => $id ] ) !== false;
    }

    public function archive( int $id ): bool {
        global $wpdb;
        return $wpdb->update( $this->table(), [ 'archived_at' => current_time( 'mysql', true ) ], [ 'id' => $id ] ) !== false;
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function normalize( array $data, bool $for_insert ): array {
        $out = [];
        if ( $for_insert ) {
            $out['slug']         = isset( $data['slug'] ) ? sanitize_key( (string) $data['slug'] ) : '';
            $out['category_key'] = isset( $data['category_key'] ) ? sanitize_key( (string) $data['category_key'] ) : '';
            $out['sort_order']   = isset( $data['sort_order'] ) ? (int) $data['sort_order'] : 0;
            $out['is_shipped']   = ! empty( $data['is_shipped'] ) ? 1 : 0;
        } else {
            if ( array_key_exists( 'category_key', $data ) ) $out['category_key'] = sanitize_key( (string) $data['category_key'] );
            if ( array_key_exists( 'sort_order',   $data ) ) $out['sort_order']   = (int) $data['sort_order'];
        }
        foreach ( [ 'name_json', 'description_json' ] as $col ) {
            if ( array_key_exists( $col, $data ) ) {
                $out[ $col ] = is_array( $data[ $col ] ) ? (string) wp_json_encode( $data[ $col ] ) : (string) $data[ $col ];
            }
        }
        return $out;
    }
}
