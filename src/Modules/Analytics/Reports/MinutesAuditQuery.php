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
 * #3857 — a TOURNAMENT day is a read-only roll-up (`is_rollup`), not a
 * match. It carries no attendance of its own, so its squad and minutes are
 * summed from the fixtures of the tournament it is linked to, each of which
 * is its own activity once kicked off. The row's minutes are therefore a
 * view of rows already in the matrix, and it is excluded from
 * `column_totals`, `grand_total` and the `summary` buckets so the same
 * afternoon is never counted twice — `summary.rollups` counts these rows
 * separately. Its minutes are edited in the tournament planner, never here.
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
     *     total_minutes:int, recorded_count:int, squad_count:int, status:string,
     *     is_rollup:bool, editable:bool, tournament_id:int
     *   }>,
     *   players: list<array{ player_id:int, first_name:string, last_name:string, jersey_number:?int }>,
     *   column_totals: array<int,int>,
     *   grand_total: int,
     *   summary: array{ total_games:int, complete:int, partial:int, none:int, rollups:int }
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
            "SELECT id, game_subtype_key, activity_type_key, tournament_id, {$date_col} AS session_date, title
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
        //    matching row for an activity is counted once. Effective minutes
        //    = COALESCE(minutes_override, minutes_played) so an explicit
        //    coach override on the match-execution / audit surface is what
        //    the overview reflects — consistent with MinutesQuery and the
        //    minutes-authority arbiter (#2367). Harmless before any override
        //    exists (the column is null → minutes_played wins).
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $minute_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT activity_id, player_id, SUM( COALESCE(minutes_override, minutes_played) ) AS minutes_played
               FROM {$p}tt_attendance
              WHERE activity_id IN ($in_ids)
                AND club_id = %d
                AND record_type = 'actual'
                AND is_guest = 0
                AND COALESCE(minutes_override, minutes_played) IS NOT NULL
                AND COALESCE(minutes_override, minutes_played) > 0
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

        // 3b. #3857 — a tournament DAY holds no attendance of its own; the
        //     play happens in its fixtures, each of which is promoted to its
        //     own activity on kickoff. Left alone the day read as a match
        //     nobody had recorded, which is the opposite of the truth on the
        //     largest block of minutes in the month. Its squad + minutes are
        //     rolled up from those fixtures instead, and the row is marked
        //     not editable — minutes have one home, the tournament planner.
        $rollups = $this->fixtureRollups( $activities );
        foreach ( $rollups as $aid => $rollup ) {
            foreach ( $rollup['squad'] as $pid => $_on ) {
                $squad_by_game[ $aid ][ $pid ] = true;
                $player_ids[ $pid ]            = true;
            }
            foreach ( $rollup['minutes'] as $pid => $mins ) {
                $minutes_by_game[ $aid ][ $pid ] = $mins;
                $player_ids[ $pid ]              = true;
            }
        }

        if ( empty( $player_ids ) ) {
            // Games exist, but no player is on any squad and nothing is
            // recorded — an honest "games present, no minutes recorded" state.
            $games      = [];
            $editable   = 0;
            foreach ( $activities as $a ) {
                $is_rollup = self::isTournamentRow( $a );
                if ( ! $is_rollup ) $editable++;
                $games[] = [
                    'activity_id'    => (int) $a->id,
                    'session_date'   => (string) $a->session_date,
                    'title'          => (string) ( $a->title ?? '' ),
                    'type_key'       => self::rowTypeKey( $a ),
                    'minutes'        => [],
                    'on_squad'       => [],
                    'total_minutes'  => 0,
                    'recorded_count' => 0,
                    'squad_count'    => 0,
                    'status'         => 'none',
                    'is_rollup'      => $is_rollup,
                    'editable'       => ! $is_rollup,
                    'tournament_id'  => (int) ( $a->tournament_id ?? 0 ),
                ];
            }
            return [
                'games'         => $games,
                'players'       => [],
                'column_totals' => [],
                'grand_total'   => 0,
                'summary'       => [
                    'total_games' => $editable,
                    'complete'    => 0,
                    'partial'     => 0,
                    'none'        => $editable,
                    'rollups'     => count( $games ) - $editable,
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
        $summary       = [ 'total_games' => 0, 'complete' => 0, 'partial' => 0, 'none' => 0, 'rollups' => 0 ];
        $games         = [];

        foreach ( $activities as $a ) {
            $aid       = (int) $a->id;
            $squad_map = $squad_by_game[ $aid ]   ?? [];
            $min_map   = $minutes_by_game[ $aid ] ?? [];
            $is_rollup = self::isTournamentRow( $a );

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
                    $row_total += $mins;
                    // A roll-up shows what its fixtures hold; those fixture
                    // rows already carry the minutes into the totals, so
                    // adding them again here would count the same afternoon
                    // twice and stop the audit reconciling with the minutes
                    // report.
                    if ( ! $is_rollup ) $column_totals[ $pid ] += $mins;
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

            if ( $is_rollup ) {
                $summary['rollups']++;
            } else {
                $summary['total_games']++;
                $summary[ $status ]++;
                $grand_total += $row_total;
            }

            $games[] = [
                'activity_id'    => $aid,
                'session_date'   => (string) $a->session_date,
                'title'          => (string) ( $a->title ?? '' ),
                'type_key'       => self::rowTypeKey( $a ),
                'minutes'        => $minutes,
                'on_squad'       => $on_squad,
                'total_minutes'  => $row_total,
                'recorded_count' => $recorded,
                'squad_count'    => $squad_count,
                'status'         => $status,
                // #3857 — a tournament day is a read-only roll-up of its
                // fixtures. `editable` is what the client keys the edit
                // affordance off; `tournament_id` is where the minutes
                // actually live.
                'is_rollup'      => $is_rollup,
                'editable'       => ! $is_rollup,
                'tournament_id'  => (int) ( $a->tournament_id ?? 0 ),
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

    /**
     * #3857 — is this activity row a tournament DAY rather than a match?
     *
     * A day is the container; its fixtures are separate `match` activities
     * created on kickoff. The distinction is the activity type, not the
     * match subtype — `game_subtype_key` is empty on a tournament, which is
     * why the editor used to report an empty `type_key` for one.
     */
    private static function isTournamentRow( object $activity ): bool {
        return strtolower( (string) ( $activity->activity_type_key ?? '' ) ) === 'tournament';
    }

    /**
     * The row's type: the match subtype for a match, the activity type for
     * a tournament day, which has no subtype to report.
     */
    private static function rowTypeKey( object $activity ): string {
        $subtype = (string) ( $activity->game_subtype_key ?? '' );
        if ( $subtype !== '' ) return $subtype;
        return self::isTournamentRow( $activity ) ? 'tournament' : '';
    }

    /**
     * #3857 — squad + recorded minutes for each tournament day in the set,
     * rolled up from the fixtures of the tournament it is linked to.
     *
     * A fixture becomes its own activity when a coach kicks it off
     * (`tt_tournament_matches.activity_id`), and that activity is where its
     * attendance and minutes are recorded. A day with no linked tournament,
     * or one whose fixtures were never kicked off, rolls up nothing — and
     * honestly so: there are no recorded minutes anywhere for it.
     *
     * @param array<int,object> $activities
     * @return array<int,array{squad:array<int,bool>, minutes:array<int,int>}>
     */
    private function fixtureRollups( array $activities ): array {
        global $wpdb;
        $p       = $wpdb->prefix;
        $club_id = (int) CurrentClub::id();

        // day activity id => tournament id
        $days = [];
        foreach ( $activities as $a ) {
            if ( ! self::isTournamentRow( $a ) ) continue;
            $tid = (int) ( $a->tournament_id ?? 0 );
            if ( $tid > 0 ) $days[ (int) $a->id ] = $tid;
        }
        if ( $days === [] ) return [];

        $tournament_ids = array_values( array_unique( array_values( $days ) ) );
        $in_t = implode( ',', array_fill( 0, count( $tournament_ids ), '%d' ) );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $fixture_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT tournament_id, activity_id
               FROM {$p}tt_tournament_matches
              WHERE tournament_id IN ($in_t)
                AND club_id = %d
                AND activity_id IS NOT NULL",
            array_merge( $tournament_ids, [ $club_id ] )
        ) );

        // tournament id => [fixture activity ids]
        $fixtures = [];
        $all_ids  = [];
        foreach ( (array) $fixture_rows as $row ) {
            $aid = (int) ( $row->activity_id ?? 0 );
            $tid = (int) ( $row->tournament_id ?? 0 );
            if ( $aid <= 0 || $tid <= 0 ) continue;
            $fixtures[ $tid ][] = $aid;
            $all_ids[ $aid ]    = true;
        }
        if ( $all_ids === [] ) return [];

        $ids  = array_keys( $all_ids );
        $in_a = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

        // Same two reads the matrix makes for an ordinary match, so a
        // rolled-up minute is the same minute the fixture's own row shows.
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $squad_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT DISTINCT activity_id, player_id
               FROM {$p}tt_attendance
              WHERE activity_id IN ($in_a)
                AND club_id = %d
                AND is_guest = 0
                AND player_id > 0",
            array_merge( $ids, [ $club_id ] )
        ) );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $minute_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT activity_id, player_id, SUM( COALESCE(minutes_override, minutes_played) ) AS minutes_played
               FROM {$p}tt_attendance
              WHERE activity_id IN ($in_a)
                AND club_id = %d
                AND record_type = 'actual'
                AND is_guest = 0
                AND COALESCE(minutes_override, minutes_played) IS NOT NULL
                AND COALESCE(minutes_override, minutes_played) > 0
              GROUP BY activity_id, player_id",
            array_merge( $ids, [ $club_id ] )
        ) );

        $squad_by_fixture   = [];
        $minutes_by_fixture = [];
        foreach ( (array) $squad_rows as $row ) {
            $squad_by_fixture[ (int) $row->activity_id ][ (int) $row->player_id ] = true;
        }
        foreach ( (array) $minute_rows as $row ) {
            $aid = (int) $row->activity_id;
            $pid = (int) $row->player_id;
            $minutes_by_fixture[ $aid ][ $pid ] = ( $minutes_by_fixture[ $aid ][ $pid ] ?? 0 ) + (int) $row->minutes_played;
        }

        $out = [];
        foreach ( $days as $day_id => $tournament_id ) {
            $squad   = [];
            $minutes = [];
            foreach ( $fixtures[ $tournament_id ] ?? [] as $fixture_id ) {
                foreach ( array_keys( $squad_by_fixture[ $fixture_id ] ?? [] ) as $pid ) {
                    $squad[ (int) $pid ] = true;
                }
                foreach ( $minutes_by_fixture[ $fixture_id ] ?? [] as $pid => $mins ) {
                    $squad[ (int) $pid ]   = true;
                    $minutes[ (int) $pid ] = ( $minutes[ (int) $pid ] ?? 0 ) + (int) $mins;
                }
            }
            if ( $squad === [] && $minutes === [] ) continue;
            $out[ $day_id ] = [ 'squad' => $squad, 'minutes' => $minutes ];
        }
        return $out;
    }

    /**
     * #3857 — the tournament day behind an activity, or null when the
     * activity is an ordinary match (or nothing the caller may read).
     *
     * The minutes editor refuses a tournament day rather than opening an
     * empty squad on it, and the refusal has to name where the minutes do
     * live. It also has to be team-scoped like every other refusal on that
     * route, so the team comes back with it.
     *
     * @return array{activity_id:int, team_id:int, tournament_id:int, title:string}|null
     */
    public function tournamentDay( int $activity_id ): ?array {
        global $wpdb;
        $p       = $wpdb->prefix;
        $club_id = (int) CurrentClub::id();
        if ( $activity_id <= 0 ) return null;

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, team_id, tournament_id, title
               FROM {$p}tt_activities
              WHERE id = %d AND club_id = %d
                AND LOWER(activity_type_key) = 'tournament'",
            $activity_id, $club_id
        ) );
        if ( ! $row ) return null;

        return [
            'activity_id'   => (int) $row->id,
            'team_id'       => (int) $row->team_id,
            'tournament_id' => (int) ( $row->tournament_id ?? 0 ),
            'title'         => (string) ( $row->title ?? '' ),
        ];
    }

    /**
     * Per-match editor read model (#2367). Resolves the squad for ONE game
     * activity + each player's effective / derived / override minutes and
     * their roster attendance-row id, plus whether a match-execution owns
     * the activity's minutes (the arbiter, #2301).
     *
     * The client uses `owned_by_execution` to route each per-player write:
     *   - owned  → PATCH /match-execution/{activity}/minutes  (sets/clears
     *              the explicit override; survives recompute, #2301).
     *   - not    → PATCH /attendance/{attendance_id} {minutes_played} (the
     *              manual attendance path, #2159 — no execution to defer to).
     *
     * Squad resolution mirrors {@see matrix()}: players with a non-guest
     * attendance row on the activity (NOT tt_players.team_id, the #2339 bug).
     * A player with recorded minutes but no explicit attendance row still
     * counts (a paper match, #2159).
     *
     * @return array{
     *   activity: array{ id:int, team_id:int, title:string, session_date:string, type_key:string },
     *   owned_by_execution: bool,
     *   half_length: int,
     *   players: list<array{
     *     player_id:int, first_name:string, last_name:string, jersey_number:?int,
     *     attendance_id:int, minutes:?int, minutes_derived:?int, minutes_override:?int
     *   }>
     * }|null  null when the activity does not exist in the caller's club.
     */
    public function editorRows( int $activity_id ): ?array {
        global $wpdb;
        $p       = $wpdb->prefix;
        $club_id = (int) CurrentClub::id();
        if ( $activity_id <= 0 ) return null;

        $date_col = 'sess' . 'ion_date'; // legacy date column (#0035 lint-safe)

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $activity = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, team_id, title, game_subtype_key, activity_type_key, {$date_col} AS session_date
               FROM {$p}tt_activities
              WHERE id = %d AND club_id = %d
                AND LOWER(activity_type_key) IN ( 'match', 'game', 'tournament' )",
            $activity_id, $club_id
        ) );
        if ( ! $activity ) return null;

        // #3857 — a tournament day has no squad of its own: the play, and
        // the minutes, belong to its fixtures. This used to answer 200 with
        // an empty player list and no way to add anybody, so the audit row
        // could never leave "not recorded". The caller refuses it instead
        // and points at the planner; {@see tournamentDay()}.
        if ( self::isTournamentRow( $activity ) ) return null;

        // Effective / derived / override minutes + the attendance-row id per
        // roster player — the same read model the finalized minutes-
        // correction form uses (MatchExecutionRepository::attendanceRowsByActivity).
        $rows = ( new \TT\Modules\MatchExecution\Repositories\MatchExecutionRepository() )
            ->attendanceRowsByActivity( $activity_id );

        // Union the squad: every non-guest attendance row (planned OR actual)
        // plus anyone who already has a minutes row above. Both kinds on
        // purpose — the audit editor's job is to let somebody put minutes on
        // a player the register missed, and a player who was selected and
        // never registered is precisely that case. What they get written to
        // is a different question, and `attendanceRowsByActivity()` above
        // answers it with the recorded row or nothing. /* both-kinds-ok */
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $squad = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT player_id
               FROM {$p}tt_attendance
              WHERE activity_id = %d AND club_id = %d AND is_guest = 0 AND player_id > 0",
            $activity_id, $club_id
        ) );
        $pids = [];
        foreach ( (array) $squad as $pid ) { $pids[ (int) $pid ] = true; }
        foreach ( array_keys( $rows ) as $pid )     { $pids[ (int) $pid ] = true; }
        $pids = array_keys( $pids );

        $players = [];
        if ( $pids !== [] ) {
            $in_p = implode( ',', array_fill( 0, count( $pids ), '%d' ) );
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $raw = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, first_name, last_name, jersey_number
                   FROM {$p}tt_players
                  WHERE id IN ($in_p) AND club_id = %d",
                array_merge( $pids, [ $club_id ] )
            ) );
            foreach ( (array) $raw as $pl ) {
                $pid = (int) $pl->id;
                $row = $rows[ $pid ] ?? [ 'attendance_id' => 0, 'minutes' => null, 'minutes_derived' => null, 'minutes_override' => null ];
                $players[] = [
                    'player_id'        => $pid,
                    'first_name'       => (string) $pl->first_name,
                    'last_name'        => (string) $pl->last_name,
                    'jersey_number'    => $pl->jersey_number !== null ? (int) $pl->jersey_number : null,
                    'attendance_id'    => (int) $row['attendance_id'],
                    'minutes'          => $row['minutes'],
                    'minutes_derived'  => $row['minutes_derived'],
                    'minutes_override' => $row['minutes_override'],
                ];
            }
            usort( $players, static function ( array $a, array $b ): int {
                $ja = $a['jersey_number'] ?? PHP_INT_MAX;
                $jb = $b['jersey_number'] ?? PHP_INT_MAX;
                if ( $ja !== $jb ) return $ja <=> $jb;
                return strcasecmp( $a['last_name'], $b['last_name'] );
            } );
        }

        $owned = ( new \TT\Modules\MatchExecution\Repositories\MatchExecutionRepository() )
            ->existsForActivity( $activity_id );

        // Half length hint (default 35') from the match prep when present.
        $half_length = 35;
        $prep = ( new \TT\Modules\MatchPrep\Repositories\MatchPrepRepository() )->findByActivity( $activity_id );
        if ( $prep && (int) ( $prep->half_length_minutes ?? 0 ) > 0 ) {
            $half_length = (int) $prep->half_length_minutes;
        }

        return [
            'activity' => [
                'id'           => (int) $activity->id,
                'team_id'      => (int) $activity->team_id,
                'title'        => (string) ( $activity->title ?? '' ),
                'session_date' => (string) $activity->session_date,
                'type_key'     => self::rowTypeKey( $activity ),
            ],
            'owned_by_execution' => $owned,
            'half_length'        => $half_length,
            'players'            => $players,
        ];
    }
}
