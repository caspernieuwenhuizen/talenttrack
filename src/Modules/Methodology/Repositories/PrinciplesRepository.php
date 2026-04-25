<?php
namespace TT\Modules\Methodology\Repositories;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * PrinciplesRepository — data access for `tt_principles`.
 *
 * Reads support filtering by team-function / team-task / source
 * (shipped / club / both) / formation, plus a search across the
 * `code` column and the JSON title fields.
 *
 * Writes are CRUD: create / update / archive / cloneShipped.
 * `cloneShipped( $id )` produces a new club-authored row that
 * carries `cloned_from_id` so the UI can render a "modified from
 * AO-01" badge on the clone.
 */
class PrinciplesRepository {

    private function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'tt_principles';
    }

    /**
     * @param array{
     *   team_function?: string,
     *   team_task?: string,
     *   source?: string,             // 'shipped' | 'club' | 'both' (default)
     *   formation_id?: int,
     *   search?: string,
     *   include_archived?: bool
     * } $filters
     * @return object[]
     */
    public function listFiltered( array $filters = [] ): array {
        global $wpdb;
        $t = $this->table();

        $where = [];
        $args  = [];

        if ( empty( $filters['include_archived'] ) ) {
            $where[] = 'archived_at IS NULL';
        }
        if ( ! empty( $filters['team_function'] ) ) {
            $where[] = 'team_function_key = %s';
            $args[]  = (string) $filters['team_function'];
        }
        if ( ! empty( $filters['team_task'] ) ) {
            $where[] = 'team_task_key = %s';
            $args[]  = (string) $filters['team_task'];
        }
        $source = isset( $filters['source'] ) ? (string) $filters['source'] : 'both';
        if ( $source === 'shipped' ) {
            $where[] = 'is_shipped = 1';
        } elseif ( $source === 'club' ) {
            $where[] = 'is_shipped = 0';
        }
        if ( ! empty( $filters['formation_id'] ) ) {
            $where[] = 'default_formation_id = %d';
            $args[]  = (int) $filters['formation_id'];
        }
        if ( isset( $filters['search'] ) && trim( (string) $filters['search'] ) !== '' ) {
            $where[] = '(code LIKE %s OR title_json LIKE %s)';
            $like = '%' . $wpdb->esc_like( (string) $filters['search'] ) . '%';
            $args[]  = $like;
            $args[]  = $like;
        }

        $where_sql = empty( $where ) ? '' : ' WHERE ' . implode( ' AND ', $where );
        $sql = "SELECT * FROM {$t}{$where_sql} ORDER BY team_function_key, team_task_key, code ASC";

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

    public function findByCode( string $code ): ?object {
        global $wpdb;
        $t = $this->table();
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE code = %s LIMIT 1", $code ) );
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

    /**
     * Clone a shipped row into a club-authored copy. Keeps every JSON
     * field; flips `is_shipped` to 0 and stamps `cloned_from_id`.
     *
     * Returns the new row's id, or 0 on failure.
     */
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
            $out['code']                 = isset( $data['code'] ) ? sanitize_text_field( (string) $data['code'] ) : '';
            $out['team_function_key']    = isset( $data['team_function_key'] ) ? sanitize_key( (string) $data['team_function_key'] ) : '';
            $out['team_task_key']        = isset( $data['team_task_key'] )     ? sanitize_key( (string) $data['team_task_key'] )     : '';
            $out['is_shipped']           = ! empty( $data['is_shipped'] ) ? 1 : 0;
        } else {
            if ( array_key_exists( 'code',              $data ) ) $out['code']              = sanitize_text_field( (string) $data['code'] );
            if ( array_key_exists( 'team_function_key', $data ) ) $out['team_function_key'] = sanitize_key( (string) $data['team_function_key'] );
            if ( array_key_exists( 'team_task_key',     $data ) ) $out['team_task_key']     = sanitize_key( (string) $data['team_task_key'] );
        }
        foreach ( [ 'title_json', 'explanation_json', 'team_guidance_json', 'line_guidance_json', 'diagram_overlay_json' ] as $col ) {
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
