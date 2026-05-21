<?php
namespace TT\Modules\PersonaDashboard\TableSources;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\PersonaDashboard\Registry\TableRowSource;
use TT\Shared\Frontend\Components\RecordLink;

/**
 * BehaviourPendingSource (#871) — players overdue for a behaviour rating.
 *
 * Powers the `behaviour_pending` DataTableWidget preset on the coach +
 * HoD dashboards. Per parent epic #867: coaches have no signal that
 * behaviour is part of weekly hygiene. This source surfaces players
 * the coach hasn't rated in the last N days (default 14, configurable
 * via `tt_config.behaviour_staleness_days`).
 *
 * Scoping:
 *   - Users with `tt_manage_players` (HoDs + admins) see all active
 *     players in the club.
 *   - Users with only `tt_rate_player_behaviour` see only players on
 *     teams they're a coach of (via `QueryHelpers::get_teams_for_coach`).
 *
 * Rows: `[ player_name, team_name, "21 days" | "never" ]` + an
 * action cell linking to the player profile with `action=log-behaviour`
 * so sub-ship #870 (hero popover) can auto-open the behaviour form on
 * landing once we wire that pickup. Pre-#870 the param is ignored,
 * which is fine — the user lands on the player profile and finds the
 * hero buttons.
 *
 * Ordering: `days_since DESC` then player name, so the most-overdue
 * row shows first. Limit defaults to 10 rows per the issue spec.
 */
final class BehaviourPendingSource implements TableRowSource {

    /**
     * @param array<string, mixed> $config
     * @return list<list<string>>
     */
    public function rowsFor( int $user_id, array $config ): array {
        global $wpdb;
        $p = $wpdb->prefix;

        if ( $user_id <= 0 ) return [];
        if ( ! user_can( $user_id, 'tt_rate_player_behaviour' ) ) return [];

        $club_id = CurrentClub::id();
        $limit   = max( 1, min( 50, (int) ( $config['limit'] ?? 10 ) ) );
        $staleness_days = max( 1, (int) QueryHelpers::get_config(
            'behaviour_staleness_days',
            (string) ( $config['days'] ?? 14 )
        ) );

        $see_all_global = user_can( $user_id, 'tt_manage_players' );

        $where  = [ 'pl.club_id = %d', "pl.status = 'active'" ];
        $params = [ $club_id ];
        if ( ! $see_all_global ) {
            $teams = QueryHelpers::get_teams_for_coach( $user_id );
            $team_ids = array_map( static fn( $t ) => (int) $t->id, (array) $teams );
            if ( $team_ids === [] ) return [];
            $placeholders = implode( ',', array_fill( 0, count( $team_ids ), '%d' ) );
            $where[] = "pl.team_id IN ({$placeholders})";
            foreach ( $team_ids as $tid ) $params[] = $tid;
        }

        // The latest rating per player via a correlated subquery —
        // simpler than a GROUP BY + JOIN for the dashboard row count.
        // DATEDIFF treats a NULL latest_rated_at as NULL → the row
        // surfaces as "never rated" once filtered.
        $sql = "SELECT pl.id, pl.first_name, pl.last_name,
                       t.name AS team_name,
                       (SELECT MAX(b.rated_at) FROM {$p}tt_player_behaviour_ratings b
                          WHERE b.player_id = pl.id AND b.club_id = pl.club_id
                       ) AS latest_rated_at
                  FROM {$p}tt_players pl
                  LEFT JOIN {$p}tt_teams t ON t.id = pl.team_id AND t.club_id = pl.club_id
                 WHERE " . implode( ' AND ', $where ) . "
                HAVING latest_rated_at IS NULL
                    OR DATEDIFF(CURRENT_DATE, latest_rated_at) > %d
                 ORDER BY (latest_rated_at IS NULL) DESC,
                          latest_rated_at ASC,
                          pl.last_name ASC, pl.first_name ASC
                 LIMIT %d";
        $params[] = $staleness_days;
        $params[] = $limit;

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
        $rows = is_array( $rows ) ? $rows : [];

        $out = [];
        foreach ( $rows as $r ) {
            $name = trim( (string) ( $r->first_name ?? '' ) . ' ' . (string) ( $r->last_name ?? '' ) );
            $team = (string) ( $r->team_name ?? '' );
            $latest = (string) ( $r->latest_rated_at ?? '' );
            $days_label = $latest === ''
                ? __( 'never', 'talenttrack' )
                : sprintf(
                    /* translators: %d: days since last rating */
                    _n( '%d day', '%d days', self::daysSince( $latest ), 'talenttrack' ),
                    self::daysSince( $latest )
                );

            // #871 — row link carries `action=log-behaviour`. The
            // player-profile renderer doesn't act on the param today;
            // sub-ship #870 (hero popover) can consume it to auto-open
            // the behaviour form on landing. Pre-#870 the param is
            // silently ignored — no error, just a normal page load.
            $open_url = add_query_arg(
                [
                    'tt_view'   => 'players',
                    'id'        => (int) $r->id,
                    'action'    => 'log-behaviour',
                ],
                RecordLink::dashboardUrl()
            );

            $out[] = [
                '<a class="tt-pd-row-link" href="' . esc_url( $open_url ) . '">' . esc_html( $name !== '' ? $name : '—' ) . '</a>',
                esc_html( $team !== '' ? $team : '—' ),
                esc_html( $days_label ),
            ];
        }
        return $out;
    }

    private static function daysSince( string $rated_at ): int {
        $ts = strtotime( $rated_at );
        if ( $ts === false ) return 0;
        $now = current_time( 'timestamp' );
        return max( 0, (int) floor( ( $now - $ts ) / DAY_IN_SECONDS ) );
    }
}
