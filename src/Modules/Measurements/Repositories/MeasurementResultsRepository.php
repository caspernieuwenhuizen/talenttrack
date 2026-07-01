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

    /**
     * Every result recorded against one definition, joined to the player,
     * the player's team (name + age group) and the recording user's display
     * name — the read model the XLSX export shapes into rows (#2139).
     *
     * Optional filters: a single `team_id`, and an inclusive `date_from` /
     * `date_to` recorded-date window. Ordered by player then date so the
     * sheet groups a player's longitudinal series together (CLAUDE.md §1).
     *
     * Business logic stays here — the exporter only shapes the returned rows.
     *
     * @param array<string, mixed> $filters
     * @return array<int, object>
     */
    public function listForDefinitionExport( int $definition_id, array $filters = [] ): array {
        if ( $definition_id <= 0 ) return [];
        global $wpdb;
        $p = $wpdb->prefix;

        $where  = [ 'r.definition_id = %d', 'r.club_id = %d', 'r.archived_at IS NULL' ];
        $params = [ $definition_id, CurrentClub::id() ];

        $team_id = isset( $filters['team_id'] ) ? (int) $filters['team_id'] : 0;
        if ( $team_id > 0 ) {
            $where[]  = 'pl.team_id = %d';
            $params[] = $team_id;
        }
        if ( ! empty( $filters['date_from'] ) ) {
            $where[]  = 'r.recorded_date >= %s';
            $params[] = (string) $filters['date_from'];
        }
        if ( ! empty( $filters['date_to'] ) ) {
            $where[]  = 'r.recorded_date <= %s';
            $params[] = (string) $filters['date_to'];
        }

        $sql = "SELECT r.id, r.recorded_date, r.value_numeric, r.value_text,
                       pl.id AS player_id, pl.first_name, pl.last_name,
                       t.name AS team_name, t.age_group AS age_group,
                       u.display_name AS recorded_by_name
                  FROM {$p}tt_measurement_results r
                  JOIN {$p}tt_players pl ON pl.id = r.player_id
             LEFT JOIN {$p}tt_teams t ON t.id = pl.team_id
             LEFT JOIN {$wpdb->users} u ON u.ID = r.recorded_by
                 WHERE " . implode( ' AND ', $where ) . "
              ORDER BY pl.last_name ASC, pl.first_name ASC, r.recorded_date ASC, r.id ASC";

        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
        return is_array( $rows ) ? $rows : [];
    }

    /**
     * The latest result per player for one definition, with each player's
     * immediately-previous value attached (for the trend arrow), joined to
     * the player, their team (name + age group) — the read model the Test
     * results browser (#2145) lists. One row per player who has at least one
     * value for the test, ordered by player name.
     *
     * Optional filters: a single `team_id`, an `age_group`, and an inclusive
     * `date_from` / `date_to` recorded-date window. The window applies to the
     * "latest" selection; the previous value is the most recent result strictly
     * before that latest date (no window — the trend reads against real history).
     *
     * Business logic stays here so the REST controller and the rendered HTML
     * (CLAUDE.md §4) get the same rows.
     *
     * @param array<string, mixed> $filters
     * @return array<int, object>
     */
    public function listLatestWithPreviousForDefinition( int $definition_id, array $filters = [] ): array {
        if ( $definition_id <= 0 ) return [];
        global $wpdb;
        $p = $wpdb->prefix;

        $where  = [ 'r.definition_id = %d', 'r.club_id = %d', 'r.archived_at IS NULL' ];
        $params = [ $definition_id, CurrentClub::id() ];

        $team_id = isset( $filters['team_id'] ) ? (int) $filters['team_id'] : 0;
        if ( $team_id > 0 ) {
            $where[]  = 'pl.team_id = %d';
            $params[] = $team_id;
        }
        $age_group = isset( $filters['age_group'] ) ? (string) $filters['age_group'] : '';
        if ( $age_group !== '' ) {
            $where[]  = 't.age_group = %s';
            $params[] = $age_group;
        }
        if ( ! empty( $filters['date_from'] ) ) {
            $where[]  = 'r.recorded_date >= %s';
            $params[] = (string) $filters['date_from'];
        }
        if ( ! empty( $filters['date_to'] ) ) {
            $where[]  = 'r.recorded_date <= %s';
            $params[] = (string) $filters['date_to'];
        }

        $where_sql = implode( ' AND ', $where );

        // The latest in-window result per player, then (correlated) the most
        // recent value strictly before that date for the same player+test — the
        // previous point the trend arrow compares against.
        $sql = "SELECT r.id, r.recorded_date, r.value_numeric, r.value_text,
                       pl.id AS player_id, pl.first_name, pl.last_name,
                       t.age_group AS age_group,
                       t.name AS team_name,
                       prev.value_numeric AS prev_value_numeric,
                       prev.value_text    AS prev_value_text,
                       prev.recorded_date AS prev_date
                  FROM {$p}tt_measurement_results r
                  JOIN {$p}tt_players pl ON pl.id = r.player_id
             LEFT JOIN {$p}tt_teams t ON t.id = pl.team_id
                  JOIN (
                        SELECT r2.player_id, MAX(r2.recorded_date) AS max_date
                          FROM {$p}tt_measurement_results r2
                          JOIN {$p}tt_players p2 ON p2.id = r2.player_id
                     LEFT JOIN {$p}tt_teams t2 ON t2.id = p2.team_id
                         WHERE " . str_replace( [ 'r.', 'pl.', 't.' ], [ 'r2.', 'p2.', 't2.' ], $where_sql ) . "
                         GROUP BY r2.player_id
                       ) latest
                    ON latest.player_id = r.player_id
                   AND latest.max_date  = r.recorded_date
             LEFT JOIN {$p}tt_measurement_results prev
                    ON prev.player_id     = r.player_id
                   AND prev.definition_id = r.definition_id
                   AND prev.club_id       = r.club_id
                   AND prev.archived_at IS NULL
                   AND prev.recorded_date < r.recorded_date
                   AND prev.recorded_date = (
                         SELECT MAX(p3.recorded_date)
                           FROM {$p}tt_measurement_results p3
                          WHERE p3.player_id     = r.player_id
                            AND p3.definition_id = r.definition_id
                            AND p3.club_id       = r.club_id
                            AND p3.archived_at IS NULL
                            AND p3.recorded_date < r.recorded_date
                       )
                 WHERE {$where_sql}
              GROUP BY r.player_id
              ORDER BY pl.last_name ASC, pl.first_name ASC";

        // The correlated subquery's predicates reuse the same bound params as
        // the outer WHERE for the inner-derived table, so params double up.
        $bound = array_merge( $params, $params );
        $rows  = $wpdb->get_results( $wpdb->prepare( $sql, $bound ) );
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

    /**
     * Soft-archive a result (the reversible delete). Permanent delete +
     * restore route through the #1782 archive framework (TABLE_MAP).
     */
    public function archive( int $id, int $by_user_id ): bool {
        if ( $id <= 0 ) return false;
        global $wpdb;
        $p = $wpdb->prefix;
        return false !== $wpdb->update(
            "{$p}tt_measurement_results",
            [ 'archived_at' => current_time( 'mysql', true ), 'archived_by' => $by_user_id ?: null ],
            [ 'id' => $id, 'club_id' => CurrentClub::id() ]
        );
    }
}
