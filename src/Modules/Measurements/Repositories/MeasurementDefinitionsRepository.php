<?php
namespace TT\Modules\Measurements\Repositories;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\LookupTranslator;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * MeasurementDefinitionsRepository (#1856).
 *
 * A measurement *definition* is a test an academy runs (e.g. "Sprint 30m",
 * "Height"). Definitions are the schema the rest of the module hangs off:
 * sessions are scheduled against one, results record one value for one.
 *
 * Stateless; mirrors EvaluationsRepository — global $wpdb, club-scoped via
 * CurrentClub::id(), excludes soft-deleted rows (archived_at IS NULL).
 */
class MeasurementDefinitionsRepository {

    /**
     * All active definitions, with the localised category label attached.
     *
     * @return array<int, object>
     */
    public function listActive(): array {
        global $wpdb;
        $p = $wpdb->prefix;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT d.*, lt.name AS category_name, lt.lookup_type AS category_lookup_type
               FROM {$p}tt_measurement_definitions d
               LEFT JOIN {$p}tt_lookups lt ON d.category_id = lt.id
              WHERE d.club_id = %d AND d.archived_at IS NULL AND d.is_active = 1
              ORDER BY lt.sort_order ASC, d.sort_order ASC, d.name ASC",
            CurrentClub::id()
        ) );
        if ( ! is_array( $rows ) ) return [];

        foreach ( $rows as $row ) {
            $row->category_label = $this->localiseCategory( $row );
        }
        return $rows;
    }

    /**
     * Active definitions the operator has chosen to surface on the player
     * profile (`show_on_profile = 1`), #2204. Same shape + ordering as
     * listActive() — the profile read model uses this so a test toggled off
     * for the profile stops rendering there while staying in reports/exports.
     *
     * @return array<int, object>
     */
    public function listActiveForProfile(): array {
        global $wpdb;
        $p = $wpdb->prefix;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT d.*, lt.name AS category_name, lt.lookup_type AS category_lookup_type
               FROM {$p}tt_measurement_definitions d
               LEFT JOIN {$p}tt_lookups lt ON d.category_id = lt.id
              WHERE d.club_id = %d AND d.archived_at IS NULL AND d.is_active = 1
                AND d.show_on_profile = 1
              ORDER BY lt.sort_order ASC, d.sort_order ASC, d.name ASC",
            CurrentClub::id()
        ) );
        if ( ! is_array( $rows ) ) return [];

        foreach ( $rows as $row ) {
            $row->category_label = $this->localiseCategory( $row );
        }
        return $rows;
    }

    /**
     * All definitions, optionally including the inactive (is_active = 0) ones.
     * Archived (soft-deleted) rows are always excluded. Club-scoped.
     *
     * @return array<int, object>
     */
    public function listAll( bool $include_inactive = false ): array {
        global $wpdb;
        $p = $wpdb->prefix;

        $active_clause = $include_inactive ? '' : ' AND d.is_active = 1';
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT d.*, lt.name AS category_name, lt.lookup_type AS category_lookup_type
               FROM {$p}tt_measurement_definitions d
               LEFT JOIN {$p}tt_lookups lt ON d.category_id = lt.id
              WHERE d.club_id = %d AND d.archived_at IS NULL{$active_clause}
              ORDER BY lt.sort_order ASC, d.sort_order ASC, d.name ASC",
            CurrentClub::id()
        ) );
        if ( ! is_array( $rows ) ) return [];

        foreach ( $rows as $row ) {
            $row->category_label = $this->localiseCategory( $row );
        }
        return $rows;
    }

    /**
     * One definition by id (club-scoped, not archived).
     */
    public function find( int $id ): ?object {
        if ( $id <= 0 ) return null;
        global $wpdb;
        $p = $wpdb->prefix;

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT d.*, lt.name AS category_name, lt.lookup_type AS category_lookup_type
               FROM {$p}tt_measurement_definitions d
               LEFT JOIN {$p}tt_lookups lt ON d.category_id = lt.id
              WHERE d.id = %d AND d.club_id = %d AND d.archived_at IS NULL",
            $id, CurrentClub::id()
        ) );
        if ( ! $row ) return null;
        $row->category_label = $this->localiseCategory( $row );
        return $row;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create( array $data ): int {
        global $wpdb;
        $p = $wpdb->prefix;

        $wpdb->insert( "{$p}tt_measurement_definitions", [
            'club_id'     => CurrentClub::id(),
            'uuid'        => wp_generate_uuid4(),
            'category_id' => (int) ( $data['category_id'] ?? 0 ),
            'name'        => (string) ( $data['name'] ?? '' ),
            'value_type'  => $this->safeValueType( $data['value_type'] ?? 'numeric' ),
            'unit'        => isset( $data['unit'] ) && $data['unit'] !== '' ? (string) $data['unit'] : null,
            'scale_min'   => isset( $data['scale_min'] ) ? (float) $data['scale_min'] : null,
            'scale_max'   => isset( $data['scale_max'] ) ? (float) $data['scale_max'] : null,
            'frequency'   => (string) ( $data['frequency'] ?? 'adhoc' ),
            'direction'   => $this->safeDirection( $data['direction'] ?? 'higher' ),
            'is_active'   => isset( $data['is_active'] ) ? (int) (bool) $data['is_active'] : 1,
            'show_on_profile' => isset( $data['show_on_profile'] ) ? (int) (bool) $data['show_on_profile'] : 1,
            'sort_order'  => (int) ( $data['sort_order'] ?? 0 ),
            'created_by'  => get_current_user_id() ?: null,
            'created_at'  => current_time( 'mysql', true ),
        ] );
        return (int) $wpdb->insert_id;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update( int $id, array $data ): bool {
        if ( $id <= 0 ) return false;
        global $wpdb;
        $p = $wpdb->prefix;

        $fields = [ 'updated_at' => current_time( 'mysql', true ) ];
        if ( array_key_exists( 'category_id', $data ) ) $fields['category_id'] = (int) $data['category_id'];
        if ( array_key_exists( 'name', $data ) )        $fields['name']        = (string) $data['name'];
        if ( array_key_exists( 'value_type', $data ) )  $fields['value_type']  = $this->safeValueType( $data['value_type'] );
        if ( array_key_exists( 'unit', $data ) )        $fields['unit']        = $data['unit'] !== '' ? (string) $data['unit'] : null;
        if ( array_key_exists( 'scale_min', $data ) )   $fields['scale_min']   = $data['scale_min'] !== null ? (float) $data['scale_min'] : null;
        if ( array_key_exists( 'scale_max', $data ) )   $fields['scale_max']   = $data['scale_max'] !== null ? (float) $data['scale_max'] : null;
        if ( array_key_exists( 'frequency', $data ) )   $fields['frequency']   = (string) $data['frequency'];
        if ( array_key_exists( 'direction', $data ) )   $fields['direction']   = $this->safeDirection( $data['direction'] );
        if ( array_key_exists( 'is_active', $data ) )   $fields['is_active']   = (int) (bool) $data['is_active'];
        if ( array_key_exists( 'show_on_profile', $data ) ) $fields['show_on_profile'] = (int) (bool) $data['show_on_profile'];
        if ( array_key_exists( 'sort_order', $data ) )  $fields['sort_order']  = (int) $data['sort_order'];

        return false !== $wpdb->update(
            "{$p}tt_measurement_definitions",
            $fields,
            [ 'id' => $id, 'club_id' => CurrentClub::id() ]
        );
    }

    private function localiseCategory( object $row ): string {
        if ( empty( $row->category_id ) ) return '';
        $stub = (object) [
            'id'          => (int) $row->category_id,
            'name'        => (string) ( $row->category_name ?? '' ),
            'lookup_type' => (string) ( $row->category_lookup_type ?? 'measurement_category' ),
        ];
        return LookupTranslator::name( $stub );
    }

    /**
     * Attach the operator-defined levels (status tests only) to a definition
     * row as `->levels`. A no-op for the other value types, so callers can
     * hydrate unconditionally. Lives here — not in a view — so the REST
     * controller and the rendered HTML get the same hydrated shape (§4).
     */
    public function withLevels( ?object $def ): ?object {
        if ( ! $def ) return null;
        $def->levels = (string) ( $def->value_type ?? '' ) === 'status'
            ? ( new MeasurementLevelsRepository() )->listForDefinition( (int) $def->id )
            : [];
        return $def;
    }

    private function safeValueType( string $value ): string {
        return in_array( $value, [ 'numeric', 'scale', 'passfail', 'status' ], true ) ? $value : 'numeric';
    }

    private function safeDirection( string $value ): string {
        return in_array( $value, [ 'higher', 'lower', 'neutral' ], true ) ? $value : 'higher';
    }
}
