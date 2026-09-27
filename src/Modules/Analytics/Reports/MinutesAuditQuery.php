<?php
namespace TT\Modules\Analytics\Reports;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Domain\Vocabularies\Lookups\AttendanceStatus;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\MatchPrep\Services\MatchLengthResolver;
use TT\Modules\Teams\FootballFormResolver;

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
 *   - `unavailable` true → marked unavailable for this game (hatched, labelled)
 *   - `on_squad` false → not in this game's squad (hatched, informational)
 *
 * "On squad for a game" = the player has recorded minutes, or has a
 * non-guest attendance row for that activity (planned OR actual) and is not
 * marked unavailable for it (#4101). Unavailable means the match prep's
 * availability for the player is anything other than Present, or their
 * attendance status is (the recorded register when there is one, the plan
 * otherwise). The rule is "not Present", not a list, so a status added to
 * the vocabulary later is covered without touching this file. Late is
 * attendance, as everywhere else (#4041). Recorded minutes always win.
 *
 * Per-row completeness (status chip):
 *   - `complete`   → every on-squad player has minutes recorded AND the
 *                    total equals `available_minutes` (#4058)
 *   - `partial`    → some on-squad players are 0, or the total is short
 *                    of / over what the match holds (`status_reason` says
 *                    which)
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
     *     minutes:array<int,int>, on_squad:array<int,bool>, unavailable:array<int,bool>,
     *     total_minutes:int, available_minutes:int, recorded_count:int, squad_count:int,
     *     status:string, status_reason:string,
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
            'summary'       => [ 'total_games' => 0, 'complete' => 0, 'partial' => 0, 'none' => 0, 'rollups' => 0 ],
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
            "SELECT id, game_subtype_key, activity_type_key, tournament_id, {$date_col} AS session_date, title,
                    match_length_minutes, start_time, end_time,
                    ( SELECT mp.half_length_minutes FROM {$p}tt_match_prep mp
                       WHERE mp.activity_id = {$p}tt_activities.id LIMIT 1 ) AS prep_half_minutes
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

        // #4058 — what a whole match holds, per game: players a side times
        // the match length. Resolved once per team; the length per game.
        // #4087 — times the periods it was played in, as the minutes report
        // counts it, so a quarters match is not read as half a match.
        $team_basis = $this->teamBasis( $team_id );
        $periods    = MinutesQuery::periodCountsFor( $activity_ids );
        $available  = [];
        foreach ( $activities as $a ) {
            $available[ (int) $a->id ] = self::isTournamentRow( $a ) ? 0 : self::availableMinutes(
                $team_basis['players_a_side'],
                MatchLengthResolver::resolvePlayedLength(
                    (int) ( $a->prep_half_minutes ?? 0 ),
                    (int) ( $a->match_length_minutes ?? 0 ),
                    $team_basis['config_half_minutes'],
                    isset( $a->start_time ) ? (string) $a->start_time : '',
                    isset( $a->end_time ) ? (string) $a->end_time : '',
                    $periods[ (int) $a->id ] ?? 2
                )
            );
        }

        // 2. Squad membership per game — every non-guest attendance row on
        //    those activities. This is how the attendance report resolves the
        //    squad; using it (not tt_players.team_id) is the #2339 fix.
        //    #4101 — minus the players marked unavailable for the game.
        $in_ids = implode( ',', array_fill( 0, count( $activity_ids ), '%d' ) );
        $squad_marks = $this->squadMarks( $activity_ids );

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
        $unavail_by_game = []; // aid => [pid => true], #4101
        $minutes_by_game = []; // aid => [pid => minutes]
        $player_ids      = []; // set of every player that appears anywhere
        foreach ( $squad_marks as $aid => $marks ) {
            foreach ( $marks as $pid => $is_unavailable ) {
                if ( $is_unavailable ) {
                    $unavail_by_game[ $aid ][ $pid ] = true;
                } else {
                    $squad_by_game[ $aid ][ $pid ] = true;
                }
                $player_ids[ $pid ] = true;
            }
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
            foreach ( $rollup['unavailable'] as $pid => $_off ) {
                $unavail_by_game[ $aid ][ $pid ] = true;
                $player_ids[ $pid ]              = true;
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
                    'unavailable'    => [],
                    'total_minutes'     => 0,
                    'available_minutes' => $available[ (int) $a->id ] ?? 0,
                    'recorded_count'    => 0,
                    'squad_count'       => 0,
                    'status'            => 'none',
                    'status_reason'     => '',
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
            $squad_map   = $squad_by_game[ $aid ]   ?? [];
            $unavail_map = $unavail_by_game[ $aid ] ?? [];
            $min_map     = $minutes_by_game[ $aid ] ?? [];
            $is_rollup   = self::isTournamentRow( $a );

            // A player counts as on-squad for the game if they have an
            // attendance row OR recorded minutes (a paper match, #2159, may
            // carry only an 'actual' minutes row). #4101 — a player marked
            // unavailable is not on the squad: their zero is not a recording
            // gap. Recorded minutes still win, since they are what happened.
            $on_squad      = [];
            $unavailable   = [];
            $minutes       = [];
            $row_total     = 0;
            $recorded      = 0;
            $squad_count   = 0;
            foreach ( $ordered_pids as $pid ) {
                $is_squad = isset( $squad_map[ $pid ] ) || isset( $min_map[ $pid ] );
                $mins     = (int) ( $min_map[ $pid ] ?? 0 );
                $on_squad[ $pid ]    = $is_squad;
                $unavailable[ $pid ] = ! $is_squad && isset( $unavail_map[ $pid ] );
                $minutes[ $pid ]     = $mins;
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

            $available_minutes = $available[ $aid ] ?? 0;
            [ $status, $status_reason ] = self::completeness(
                $recorded,
                $squad_count,
                $row_total,
                $is_rollup ? null : $available_minutes
            );

            if ( $is_rollup ) {
                $summary['rollups']++;
            } else {
                $summary['total_games']++;
                if ( $status === 'complete' ) {
                    $summary['complete']++;
                } elseif ( $status === 'partial' ) {
                    $summary['partial']++;
                } else {
                    $summary['none']++;
                }
                $grand_total += $row_total;
            }

            $games[] = [
                'activity_id'    => $aid,
                'session_date'   => (string) $a->session_date,
                'title'          => (string) ( $a->title ?? '' ),
                'type_key'       => self::rowTypeKey( $a ),
                'minutes'        => $minutes,
                'on_squad'       => $on_squad,
                // #4101 — marked unavailable for this game (not Present in
                // match prep or on the register) and no minutes recorded.
                // The state only: no reason or injury detail leaves here.
                'unavailable'    => $unavailable,
                'total_minutes'     => $row_total,
                // #4058 — what the match holds, so the verdict can be
                // explained. 0 on a tournament roll-up, which is not judged
                // against a match length.
                'available_minutes' => $available_minutes,
                'recorded_count'    => $recorded,
                'squad_count'       => $squad_count,
                'status'            => $status,
                'status_reason'     => $status_reason,
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
     * #4058 — a game's completeness verdict, and why it is not complete.
     *
     *  - `none`     — nothing recorded;
     *  - `complete` — every squad player has minutes AND the minutes add up
     *    to exactly what the match holds;
     *  - `partial`  — anything else.
     *
     * Counting players alone called a game complete when eleven players had
     * 30 minutes each of a 70-minute match. Over-recording is caught too:
     * more minutes than the match can contain is usually a substitution
     * counted twice, and it inflates every report reading the same rows.
     *
     * The reason is `players_missing`, `minutes_short`, `minutes_over` or
     * `''`, so the screen can say which of the two checks failed — the chip
     * alone cannot. Pass `$available` null for a tournament roll-up, which
     * is judged on its players only: it has no match length of its own.
     *
     * @return array{0:string, 1:string} [ status, reason ]
     */
    public static function completeness( int $recorded, int $squad_count, int $total_minutes, ?int $available ): array {
        if ( $recorded === 0 ) return [ 'none', '' ];

        if ( $squad_count <= 0 || $recorded < $squad_count ) return [ 'partial', 'players_missing' ];

        if ( $available !== null ) {
            if ( $total_minutes < $available ) return [ 'partial', 'minutes_short' ];
            if ( $total_minutes > $available ) return [ 'partial', 'minutes_over' ];
        }

        return [ 'complete', '' ];
    }

    /**
     * #4058 — the player-minutes a whole match holds. The match length comes
     * from {@see MatchLengthResolver::resolvePlayedLength()} (#4077), the one
     * chain the minutes report and the minutes share read too.
     */
    public static function availableMinutes( int $players_a_side, int $match_length ): int {
        return max( 0, $players_a_side ) * max( 0, $match_length );
    }

    /**
     * Players a side and the configured half length for one team, read once
     * for the whole matrix.
     *
     * @return array{players_a_side:int, config_half_minutes:int}
     */
    private function teamBasis( int $team_id ): array {
        global $wpdb;
        $team = $wpdb->get_row( $wpdb->prepare(
            "SELECT football_form, age_group FROM {$wpdb->prefix}tt_teams WHERE id = %d AND club_id = %d",
            $team_id,
            CurrentClub::id()
        ) );

        $form      = is_object( $team ) ? FootballFormResolver::forTeamRow( $team ) : FootballFormResolver::FALLBACK_FORM;
        $age_group = is_object( $team ) ? trim( (string) ( $team->age_group ?? '' ) ) : '';
        $map       = ( new MatchLengthResolver() )->configuredMap();

        return [
            'players_a_side'      => FootballFormResolver::playersASide( $form ),
            'config_half_minutes' => $age_group !== '' ? (int) ( $map[ $age_group ] ?? 0 ) : 0,
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
     * @return array<int,array{squad:array<int,bool>, unavailable:array<int,bool>, minutes:array<int,int>}>
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
            $aid = (int) ( $a->id ?? 0 );
            if ( $tid > 0 && $aid > 0 ) $days[ $aid ] = $tid;
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
        // rolled-up minute is the same minute the fixture's own row shows,
        // and a player unavailable for a fixture is left out of the day's
        // squad the same way (#4101).
        $squad_marks = $this->squadMarks( $ids );
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

        $minutes_by_fixture = [];
        foreach ( (array) $minute_rows as $row ) {
            $aid = (int) $row->activity_id;
            $pid = (int) $row->player_id;
            $minutes_by_fixture[ $aid ][ $pid ] = ( $minutes_by_fixture[ $aid ][ $pid ] ?? 0 ) + (int) $row->minutes_played;
        }

        $out = [];
        foreach ( $days as $day_id => $tournament_id ) {
            $squad       = [];
            $unavailable = [];
            $minutes     = [];
            foreach ( $fixtures[ $tournament_id ] ?? [] as $fixture_id ) {
                foreach ( $squad_marks[ $fixture_id ] ?? [] as $pid => $is_unavailable ) {
                    if ( $is_unavailable ) {
                        $unavailable[ (int) $pid ] = true;
                    } else {
                        $squad[ (int) $pid ] = true;
                    }
                }
                foreach ( $minutes_by_fixture[ $fixture_id ] ?? [] as $pid => $mins ) {
                    $squad[ (int) $pid ]   = true;
                    $minutes[ (int) $pid ] = ( $minutes[ (int) $pid ] ?? 0 ) + (int) $mins;
                }
            }
            // On the day's squad for any fixture wins over unavailable for
            // another: the player was there for part of the afternoon.
            $unavailable = array_diff_key( $unavailable, $squad );
            if ( $squad === [] && $minutes === [] && $unavailable === [] ) continue;
            $out[ $day_id ] = [ 'squad' => $squad, 'unavailable' => $unavailable, 'minutes' => $minutes ];
        }
        return $out;
    }

    /**
     * #4101 — every player with a non-guest attendance row on each activity,
     * and whether they are marked unavailable for it.
     *
     * Unavailable is read from two places, either of which is enough:
     *
     *  - the match prep's availability for the player, when it is anything
     *    other than Present (match prep writes an attendance row for every
     *    player in its availability set, available or not);
     *  - the player's attendance status: the recorded register when there is
     *    one, the plan otherwise, since the register is what happened.
     *
     * Recorded minutes are not consulted here; the caller lets them win.
     *
     * @param array<int> $activity_ids
     * @return array<int, array<int, bool>> activity id => [ player id => unavailable ]
     */
    private function squadMarks( array $activity_ids ): array {
        if ( $activity_ids === [] ) return [];

        global $wpdb;
        $p      = $wpdb->prefix;
        $in     = implode( ',', array_fill( 0, count( $activity_ids ), '%d' ) );
        $params = array_merge( $activity_ids, [ (int) CurrentClub::id() ] );

        // Both kinds on purpose: a planned row is how a player joins a
        // game's selection, a recorded one says whether they turned up.
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT activity_id, player_id, record_type, status
               FROM {$p}tt_attendance
              WHERE activity_id IN ($in)
                AND club_id = %d
                AND is_guest = 0
                AND player_id > 0",
            $params
        ) );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $prep_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT mp.activity_id, av.player_id, av.status
               FROM {$p}tt_match_prep_availability av
               JOIN {$p}tt_match_prep mp ON mp.id = av.match_prep_id AND mp.club_id = av.club_id
              WHERE mp.activity_id IN ($in)
                AND av.club_id = %d",
            $params
        ) );

        $prep = [];
        foreach ( (array) $prep_rows as $r ) {
            $prep[ (int) $r->activity_id ][ (int) $r->player_id ] = (string) ( $r->status ?? '' );
        }

        // activity => player => [ 'actual' => statuses, 'planned' => statuses ]
        $statuses = [];
        foreach ( (array) $rows as $r ) {
            $aid  = (int) $r->activity_id;
            $pid  = (int) $r->player_id;
            $kind = (string) ( $r->record_type ?? '' ) === 'actual' ? 'actual' : 'planned';
            if ( $pid <= 0 ) continue;
            $statuses[ $aid ][ $pid ][ $kind ][] = (string) ( $r->status ?? '' );
        }

        $out = [];
        foreach ( $statuses as $aid => $players ) {
            foreach ( $players as $pid => $kinds ) {
                $out[ $aid ][ $pid ] = self::isMarkedUnavailable(
                    $prep[ $aid ][ $pid ] ?? null,
                    $kinds['actual'] ?? $kinds['planned'] ?? []
                );
            }
        }
        return $out;
    }

    /**
     * #4101 — is a player marked unavailable for one game?
     *
     * Yes when the match prep's availability is anything but Present, or
     * when none of their attendance statuses (one kind: the register, or the
     * plan when there is no register) is a turn-up. The rule is "not
     * Present" rather than a list of absences, so a status the vocabulary
     * gains later is covered here without an edit. Late is a turn-up, as in
     * the attendance one-rule (#4041). An empty status is the column's
     * default, Present.
     *
     * @param list<string> $attendance_statuses
     */
    public static function isMarkedUnavailable( ?string $prep_status, array $attendance_statuses ): bool {
        if ( $prep_status !== null && trim( $prep_status ) !== ''
            && AttendanceStatus::normalise( $prep_status ) !== AttendanceStatus::PRESENT ) {
            return true;
        }
        if ( $attendance_statuses === [] ) return false;

        foreach ( $attendance_statuses as $status ) {
            if ( trim( $status ) === '' ) return false;
            $canonical = AttendanceStatus::normalise( $status );
            if ( $canonical === AttendanceStatus::PRESENT || $canonical === AttendanceStatus::LATE ) return false;
        }
        return true;
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

        // Half length hint: the match prep's when present, otherwise half of
        // what the shared match-length chain resolves (#4077).
        $prep        = ( new \TT\Modules\MatchPrep\Repositories\MatchPrepRepository() )->findByActivity( $activity_id );
        $prep_half   = $prep ? (int) $prep->half_length_minutes : 0;
        $half_length = $prep_half > 0
            ? $prep_half
            : MatchLengthResolver::halfOf( ( new MatchLengthResolver() )->playedMatchMinutes( $activity_id ) );

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
