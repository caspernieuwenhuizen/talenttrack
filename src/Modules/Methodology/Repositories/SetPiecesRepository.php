<?php
namespace TT\Modules\Methodology\Repositories;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * SetPiecesRepository — `tt_set_pieces` data access. Same shape as
 * PrinciplesRepository but with the kind/side filter dimensions
 * specific to set pieces.
 */
class SetPiecesRepository {

    private function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'tt_set_pieces';
    }

    /**
     * @param array{
     *   kind?: string,
     *   side?: string,
     *   source?: string,
     *   include_archived?: bool
     * } $filters
     * @return object[]
     */
    public function listFiltered( array $filters = [] ): array {
        global $wpdb;
        $t = $this->table();

        $where = [];
        $args  = [];
        if ( empty( $filters['include_archived'] ) ) $where[] = 'archived_at IS NULL';
        if ( ! empty( $filters['kind'] ) ) {
            $where[] = 'kind_key = %s';
            $args[]  = (string) $filters['kind'];
        }
        if ( ! empty( $filters['side'] ) ) {
            $where[] = 'side = %s';
            $args[]  = (string) $filters['side'];
        }
        $source = isset( $filters['source'] ) ? (string) $filters['source'] : 'both';
        if ( $source === 'shipped' )      $where[] = 'is_shipped = 1';
        elseif ( $source === 'club' )     $where[] = 'is_shipped = 0';

        $where_sql = empty( $where ) ? '' : ' WHERE ' . implode( ' AND ', $where );
        $sql = "SELECT * FROM {$t}{$where_sql} ORDER BY side ASC, kind_key ASC, slug ASC";

        return empty( $args )
            ? (array) $wpdb->get_results( $sql )
            : (array) $wpdb->get_results( $wpdb->prepare( $sql, ...$args ) );
    }

    public function find( int $id ): ?object {
        global $wpdb;
        $t = $this->table();
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ) );
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
        return $wpdb->update(
            $this->table(),
            [ 'archived_at' => current_time( 'mysql', true ) ],
            [ 'id' => $id ]
        ) !== false;
    }

    public function cloneShipped( int $id ): int {
        $source = $this->find( $id );
        if ( ! $source ) return 0;
        global $wpdb;
        $insert = (array) $source;
        unset( $insert['id'], $insert['created_at'], $insert['updated_at'] );
        $insert['is_shipped']     = 0;
        $insert['cloned_from_id'] = (int) $id;
        $wpdb->insert( $this->table(), $insert );
        return (int) $wpdb->insert_id;
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function normalize( array $data, bool $for_insert ): array {
        $out = [];
        if ( $for_insert ) {
            $out['slug']       = isset( $data['slug'] )       ? sanitize_text_field( (string) $data['slug'] ) : '';
            $out['kind_key']   = isset( $data['kind_key'] )   ? sanitize_key( (string) $data['kind_key'] )   : '';
            $out['side']       = isset( $data['side'] )       ? sanitize_key( (string) $data['side'] )       : '';
            $out['is_shipped'] = ! empty( $data['is_shipped'] ) ? 1 : 0;
        } else {
            if ( array_key_exists( 'kind_key', $data ) ) $out['kind_key'] = sanitize_key( (string) $data['kind_key'] );
            if ( array_key_exists( 'side',     $data ) ) $out['side']     = sanitize_key( (string) $data['side'] );
        }
        foreach ( [ 'title_json', 'bullets_json', 'diagram_overlay_json' ] as $col ) {
            if ( array_key_exists( $col, $data ) ) {
                $out[ $col ] = is_array( $data[ $col ] ) ? (string) wp_json_encode( $data[ $col ] ) : (string) $data[ $col ];
            }
        }
        if ( array_key_exists( 'default_formation_id', $data ) ) {
            $out['default_formation_id'] = $data['default_formation_id'] === null ? null : (int) $data['default_formation_id'];
        }
        return $out;
    }
}
