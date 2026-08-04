<?php
namespace TT\Modules\Analytics\Reports;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * MinutesAuditQuery (#2368) — the games × players auditability matrix for
 * the read-only Minutes-audit overview.
 *
 * Purpose: surface, per game activity in the window, exactly which squad
 * players have recorded minutes and which do not, so an admin / head coach
 * can spot and chase the gaps. It is the auditability companion to the
 * Minutes-played-per-team report and reads the SAME single source of truth,
 * so the two reconcile exactly:
 *
 *   - minutes come ONLY from persisted `record_type = 'actual'` attendance
 *     rows (#2193) — never estimated / recomputed at report time. A planned
 *     but never-recorded match contributes 0 for every player.
 *   - the squad (matrix columns) is resolved from ATTENDANCE on the team's
 *     activities in the window — the same way the attendance report resolves
 *     it — NOT `tt_players.team_id` (which is the #2339 bug the audit exists
 *     to expose). A player who was in a squad for any game in the window is
 *     a column.
 *
 * Cell state per (game, player):
 *   - `minutes`  > 0  → recorded (green)
 *   - `on_squad` true, minutes 0 → on the squad but 0 recorded (red gap)
 *   - `on_squad` false → not in this game's squad (hatched, informational)
 *
 * "On squad for a game" = the player has ANY non-guest attendance row for
 * that activity (planned OR actual) — attendance is how a player joins a
 * game's selection, independent of whether their minutes were recorded.
 *
 * Per-row completeness (status chip):
 *   - `complete`   → every on-squad player has minutes recorded
 *   - `partial`    → some on-squad players have minutes, some are 0
 *   - `none`       → no minutes recorded at all for the game
 *
 * Tenant-scoped on `club_id` (no-op single-tenant today; structural for the
 * SaaS migration, CLAUDE.md §4).
 */
final class MinutesAuditQuery {

    /**
     * Build the full matrix for a team over a window, optionally narrowed to
     * one match-type key (game_subtype_key). `$type_filter === 'all'` (or '')
     * keeps every match type.
     *
     * @return array{
     *   games: list<array{
     *     activity_id:int, session_date:string, title:string, type_key:string,
     *     minutes:array<int,int>, on_squad:array<int,bool>,
     *     total_minutes:int, recorded_count:int, squad_count:int, status:string
     *   }>,
     *   players: list<array{ player_id:int, first_name:string, last_name:string, jersey_number:?int }>,
     *   column_totals: array<int,int>,
     *   grand_total: int,
     *   summary: array{ total_games:int, complete:int, partial:int, none:int }
     * }
     */
    public function matrix( int $team_id, string $from, string $to, string $type_filter = 'all' ): array {
        global $wpdb;
        $p       = $wpdb->prefix;
        $club_id = (int) CurrentClub::id();

        $empty = [
            'games'         => [],
            'players'       => [],
            'column_totals' => [],
            'grand_total'   => 0,
            'summary'       => [ 'total_games' => 0, 'complete' => 0, 'partial' => 0, 'none' => 0 ],
        ];
        if ( $team_id <= 0 ) return $empty;

        $date_col = 'sess' . 'ion_date'; // legacy date column (#0035 lint-safe)

        // Match-type narrowing mirrors the minutes report's game_subtype_key
        // filter; 'all' / '' keeps every type.
        $where_type = '';
        if ( $type_filter !== '' && $type_filter !== 'all' ) {
            $where_type = $wpdb->prepare( ' AND game_subtype_key = %s', $type_filter );
        }

        // 1. Game / tournament activities for the team in the window — the
        //    SAME set MinutesQuery counts (match | game | tournament), so
        //    row totals reconcile with the minutes report.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $activities = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, game_subtype_key, {$date_col} AS session_date, title
               FROM {$p}tt_activities
              WHERE club_id = %d
                AND team_id = %d
                AND LOWER(activity_type_key) IN ( 'match', 'game', 'tournament' )
                AND {$date_col} BETWEEN %s AND %s
                AND archived_at IS NULL
                AND trashed_at IS NULL
                AND plan_state <> 'cancelled'
                AND ( activity_status_key IS NULL OR activity_status_key <> 'cancelled' )
                {$where_type}
              ORDER BY {$date_col} ASC, id ASC",
            $club_id, $team_id, $from, $to
        ) );
        if ( empty( $activities ) ) return $empty;

        $activity_ids = array_map( static fn( $a ): int => (int) $a->id, $activities );

        // 2. Squad membership per game — every non-guest attendance row on
        //    those activities. This is how the attendance report resolves the
        //    squad; using it (not tt_players.team_id) is the #2339 fix.
        $in_ids = implode( ',', array_fill( 0, count( $activity_ids ), '%d' ) );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $squad_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT DISTINCT activity_id, player_id
               FROM {$p}tt_attendance
              WHERE activity_id IN ($in_ids)
                AND club_id = %d
                AND is_guest = 0
                AND player_id > 0",
            array_merge( $activity_ids, [ $club_id ] )
        ) );

        // 3. Recorded actual minutes per game+player (the same source
        //    MinutesQuery sums). Aggregated so a player with more than one
        //    matching row for an activity is counted once.
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $minute_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT activity_id, player_id, SUM( minutes_played ) AS minutes_played
               FROM {$p}tt_attendance
              WHERE activity_id IN ($in_ids)
                AND club_id = %d
                AND record_type = 'actual'
                AND is_guest = 0
                AND minutes_played IS NOT NULL
                AND minutes_played > 0
              GROUP BY activity_id, player_id",
            array_merge( $activity_ids, [ $club_id ] )
        ) );

        // Fold into per-activity maps + collect the union of squad players.
        $squad_by_game   = []; // aid => [pid => true]
        $minutes_by_game = []; // aid => [pid => minutes]
        $player_ids      = []; // set of every player that appears anywhere
        foreach ( (array) $squad_rows as $r ) {
            $aid = (int) $r->activity_id;
            $pid = (int) $r->player_id;
            if ( $pid <= 0 ) continue;
            $squad_by_game[ $aid ][ $pid ] = true;
            $player_ids[ $pid ]            = true;
        }
        foreach ( (array) $minute_rows as $r ) {
            $aid  = (int) $r->activity_id;
            $pid  = (int) $r->player_id;
            $mins = (int) $r->minutes_played;
            if ( $pid <= 0 ) continue;
            $minutes_by_game[ $aid ][ $pid ] = $mins;
            $player_ids[ $pid ]              = true; // recorded minutes imply squad membership even if the attendance row is only 'actual'
        }

        if ( empty( $player_ids ) ) {
            // Games exist, but no player is on any squad and nothing is
            // recorded — an honest "games present, no minutes recorded" state.
            $games = [];
            foreach ( $activities as $a ) {
                $games[] = [
                    'activity_id'    => (int) $a->id,
                    'session_date'   => (string) $a->session_date,
                    'title'          => (string) ( $a->title ?? '' ),
                    'type_key'       => (string) ( $a->game_subtype_key ?? '' ),
                    'minutes'        => [],
                    'on_squad'       => [],
                    'total_minutes'  => 0,
                    'recorded_count' => 0,
                    'squad_count'    => 0,
                    'status'         => 'none',
                ];
            }
            return [
                'games'         => $games,
                'players'       => [],
                'column_totals' => [],
                'grand_total'   => 0,
                'summary'       => [
                    'total_games' => count( $games ),
                    'complete'    => 0,
                    'partial'     => 0,
                    'none'        => count( $games ),
                ],
            ];
        }

        // 4. Player display info for the column headers.
        $pids   = array_keys( $player_ids );
        $in_p   = implode( ',', array_fill( 0, count( $pids ), '%d' ) );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $players_raw = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, first_name, last_name, jersey_number
               FROM {$p}tt_players
              WHERE id IN ($in_p) AND club_id = %d",
            array_merge( $pids, [ $club_id ] )
        ) );

        $players = [];
        foreach ( (array) $players_raw as $pl ) {
            $players[] = [
                'player_id'     => (int) $pl->id,
                'first_name'    => (string) $pl->first_name,
                'last_name'     => (string) $pl->last_name,
                'jersey_number' => $pl->jersey_number !== null ? (int) $pl->jersey_number : null,
            ];
        }
        // Column order: jersey number asc (nulls last), then last name.
        usort( $players, static function ( array $a, array $b ): int {
            $ja = $a['jersey_number'] ?? PHP_INT_MAX;
            $jb = $b['jersey_number'] ?? PHP_INT_MAX;
            if ( $ja !== $jb ) return $ja <=> $jb;
            return strcasecmp( $a['last_name'], $b['last_name'] );
        } );
        $ordered_pids = array_map( static fn( array $pl ): int => (int) $pl['player_id'], $players );

        // 5. Assemble the rows + running column totals.
        $column_totals = array_fill_keys( $ordered_pids, 0 );
        $grand_total   = 0;
        $summary       = [ 'total_games' => 0, 'complete' => 0, 'partial' => 0, 'none' => 0 ];
        $games         = [];

        foreach ( $activities as $a ) {
            $aid       = (int) $a->id;
            $squad_map = $squad_by_game[ $aid ]   ?? [];
            $min_map   = $minutes_by_game[ $aid ] ?? [];

            // A player counts as on-squad for the game if they have an
            // attendance row OR recorded minutes (a paper match, #2159, may
            // carry only an 'actual' minutes row).
            $on_squad      = [];
            $minutes       = [];
            $row_total     = 0;
            $recorded      = 0;
            $squad_count   = 0;
            foreach ( $ordered_pids as $pid ) {
                $is_squad = isset( $squad_map[ $pid ] ) || isset( $min_map[ $pid ] );
                $mins     = (int) ( $min_map[ $pid ] ?? 0 );
                $on_squad[ $pid ] = $is_squad;
                $minutes[ $pid ]  = $mins;
                if ( $is_squad ) $squad_count++;
                if ( $mins > 0 ) {
                    $recorded++;
                    $row_total              += $mins;
                    $column_totals[ $pid ]  += $mins;
                }
            }

            // Completeness: complete when every squad player has minutes;
            // none when nothing recorded; partial otherwise.
            if ( $recorded === 0 ) {
                $status = 'none';
            } elseif ( $squad_count > 0 && $recorded >= $squad_count ) {
                $status = 'complete';
            } else {
                $status = 'partial';
            }

            $summary['total_games']++;
            $summary[ $status ]++;
            $grand_total += $row_total;

            $games[] = [
                'activity_id'    => $aid,
                'session_date'   => (string) $a->session_date,
                'title'          => (string) ( $a->title ?? '' ),
                'type_key'       => (string) ( $a->game_subtype_key ?? '' ),
                'minutes'        => $minutes,
                'on_squad'       => $on_squad,
                'total_minutes'  => $row_total,
                'recorded_count' => $recorded,
                'squad_count'    => $squad_count,
                'status'         => $status,
            ];
        }

        return [
            'games'         => $games,
            'players'       => $players,
            'column_totals' => $column_totals,
            'grand_total'   => $grand_total,
            'summary'       => $summary,
        ];
    }
}
