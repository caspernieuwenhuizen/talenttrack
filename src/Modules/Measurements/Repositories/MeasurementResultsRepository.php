<?php
namespace TT\Modules\Measurements\Repositories;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * MeasurementResultsRepository (#1856).
 *
 * One row = one recorded value of one test for one player on one date.
 * This is the player-centric spine of the module (CLAUDE.md §1): every
 * result attaches to a `player_id`.
 *
 * Stateless; club-scoped; excludes archived rows.
 */
class MeasurementResultsRepository {

    /**
     * Chronological value series for one player + definition — the trend.
     * Ascending by date so a sparkline reads left-to-right.
     *
     * @return array<int, object>
     */
    public function listSeriesForPlayer( int $player_id, int $definition_id ): array {
        if ( $player_id <= 0 || $definition_id <= 0 ) return [];
        global $wpdb;
        $p = $wpdb->prefix;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, recorded_date, value_numeric, value_text
               FROM {$p}tt_measurement_results
              WHERE player_id = %d AND definition_id = %d
                AND club_id = %d AND archived_at IS NULL
              ORDER BY recorded_date ASC, id ASC",
            $player_id, $definition_id, CurrentClub::id()
        ) );
        return is_array( $rows ) ? $rows : [];
    }

    /**
     * Latest result per definition for one player — powers the profile
     * list (one current value + flag per test).
     *
     * @return array<int, object> keyed by definition_id
     */
    public function latestPerDefinitionForPlayer( int $player_id ): array {
        if ( $player_id <= 0 ) return [];
        global $wpdb;
        $p = $wpdb->prefix;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT r.*
               FROM {$p}tt_measurement_results r
               JOIN (
                     SELECT definition_id, MAX(recorded_date) AS max_date
                       FROM {$p}tt_measurement_results
                      WHERE player_id = %d AND club_id = %d AND archived_at IS NULL
                      GROUP BY definition_id
                    ) latest
                 ON r.definition_id = latest.definition_id
                AND r.recorded_date = latest.max_date
              WHERE r.player_id = %d AND r.club_id = %d AND r.archived_at IS NULL
              ORDER BY r.id DESC",
            $player_id, CurrentClub::id(), $player_id, CurrentClub::id()
        ) );
        if ( ! is_array( $rows ) ) return [];

        $out = [];
        foreach ( $rows as $row ) {
            $def = (int) $row->definition_id;
            if ( ! isset( $out[ $def ] ) ) $out[ $def ] = $row;
        }
        return $out;
    }

    /**
     * All results recorded against a session (one row per player entered).
     *
     * @return array<int, object>
     */
    public function listForSession( int $measurement_session_id ): array {
        if ( $measurement_session_id <= 0 ) return [];
        global $wpdb;
        $p = $wpdb->prefix;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$p}tt_measurement_results
              WHERE measurement_session_id = %d AND club_id = %d AND archived_at IS NULL",
            $measurement_session_id, CurrentClub::id()
        ) );
        return is_array( $rows ) ? $rows : [];
    }

    public function find( int $id ): ?object {
        if ( $id <= 0 ) return null;
        global $wpdb;
        $p = $wpdb->prefix;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}tt_measurement_results
              WHERE id = %d AND club_id = %d AND archived_at IS NULL",
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

        $wpdb->insert( "{$p}tt_measurement_results", [
            'club_id'       => CurrentClub::id(),
            'uuid'          => wp_generate_uuid4(),
            'player_id'     => (int) ( $data['player_id'] ?? 0 ),
            'definition_id' => (int) ( $data['definition_id'] ?? 0 ),
            'measurement_session_id' => ! empty( $data['measurement_session_id'] ) ? (int) $data['measurement_session_id'] : null,
            'recorded_date' => (string) ( $data['recorded_date'] ?? current_time( 'Y-m-d' ) ),
            'value_numeric' => isset( $data['value_numeric'] ) && $data['value_numeric'] !== '' ? (float) $data['value_numeric'] : null,
            'value_text'    => isset( $data['value_text'] ) && $data['value_text'] !== '' ? (string) $data['value_text'] : null,
            'recorded_by'   => get_current_user_id() ?: null,
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
        if ( array_key_exists( 'recorded_date', $data ) ) $fields['recorded_date'] = (string) $data['recorded_date'];
        if ( array_key_exists( 'value_numeric', $data ) ) $fields['value_numeric'] = $data['value_numeric'] !== '' && $data['value_numeric'] !== null ? (float) $data['value_numeric'] : null;
        if ( array_key_exists( 'value_text', $data ) )    $fields['value_text']    = $data['value_text'] !== '' ? (string) $data['value_text'] : null;

        return false !== $wpdb->update(
            "{$p}tt_measurement_results",
            $fields,
            [ 'id' => $id, 'club_id' => CurrentClub::id() ]
        );
    }
}
