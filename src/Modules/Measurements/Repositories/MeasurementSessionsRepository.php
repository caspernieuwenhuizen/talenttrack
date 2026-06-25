<?php
namespace TT\Modules\Measurements\Repositories;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * MeasurementSessionsRepository (#1856).
 *
 * A session is a planned testing moment: one definition, one team, one
 * date. Staff enter one result per player against it. Powers "who's due".
 *
 * Stateless; club-scoped; excludes archived rows.
 */
class MeasurementSessionsRepository {

    /**
     * Sessions for one team (newest planned first), with the definition
     * name attached for listing.
     *
     * @return array<int, object>
     */
    public function listForTeam( int $team_id ): array {
        if ( $team_id <= 0 ) return [];
        global $wpdb;
        $p = $wpdb->prefix;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT s.*, d.name AS definition_name, d.unit AS definition_unit, d.value_type
               FROM {$p}tt_measurement_sessions s
               LEFT JOIN {$p}tt_measurement_definitions d ON s.definition_id = d.id
              WHERE s.team_id = %d AND s.club_id = %d AND s.archived_at IS NULL
              ORDER BY s.planned_date DESC, s.id DESC",
            $team_id, CurrentClub::id()
        ) );
        return is_array( $rows ) ? $rows : [];
    }

    public function find( int $id ): ?object {
        if ( $id <= 0 ) return null;
        global $wpdb;
        $p = $wpdb->prefix;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT s.*, d.name AS definition_name, d.unit AS definition_unit, d.value_type
               FROM {$p}tt_measurement_sessions s
               LEFT JOIN {$p}tt_measurement_definitions d ON s.definition_id = d.id
              WHERE s.id = %d AND s.club_id = %d AND s.archived_at IS NULL",
            $id, CurrentClub::id()
        ) );
        return $row ?: null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create( array $data ): int {
        global $wpdb;
        $p = $wpdb->prefix;

        $wpdb->insert( "{$p}tt_measurement_sessions", [
            'club_id'       => CurrentClub::id(),
            'uuid'          => wp_generate_uuid4(),
            'definition_id' => (int) ( $data['definition_id'] ?? 0 ),
            'team_id'       => (int) ( $data['team_id'] ?? 0 ),
            'planned_date'  => (string) ( $data['planned_date'] ?? current_time( 'Y-m-d' ) ),
            'status'        => $this->safeStatus( $data['status'] ?? 'planned' ),
            'notes'         => isset( $data['notes'] ) && $data['notes'] !== '' ? (string) $data['notes'] : null,
            'created_by'    => get_current_user_id() ?: null,
            'created_at'    => current_time( 'mysql', true ),
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
        if ( array_key_exists( 'planned_date', $data ) ) $fields['planned_date'] = (string) $data['planned_date'];
        if ( array_key_exists( 'status', $data ) )       $fields['status']       = $this->safeStatus( $data['status'] );
        if ( array_key_exists( 'notes', $data ) )        $fields['notes']        = $data['notes'] !== '' ? (string) $data['notes'] : null;

        return false !== $wpdb->update(
            "{$p}tt_measurement_sessions",
            $fields,
            [ 'id' => $id, 'club_id' => CurrentClub::id() ]
        );
    }

    private function safeStatus( string $value ): string {
        return in_array( $value, [ 'planned', 'completed', 'cancelled' ], true ) ? $value : 'planned';
    }
}
