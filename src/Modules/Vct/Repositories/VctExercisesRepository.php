<?php
namespace TT\Modules\Vct\Repositories;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * VctExercisesRepository — the deterministic exercise catalogue.
 *
 * Hot path: `findCandidates()`. The composite index
 * `(club_id, archived_at, category, intensity_band, age_min, age_max)`
 * on `tt_vct_exercises` (migration 0122) covers the query so the engine
 * stays under O(log n) even at 1000+ exercises per club.
 *
 * Every query filters `archived_at IS NULL` explicitly per spec —
 * archiving an exercise removes it from candidate selection without
 * losing history.
 */
class VctExercisesRepository {

    private \wpdb $wpdb;
    private string $table;

    public function __construct() {
        global $wpdb;
        $this->wpdb  = $wpdb;
        $this->table = $wpdb->prefix . 'tt_vct_exercises';
    }

    /**
     * Candidate exercises for a given slot context. Filters:
     *   - club scope
     *   - archived_at IS NULL (explicit, per spec acceptance criterion)
     *   - category matches the slot
     *   - intensity_band within the slot's [min, max] range
     *   - age window covers the requested age (age_min <= age <= age_max)
     *   - md_context bit-flag column is set for the requested context
     *   - tactical_theme matches when given (NULL when slot is theme-agnostic)
     *
     * Returns the full row for each candidate so the selection pass can
     * score by Verheijen-classification + variety without a second query.
     *
     * @return list<array<string,mixed>>
     */
    public function findCandidates(
        string $category,
        int $intensity_band_min,
        int $intensity_band_max,
        int $age,
        string $md_context,
        ?string $tactical_theme
    ): array {
        $md_column = $this->mdContextColumn( $md_context );
        if ( $md_column === null ) return [];

        $sql = "SELECT * FROM {$this->table}
                 WHERE club_id = %d
                   AND archived_at IS NULL
                   AND category = %s
                   AND intensity_band BETWEEN %d AND %d
                   AND age_min <= %d
                   AND age_max >= %d
                   AND {$md_column} = 1";
        $params = [
            CurrentClub::id(),
            $category,
            $intensity_band_min,
            $intensity_band_max,
            $age,
            $age,
        ];
        if ( $tactical_theme !== null && $tactical_theme !== '' && $tactical_theme !== 'mixed' ) {
            $sql .= " AND (tactical_theme = %s OR tactical_theme IS NULL)";
            $params[] = $tactical_theme;
        }
        $sql .= " ORDER BY verheijen_classification ASC, id ASC";

        $rows = $this->wpdb->get_results( $this->wpdb->prepare( $sql, $params ) );
        if ( ! is_array( $rows ) ) return [];
        return array_map( [ $this, 'hydrate' ], $rows );
    }

    /**
     * List all exercises (optionally filtered by category / archived
     * state). Used by the library editor (#950) for the catalogue
     * browse view.
     *
     * @return list<array<string,mixed>>
     */
    public function listAll( ?string $category = null, bool $include_archived = false ): array {
        $sql = "SELECT * FROM {$this->table} WHERE club_id = %d";
        $params = [ CurrentClub::id() ];
        if ( ! $include_archived ) $sql .= " AND archived_at IS NULL";
        if ( $category !== null && $category !== '' ) {
            $sql .= " AND category = %s";
            $params[] = $category;
        }
        $sql .= " ORDER BY category ASC, intensity_band ASC, name_canonical ASC";
        $rows = $this->wpdb->get_results( $this->wpdb->prepare( $sql, $params ) );
        if ( ! is_array( $rows ) ) return [];
        return array_map( [ $this, 'hydrate' ], $rows );
    }

    /**
     * Create a new exercise. Returns the new id or 0 on failure.
     *
     * @param array<string,mixed> $data
     */
    public function create( array $data ): int {
        $row = $this->normalisePayload( $data );
        $row['club_id']       = CurrentClub::id();
        $row['uuid']          = wp_generate_uuid4();
        $row['seed_revision'] = 0;
        $ok = $this->wpdb->insert( $this->table, $row );
        return $ok === false ? 0 : (int) $this->wpdb->insert_id;
    }

    /**
     * Partial update of an exercise. Returns true on success.
     *
     * @param array<string,mixed> $patch
     */
    public function update( int $id, array $patch ): bool {
        if ( $id <= 0 ) return false;
        $clean = $this->normalisePayload( $patch, true );
        if ( ! $clean ) return true;
        $ok = $this->wpdb->update(
            $this->table,
            $clean,
            [ 'id' => $id, 'club_id' => CurrentClub::id() ]
        );
        return $ok !== false;
    }

    /**
     * Soft-delete an exercise. The engine's findCandidates() already
     * filters `archived_at IS NULL`, so archiving removes the exercise
     * from selection without losing history. Returns true on success.
     */
    public function archive( int $id ): bool {
        if ( $id <= 0 ) return false;
        $ok = $this->wpdb->update(
            $this->table,
            [ 'archived_at' => current_time( 'mysql', true ) ],
            [ 'id' => $id, 'club_id' => CurrentClub::id() ]
        );
        return $ok !== false;
    }

    /**
     * Normalise + filter the create/update payload. When
     * $partial = true, missing keys are skipped (PATCH semantics);
     * when false, missing required keys default to safe values
     * (POST semantics).
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function normalisePayload( array $data, bool $partial = false ): array {
        $out = [];
        $map_str  = [ 'code', 'name_canonical', 'category', 'sided_size', 'verheijen_classification' ];
        $map_int  = [ 'intensity_band', 'duration_minutes_min', 'duration_minutes_max', 'players_min', 'players_max', 'age_min', 'age_max' ];
        $map_md   = [ 'md_minus_4', 'md_minus_3', 'md_minus_2', 'md_minus_1', 'md_zero', 'md_plus_1', 'md_plus_2', 'md_none' ];
        $nullable = [ 'tactical_theme', 'sided_size', 'verheijen_classification', 'diagram_url' ];

        foreach ( $map_str as $k ) {
            if ( array_key_exists( $k, $data ) ) {
                $v = $data[ $k ];
                $out[ $k ] = $v === null ? null : (string) $v;
                if ( in_array( $k, $nullable, true ) && $out[ $k ] === '' ) $out[ $k ] = null;
            } elseif ( ! $partial ) {
                if ( in_array( $k, $nullable, true ) ) {
                    $out[ $k ] = null;
                } elseif ( $k === 'code' || $k === 'name_canonical' || $k === 'category' ) {
                    // required for POST — caller must supply.
                }
            }
        }
        if ( array_key_exists( 'tactical_theme', $data ) ) {
            $tv = $data['tactical_theme'];
            $out['tactical_theme'] = ( $tv === null || $tv === '' ) ? null : (string) $tv;
        }
        if ( array_key_exists( 'diagram_url', $data ) ) {
            $dv = $data['diagram_url'];
            $out['diagram_url'] = ( $dv === null || $dv === '' ) ? null : (string) $dv;
        }
        if ( array_key_exists( 'equipment_json', $data ) ) {
            $ev = $data['equipment_json'];
            $out['equipment_json'] = is_array( $ev ) ? wp_json_encode( $ev ) : (string) $ev;
        }
        foreach ( $map_int as $k ) {
            if ( array_key_exists( $k, $data ) ) $out[ $k ] = (int) $data[ $k ];
        }
        foreach ( $map_md as $k ) {
            if ( array_key_exists( $k, $data ) ) $out[ $k ] = ! empty( $data[ $k ] ) ? 1 : 0;
        }
        return $out;
    }

    /** @return array<string,mixed>|null */
    public function find( int $id ): ?array {
        if ( $id <= 0 ) return null;
        $row = $this->wpdb->get_row( $this->wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE club_id = %d AND id = %d LIMIT 1",
            CurrentClub::id(), $id
        ) );
        if ( ! $row ) return null;
        return $this->hydrate( $row );
    }

    /**
     * MD-context → bit-flag column name. Returns null for unknown
     * contexts so the caller can short-circuit.
     */
    private function mdContextColumn( string $md_context ): ?string {
        switch ( $md_context ) {
            case 'MD-4':  return 'md_minus_4';
            case 'MD-3':  return 'md_minus_3';
            case 'MD-2':  return 'md_minus_2';
            case 'MD-1':  return 'md_minus_1';
            case 'MD':    return 'md_zero';
            case 'MD+1':  return 'md_plus_1';
            case 'MD+2':  return 'md_plus_2';
            case 'NONE':  return 'md_none';
        }
        return null;
    }

    /** @param object $row */
    private function hydrate( $row ): array {
        return [
            'id'                       => (int)    $row->id,
            'uuid'                     => (string) $row->uuid,
            'code'                     => (string) $row->code,
            'name_canonical'           => (string) $row->name_canonical,
            'category'                 => (string) $row->category,
            'tactical_theme'           => $row->tactical_theme !== null ? (string) $row->tactical_theme : null,
            'intensity_band'           => (int)    $row->intensity_band,
            'duration_minutes_min'     => (int)    $row->duration_minutes_min,
            'duration_minutes_max'     => (int)    $row->duration_minutes_max,
            'players_min'              => (int)    $row->players_min,
            'players_max'              => (int)    $row->players_max,
            'sided_size'               => $row->sided_size !== null ? (string) $row->sided_size : null,
            'age_min'                  => (int)    $row->age_min,
            'age_max'                  => (int)    $row->age_max,
            'equipment_json'           => $row->equipment_json !== null ? (string) $row->equipment_json : null,
            'diagram_url'              => $row->diagram_url !== null ? (string) $row->diagram_url : null,
            'verheijen_classification' => $row->verheijen_classification !== null ? (string) $row->verheijen_classification : null,
        ];
    }
}
