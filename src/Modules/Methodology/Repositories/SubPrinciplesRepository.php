<?php
namespace TT\Modules\Methodology\Repositories;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Methodology\MethodologyScope;

/**
 * SubPrinciplesRepository (#2369, epic #2316) — data access for
 * `tt_methodology_sub_principles`.
 *
 * A sub-principle is a per-line coaching point (aanvallers / middenvelders
 * / verdedigers / algemeen) attached to a game phase via
 * `(phase_side, phase_number)` under a methodology set — optionally to a
 * parent principle via `principle_id`.
 *
 * Reads are club-scoped and filtered by the active methodology set
 * (MethodologyScope::active()), with the `(methodology_id = X OR IS NULL)`
 * widening PrinciplesRepository uses so unassigned rows never vanish.
 * Writes are CRUD; create stamps the active set + a uuid. Shipped rows are
 * read-only reference content — update / delete refuse them. The
 * multilingual `*_json` columns decode to arrays on read.
 */
class SubPrinciplesRepository {

    private function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'tt_methodology_sub_principles';
    }

    /**
     * List sub-principles for a specific methodology set (no ambient
     * scope), used by the read view when it already knows the set.
     *
     * @return object[]
     */
    public function listForMethodology( int $methodology_id ): array {
        return $this->listFiltered( [ 'methodology_id' => $methodology_id ] );
    }

    /**
     * @param array{
     *   methodology_id?: int,
     *   phase_side?: string,
     *   phase_number?: int,
     *   line_key?: string,
     *   principle_id?: int,
     *   source?: string,             // 'shipped' | 'club' | 'both' (default)
     *   include_archived?: bool
     * } $filters
     * @return object[]
     */
    public function listFiltered( array $filters = [] ): array {
        global $wpdb;
        $t = $this->table();

        $where = [ 'club_id = %d' ];
        $args  = [ CurrentClub::id() ];

        if ( empty( $filters['include_archived'] ) ) {
            $where[] = 'archived_at IS NULL';
        }
        $mid = array_key_exists( 'methodology_id', $filters )
            ? (int) $filters['methodology_id']
            : MethodologyScope::active();
        if ( $mid > 0 ) {
            $where[] = '(methodology_id = %d OR methodology_id IS NULL)';
            $args[]  = $mid;
        }
        $source = isset( $filters['source'] ) ? (string) $filters['source'] : 'both';
        if ( $source === 'shipped' ) {
            $where[] = 'is_shipped = 1';
        } elseif ( $source === 'club' ) {
            $where[] = 'is_shipped = 0';
        }
        if ( ! empty( $filters['phase_side'] ) ) {
            $where[] = 'phase_side = %s';
            $args[]  = (string) $filters['phase_side'];
        }
        if ( isset( $filters['phase_number'] ) && (int) $filters['phase_number'] > 0 ) {
            $where[] = 'phase_number = %d';
            $args[]  = (int) $filters['phase_number'];
        }
        if ( ! empty( $filters['line_key'] ) ) {
            $where[] = 'line_key = %s';
            $args[]  = (string) $filters['line_key'];
        }
        if ( isset( $filters['principle_id'] ) && (int) $filters['principle_id'] > 0 ) {
            $where[] = 'principle_id = %d';
            $args[]  = (int) $filters['principle_id'];
        }

        $where_sql = ' WHERE ' . implode( ' AND ', $where );
        $sql = "SELECT * FROM {$t}{$where_sql} ORDER BY phase_side ASC, phase_number ASC, line_key ASC, sort_order ASC, id ASC";

        $rows = (array) $wpdb->get_results( $wpdb->prepare( $sql, ...$args ) );
        return array_map( [ $this, 'decodeRow' ], $rows );
    }

    public function find( int $id ): ?object {
        global $wpdb;
        $t = $this->table();
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$t} WHERE id = %d AND club_id = %d",
            $id, CurrentClub::id()
        ) );
        return $row ? $this->decodeRow( $row ) : null;
    }

    /** @param array<string,mixed> $data */
    public function create( array $data ): int {
        global $wpdb;
        $row = $this->normalize( $data, true );
        $row['club_id'] = CurrentClub::id();
        $row['uuid']    = wp_generate_uuid4();
        $mid = MethodologyScope::active();
        if ( $mid > 0 && ! isset( $row['methodology_id'] ) ) {
            $row['methodology_id'] = $mid;
        }
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
     * Hard-delete a club-authored sub-principle. Shipped rows are
     * read-only reference content and are refused. Returns false when the
     * row is shipped or the delete affected no rows (wrong id / other club).
     */
    public function delete( int $id ): bool {
        $row = $this->find( $id );
        if ( ! $row || ! empty( $row->is_shipped ) ) return false;
        global $wpdb;
        return $wpdb->delete(
            $this->table(),
            [ 'id' => $id, 'club_id' => CurrentClub::id() ]
        ) !== false;
    }

    /**
     * Decode the multilingual JSON columns on a raw row into arrays so
     * callers work with structured data.
     */
    private function decodeRow( object $row ): object {
        $row->title_decoded       = $this->decodeJson( $row->title_json ?? null );
        $row->description_decoded = $this->decodeJson( $row->description_json ?? null );
        return $row;
    }

    /** @return array<string,mixed> */
    private function decodeJson( ?string $json ): array {
        if ( $json === null || $json === '' ) return [];
        $decoded = json_decode( $json, true );
        return is_array( $decoded ) ? $decoded : [];
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function normalize( array $data, bool $for_insert ): array {
        $out = [];
        if ( $for_insert ) {
            $out['phase_side']   = isset( $data['phase_side'] ) ? sanitize_key( (string) $data['phase_side'] ) : null;
            $out['phase_number'] = isset( $data['phase_number'] ) ? (int) $data['phase_number'] : null;
            $out['line_key']     = isset( $data['line_key'] ) ? sanitize_key( (string) $data['line_key'] ) : null;
            $out['is_shipped']   = ! empty( $data['is_shipped'] ) ? 1 : 0;
            $out['sort_order']   = isset( $data['sort_order'] ) ? (int) $data['sort_order'] : 0;
        } else {
            if ( array_key_exists( 'phase_side',   $data ) ) $out['phase_side']   = $data['phase_side'] === null ? null : sanitize_key( (string) $data['phase_side'] );
            if ( array_key_exists( 'phase_number', $data ) ) $out['phase_number'] = $data['phase_number'] === null ? null : (int) $data['phase_number'];
            if ( array_key_exists( 'line_key',     $data ) ) $out['line_key']     = $data['line_key'] === null ? null : sanitize_key( (string) $data['line_key'] );
            if ( array_key_exists( 'sort_order',   $data ) ) $out['sort_order']   = (int) $data['sort_order'];
        }
        foreach ( [ 'title_json', 'description_json' ] as $col ) {
            if ( array_key_exists( $col, $data ) ) {
                $out[ $col ] = is_array( $data[ $col ] ) ? (string) wp_json_encode( $data[ $col ] ) : (string) $data[ $col ];
            }
        }
        if ( array_key_exists( 'methodology_id', $data ) ) {
            $out['methodology_id'] = $data['methodology_id'] === null ? null : (int) $data['methodology_id'];
        }
        if ( array_key_exists( 'principle_id', $data ) ) {
            $out['principle_id'] = $data['principle_id'] === null ? null : (int) $data['principle_id'];
        }
        return $out;
    }
}
