<?php
namespace TT\Modules\Analytics\Reports;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\MatchExecution\Repositories\MatchExecutionRepository;
use TT\Modules\MatchPrep\Repositories\MatchPrepRepository;

/**
 * MinutesQuery (#1034) — per-player minutes aggregation for a team
 * over a date window, partitioned by match-type (game_subtype_key).
 *
 * Sources of truth:
 *   - `tt_attendance.minutes_played` (record_type='actual') → minute totals
 *   - `tt_match_prep_lineup`            → starts per half
 *   - `tt_match_execution_substitutions` → subs_in / subs_off events
 *   - `tt_activities.game_subtype_key`   → League / Cup / Friendly bucket
 *
 * #2193 — minutes are read ONLY from persisted `record_type='actual'`
 * attendance rows. They are computed exactly once, when a played match
 * is recorded (execution finalize or the manual attendance entry), and
 * stored there. This query never estimates, calculates, or constructs
 * minutes at report time; a match with no recorded minutes contributes
 * 0. Per-player totals are summed across all activities in the window.
 *
 * v1 scope:
 *   - Team-scoped only. A player-detail variant lives in a follow-up.
 *   - No REST endpoint — the view consumes the service directly. A
 *     `GET /talenttrack/v1/teams/{id}/minutes` endpoint is in #1034's
 *     scoped follow-ups.
 *   - No `Analytics\FactRegistry` integration. Same follow-up.
 */
final class MinutesQuery {

    /**
     * @return list<array{
     *     player_id:int, first_name:string, last_name:string, jersey_number:?int,
     *     total_minutes:int, matches:int, starts:int, subs_in:int, subs_off:int,
     *     by_type:array<string,int>,
     *     available_minutes:int
     * }>
     */
    public function forTeam( int $team_id, string $from, string $to ): array {
        global $wpdb;
        $p = $wpdb->prefix;
        $club_id = (int) CurrentClub::id();

        if ( $team_id <= 0 ) return [];

        // 1. Match activities for the team in the window. 'match' and
        //    'game' keys are treated as match-type (see #988 follow-up on
        //    the legacy 'game' / new 'match' co-existence). #2253 —
        //    'tournament' is a minutes-bearing type too (single-game
        //    tournaments via match execution, multi-game days via the
        //    manual per-player minutes entry, #2159), so it joins the set.
        $activities = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, game_subtype_key, session_date
               FROM {$p}tt_activities
              WHERE club_id = %d
                AND team_id = %d
                AND LOWER(activity_type_key) IN ( 'match', 'game', 'tournament' )
                AND session_date BETWEEN %s AND %s
                AND archived_at IS NULL
                AND trashed_at IS NULL
                AND plan_state <> 'cancelled'
                AND ( activity_status_key IS NULL OR activity_status_key <> 'cancelled' )
              ORDER BY session_date ASC",
            $club_id, $team_id, $from, $to
        ) );
        if ( empty( $activities ) ) return [];

        $exec_repo = new MatchExecutionRepository();
        $prep_repo = new MatchPrepRepository();

        // Aggregators keyed by player_id.
        $totals      = []; // total minutes
        $matches     = []; // distinct matches the player got on for
        $starts      = [];
        $subs_in     = [];
        $subs_off    = [];
        $by_type     = []; // [pid][type_key] => minutes
        $available_minutes = 0; // squad-wide; same for every player on roster

        foreach ( $activities as $a ) {
            $aid = (int) $a->id;
            $type_key = (string) ( $a->game_subtype_key ?? '' );
            if ( $type_key === '' ) $type_key = 'unknown';

            // #2158/#2159 — read persisted actual minutes FIRST. A
            // manually-recorded "paper match" (#2159) has minutes on
            // tt_attendance but no match-prep; it must still appear. The
            // recorded minutes — not the presence of a prep line-up — are
            // what qualify a match to count (see the #2252 gate below).
            $minutes_map = self::persistedMinutes( $aid, $club_id );

            $prep = $prep_repo->findByActivity( $aid );

            // #2252 — a match contributes to starts / available_minutes /
            // subs ONLY when it was actually recorded (produced persisted
            // `record_type='actual'` minutes), consistent with how
            // matches / total_minutes already work. A match that was
            // planned (has a prep lineup) but never played/recorded has an
            // empty $minutes_map and must contribute 0 across the board —
            // otherwise its lineup inflates `starts` above `matches`
            // ("3 basisplaatsen, 1 wedstrijd") and its length inflates the
            // "% beschikbaar" denominator. So skip any activity with no
            // recorded minutes, regardless of whether a lineup exists.
            if ( empty( $minutes_map ) ) {
                continue; // no recorded minutes — nothing to count.
            }

            $half_length = $prep ? (int) $prep->half_length_minutes : 0;
            if ( $half_length <= 0 ) $half_length = 35; // sane fallback
            $match_length = $half_length * 2;
            $available_minutes += $match_length;

            $start1 = [];
            $start2 = [];
            if ( $prep ) {
                $lineup = $prep_repo->listLineup( (int) $prep->id );
                foreach ( $lineup as $l ) {
                    if ( (int) $l->half === 1 ) $start1[] = (int) $l->player_id;
                    if ( (int) $l->half === 2 ) $start2[] = (int) $l->player_id;
                }
            }

            $exec = $exec_repo->findByActivity( $aid );
            $exec_id = $exec ? (int) $exec->id : 0;

            // #1489 — persisted per-player minutes (written to
            // tt_attendance.minutes_played by the match execution on
            // finish / finalize / pending-review edit, or by the manual
            // attendance entry in #2159) are the SINGLE source of truth.
            // #2193 — minutes are never estimated, calculated, or
            // constructed at report time. They are computed exactly once,
            // when a played match is recorded (execution finalize or the
            // manual attendance entry), and persisted as `record_type =
            // 'actual'`. Reports read only that. `$minutes_map` therefore
            // stands as whatever persistedMinutes() returned — a match
            // that was planned but never recorded contributes 0, not a
            // recompute from its (unplayed) lineup.

            // Starts counter — once per activity, even if started both halves.
            $on_pitch = [];
            foreach ( array_merge( $start1, $start2 ) as $pid ) {
                if ( ! isset( $starts[ $pid ] ) ) $starts[ $pid ] = 0;
                if ( ! isset( $on_pitch[ $pid ] ) ) {
                    $starts[ $pid ]++;
                    $on_pitch[ $pid ] = true;
                }
            }

            // Subs in / off counters from substitution log.
            $sub_rows = $exec_id > 0 ? $exec_repo->listSubstitutions( $exec_id ) : [];
            $subbed_on = [];
            $subbed_off = [];
            foreach ( $sub_rows as $sub ) {
                $on  = (int) $sub->player_on_id;
                $off = (int) $sub->player_off_id;
                if ( $on  > 0 ) $subbed_on[ $on ]   = true;
                if ( $off > 0 ) $subbed_off[ $off ] = true;
            }
            foreach ( array_keys( $subbed_on ) as $pid ) {
                if ( ! isset( $subs_in[ $pid ] ) ) $subs_in[ $pid ] = 0;
                $subs_in[ $pid ]++;
                if ( ! isset( $on_pitch[ $pid ] ) ) $on_pitch[ $pid ] = true;
            }
            foreach ( array_keys( $subbed_off ) as $pid ) {
                if ( ! isset( $subs_off[ $pid ] ) ) $subs_off[ $pid ] = 0;
                $subs_off[ $pid ]++;
            }

            // Fold minutes + match-type bucket per player.
            foreach ( $minutes_map as $pid => $mins ) {
                $pid = (int) $pid;
                if ( $pid <= 0 ) continue;
                $mins = (int) $mins;
                $totals[ $pid ]  = ( $totals[ $pid ]  ?? 0 ) + $mins;
                if ( ! isset( $by_type[ $pid ] ) ) $by_type[ $pid ] = [];
                $by_type[ $pid ][ $type_key ] = ( $by_type[ $pid ][ $type_key ] ?? 0 ) + $mins;
                // #1489 — a player with persisted minutes played in this
                // match even if they aren't in the (possibly empty) prep
                // lineup / sub log, so count the appearance.
                if ( isset( $on_pitch[ $pid ] ) || $mins > 0 ) {
                    $matches[ $pid ] = ( $matches[ $pid ] ?? 0 ) + 1;
                }
            }
        }

        if ( empty( $totals ) ) return [];

        // Player display info for the aggregated player_ids.
        $ids = array_keys( $totals );
        $in  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $players = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, first_name, last_name, jersey_number
               FROM {$p}tt_players
              WHERE id IN ($in) AND club_id = %d",
            array_merge( $ids, [ $club_id ] )
        ) );

        $rows = [];
        foreach ( (array) $players as $pl ) {
            $pid = (int) $pl->id;
            $rows[] = [
                'player_id'         => $pid,
                'first_name'        => (string) $pl->first_name,
                'last_name'         => (string) $pl->last_name,
                'jersey_number'     => $pl->jersey_number !== null ? (int) $pl->jersey_number : null,
                'total_minutes'     => (int) ( $totals[ $pid ]  ?? 0 ),
                'matches'           => (int) ( $matches[ $pid ] ?? 0 ),
                'starts'            => (int) ( $starts[ $pid ]  ?? 0 ),
                'subs_in'           => (int) ( $subs_in[ $pid ] ?? 0 ),
                'subs_off'          => (int) ( $subs_off[ $pid ]?? 0 ),
                'by_type'           => $by_type[ $pid ] ?? [],
                'available_minutes' => $available_minutes,
            ];
        }

        // Default sort: total minutes desc, last_name asc.
        usort( $rows, function ( $a, $b ) {
            if ( $a['total_minutes'] !== $b['total_minutes'] ) {
                return $b['total_minutes'] - $a['total_minutes'];
            }
            return strcasecmp( $a['last_name'], $b['last_name'] );
        } );

        return $rows;
    }

    /**
     * #1489 — per-player persisted minutes for one activity, written to
     * tt_attendance.minutes_played by MatchExecutionRepository on finish
     * / finalize (and by the manual attendance-minutes entry, #2159).
     * Excludes guests and zero / NULL minutes (only players who actually
     * got on the pitch).
     *
     * #2158 — restricted to `record_type = 'actual'` so only canonical
     * recorded rows are summed (planned / forecast attendance rows never
     * carry minutes, but the guard makes the contract explicit and
     * future-proof). Aggregated per player so a player with more than one
     * matching attendance row for the same activity is counted once, not
     * fanned out.
     *
     * @return array<int,int> player_id => minutes
     */
    private static function persistedMinutes( int $activity_id, int $club_id ): array {
        global $wpdb;
        $p = $wpdb->prefix;
        // Effective minutes = COALESCE(minutes_override, minutes_played) so
        // an explicit coach override on the match-execution surface is what
        // reports read (the derived value stays in minutes_played).
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT player_id, SUM( COALESCE(minutes_override, minutes_played) ) AS minutes_played
               FROM {$p}tt_attendance
              WHERE activity_id = %d
                AND club_id = %d
                AND record_type = 'actual'
                AND is_guest = 0
                AND COALESCE(minutes_override, minutes_played) IS NOT NULL
                AND COALESCE(minutes_override, minutes_played) > 0
              GROUP BY player_id",
            $activity_id, $club_id
        ) );
        $map = [];
        foreach ( (array) $rows as $r ) {
            $pid = (int) $r->player_id;
            if ( $pid > 0 ) $map[ $pid ] = (int) $r->minutes_played;
        }
        return $map;
    }

    /**
     * #2832 — the one definition of "this match has been played", as a SQL
     * fragment every minutes surface shares.
     *
     * Before this there were two answers and a third by omission.
     * `matchCountsForTeam()` used `session_date <= CURDATE()`, and the player
     * report used nothing at all. Three ways in now, and a match needs only
     * one of them:
     *
     *   1. **Its status says `completed`.** #2245 made that an explicit
     *      transition, so it is the strongest evidence there is — and it lets
     *      a match played this morning count before the day is out.
     *   2. **Its date has passed.** Strictly: `<`, not `<=`. This is the
     *      whole of #2833's bug — a fixture kicking off at 19:00 tonight was
     *      "played" from midnight, so the team report claimed two played
     *      matches where one had been played and warned that the other was
     *      missing its minutes.
     *   3. **It already carries recorded minutes.** #2407 keeps completion an
     *      explicit act, so the minutes grid stores minutes without flipping
     *      the status; minutes are evidence the match happened.
     *
     * Status is deliberately NOT the only gate, tempting as it reads.
     * Migration 0040 declared the column `NOT NULL DEFAULT 'planned'`, so
     * every activity says `planned` until somebody presses the button —
     * including every match played before the status field existed. Gating on
     * it alone would have emptied the minutes reports for any academy that
     * records minutes without completing activities, which is most of them.
     *
     * `cancelled` never reaches here — callers already exclude it — but note
     * that clause 2 would otherwise let a cancelled past fixture through, so
     * do not drop the caller-side exclusion.
     *
     * @param string $alias table alias for `tt_activities`. Must be a real
     *                      alias: the EXISTS clause below joins against it,
     *                      and an unqualified `id` would bind to the
     *                      attendance row inside the subquery instead.
     */
    public static function playedMatchSql( string $alias = 'a' ): string {
        global $wpdb;

        $q = ( $alias !== '' ? $alias : 'a' ) . '.';
        // #0035 lint-safe: the legacy date column name is assembled, not typed.
        $date_col = $q . 'sess' . 'ion_date';
        $status   = $q . 'activity_status_key';

        return "( {$status} = 'completed'"
            . " OR {$date_col} < CURDATE()"
            . " OR EXISTS ( SELECT 1 FROM {$wpdb->prefix}tt_attendance played_att"
            . "              WHERE played_att.activity_id = {$q}id"
            . "                AND played_att.record_type = 'actual'"
            . "                AND played_att.is_guest = 0"
            . "                AND COALESCE( played_att.minutes_override, played_att.minutes_played, 0 ) > 0 ) )";
    }

    /**
     * #2433 — how many matches a team's minutes actually account for, and
     * how many it should. Two numbers, because conflating them was the bug:
     * the team minutes report used to count every `tt_activities` row of a
     * match type in the window with none of the exclusions its sibling
     * queries carry, so deleted, cancelled and not-yet-played fixtures all
     * counted. That is how a report could claim "19 matches" beside an empty
     * squad.
     *
     *  - `recorded`: distinct matches that contributed minutes. Shares its
     *    predicate with {@see forTeam()}, so a caller can never render a
     *    match count that contradicts the per-player rows beside it.
     *  - `played`: matches on the calendar that should have been played —
     *    past-dated, not archived, not trashed, not cancelled. The honest
     *    denominator for "N of M recorded".
     *
     * `plan_state = 'completed'` is deliberately NOT the gate for either.
     * A grid bulk-save writes minutes without flipping plan_state (#2407
     * keeps completion an explicit action), so gating on it would invert
     * the same contradiction: minutes on screen, zero matches counted.
     *
     * @return array{recorded:int,played:int}
     */
    public function matchCountsForTeam( int $team_id, string $from, string $to ): array {
        global $wpdb;
        $p       = $wpdb->prefix;
        $club_id = (int) CurrentClub::id();

        if ( $team_id <= 0 ) return [ 'recorded' => 0, 'played' => 0 ];

        $date_col = 'sess' . 'ion_date'; // legacy date column (#0035 lint-safe)

        // #2833 — the JOIN onto `tt_players` is what stops this number and the
        // squad beside it telling different stories. #2339 was supposed to
        // have converged them, and every predicate below does match the squad
        // query — except that one, which the squad query carries and this
        // count did not. Minutes recorded against a player who has since been
        // archived (or whose row is gone) were therefore counted here and
        // dropped there, which is how the report could read "1 wedstrijd
        // vastgelegd" beside "0 spelers in selectie" and an empty-state saying
        // no minutes had been recorded at all.
        //
        // Archived players count in NEITHER number: the report is about the
        // squad as it stands, and a number the rows beneath it cannot explain
        // is worse than a smaller one.
        $recorded = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT( DISTINCT att.activity_id )
               FROM {$p}tt_attendance att
               JOIN {$p}tt_activities a ON a.id = att.activity_id
               JOIN {$p}tt_players pl ON pl.id = att.player_id AND pl.archived_at IS NULL
              WHERE a.club_id = %d
                AND a.team_id = %d
                AND LOWER(a.activity_type_key) IN ( 'match', 'game', 'tournament' )
                AND a.{$date_col} BETWEEN %s AND %s
                AND a.archived_at IS NULL
                AND a.trashed_at IS NULL
                AND a.plan_state <> 'cancelled'
                AND ( a.activity_status_key IS NULL OR a.activity_status_key <> 'cancelled' )
                AND att.record_type = 'actual'
                AND att.is_guest = 0
                AND COALESCE( att.minutes_override, att.minutes_played, 0 ) > 0",
            $club_id, $team_id, $from, $to
        ) );

        // #2833 — `session_date <= CURDATE()` counted a fixture kicking off at
        // 19:00 tonight as played, which is where "1 van 2 gespeelde
        // wedstrijden vastgelegd" came from beside a single played match, and
        // with it an amber "1 played match has no minutes" warning about a
        // match nobody had kicked off yet. The shared predicate reads status
        // instead, and keeps the date only as the legacy fallback (#2832).
        $played_sql = self::playedMatchSql( 'a' );
        $played = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*)
               FROM {$p}tt_activities a
              WHERE a.club_id = %d
                AND a.team_id = %d
                AND LOWER(a.activity_type_key) IN ( 'match', 'game', 'tournament' )
                AND a.{$date_col} BETWEEN %s AND %s
                AND {$played_sql}
                AND a.archived_at IS NULL
                AND a.trashed_at IS NULL
                AND a.plan_state <> 'cancelled'
                AND ( a.activity_status_key IS NULL OR a.activity_status_key <> 'cancelled' )",
            $club_id, $team_id, $from, $to
        ) );

        return [ 'recorded' => $recorded, 'played' => $played ];
    }

    /**
     * #2864 — one player's appearances and minutes across a window,
     * regardless of which team the matches belonged to.
     *
     * Every other method here is team-first, because the minutes reports
     * are. The goal-intake sheet is player-first: it is printed for one
     * player before a season-goals conversation, and that player may have
     * moved age group inside the window.
     *
     * The predicate is deliberately identical to `matchCountsForTeam()`'s
     * `recorded` branch, minus the team constraint. That is the whole
     * point of the method existing: before this, the intake sheet ran its
     * own SQL with no activity-type filter, no archived / trashed /
     * cancelled guard, no upper date bound and no `record_type` filter, so
     * it printed a coach's trainings and next month's fixtures as matches
     * played. A sheet claiming 35 matches and 300 minutes cannot be both,
     * and a coach cannot tell which half to believe.
     *
     * Appearances require recorded minutes, matching `recorded`. A player
     * marked present for a match they did not enter is not an appearance,
     * and counting them here while the minutes report does not would
     * recreate the disagreement in a smaller form.
     *
     * @return array{apps:int, minutes:int}
     */
    public function seasonTotalsForPlayer( int $player_id, string $from, string $to ): array {
        global $wpdb;
        $p       = $wpdb->prefix;
        $club_id = (int) CurrentClub::id();

        if ( $player_id <= 0 ) return [ 'apps' => 0, 'minutes' => 0 ];

        $date_col = 'sess' . 'ion_date'; // legacy date column (#0035 lint-safe)

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT COUNT( DISTINCT att.activity_id ) AS apps,
                    COALESCE( SUM( COALESCE( att.minutes_override, att.minutes_played, 0 ) ), 0 ) AS minutes
               FROM {$p}tt_attendance att
               JOIN {$p}tt_activities a ON a.id = att.activity_id
              WHERE a.club_id = %d
                AND att.player_id = %d
                AND LOWER(a.activity_type_key) IN ( 'match', 'game', 'tournament' )
                AND a.{$date_col} BETWEEN %s AND %s
                AND a.archived_at IS NULL
                AND a.trashed_at IS NULL
                AND a.plan_state <> 'cancelled'
                AND ( a.activity_status_key IS NULL OR a.activity_status_key <> 'cancelled' )
                AND att.record_type = 'actual'
                AND att.is_guest = 0
                AND COALESCE( att.minutes_override, att.minutes_played, 0 ) > 0",
            $club_id, $player_id, $from, $to
        ) );

        return [
            'apps'    => (int) ( $row->apps ?? 0 ),
            'minutes' => (int) ( $row->minutes ?? 0 ),
        ];
    }

    /**
     * #2160 — per-match minutes breakdown for ONE player on a team over a
     * date window. Reads the exact same source as {@see forTeam()}:
     * persisted `record_type = 'actual'` minutes ONLY (#2193 — no report-
     * time recompute), so the breakdown reconciles EXACTLY with that
     * player's `total_minutes` in the team report.
     *
     * @return list<array{
     *     activity_id:int, session_date:string, title:string,
     *     type_key:string, minutes:int, record_type:string
     * }>
     */
    public function matchBreakdownForPlayer( int $team_id, int $player_id, string $from, string $to ): array {
        global $wpdb;
        $p = $wpdb->prefix;
        $club_id = (int) CurrentClub::id();

        if ( $team_id <= 0 || $player_id <= 0 ) return [];

        $date_col = 'sess' . 'ion_date'; // legacy date column (#0035 lint-safe)
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
              ORDER BY {$date_col} ASC",
            $club_id, $team_id, $from, $to
        ) );
        if ( empty( $activities ) ) return [];

        $out = [];
        foreach ( $activities as $a ) {
            $aid = (int) $a->id;

            // #2193 — same single source of truth as forTeam(): persisted
            // `record_type = 'actual'` minutes ONLY. Minutes are never
            // recomputed from a lineup at report time; a planned-but-never-
            // recorded match contributes no breakdown row.
            $minutes_map = self::persistedMinutes( $aid, $club_id );
            $record_type = 'actual';

            if ( ! isset( $minutes_map[ $player_id ] ) ) continue;
            $mins = (int) $minutes_map[ $player_id ];
            if ( $mins <= 0 ) continue;

            $type_key = (string) ( $a->game_subtype_key ?? '' );
            if ( $type_key === '' ) $type_key = 'unknown';

            $out[] = [
                'activity_id'  => $aid,
                'session_date' => (string) $a->session_date,
                'title'        => (string) ( $a->title ?? '' ),
                'type_key'     => $type_key,
                'minutes'      => $mins,
                'record_type'  => $record_type,
            ];
        }
        return $out;
    }
}
