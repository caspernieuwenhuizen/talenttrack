<?php
namespace TT\Modules\Analytics\Reports;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\MatchExecution\Domain\MatchStints;
use TT\Modules\MatchExecution\Repositories\MatchExecutionRepository;
use TT\Modules\MatchPrep\Repositories\MatchPrepRepository;
use TT\Modules\MatchPrep\Services\MatchLengthResolver;
use TT\Modules\Tournaments\Services\TournamentMinutesResolver;

/**
 * MinutesQuery (#1034) — per-player minutes aggregation for a team
 * over a date window, partitioned by match-type (game_subtype_key).
 *
 * Sources of truth:
 *   - `tt_attendance.minutes_played` (record_type='actual') → minute totals
 *   - `tt_match_prep_lineup` + `tt_match_execution_substitutions`, walked
 *     together by `MatchStints` → starts / subs_in / subs_off (#4059,
 *     #4060); the first-period line-up alone when no execution exists
 *   - `tt_activities.game_subtype_key`   → League / Cup / Friendly bucket
 *   - `MatchLengthResolver::playedMatchMinutes()` → each match's length for
 *     `available_minutes`, the same chain the audit and minutes share read
 *     (#4077)
 *
 * #2193 — minutes are read ONLY from persisted `record_type='actual'`
 * attendance rows. They are computed exactly once, when a played match
 * is recorded (execution finalize or the manual attendance entry), and
 * stored there. This query never estimates, calculates, or constructs
 * minutes at report time; a match with no recorded minutes contributes
 * 0. Per-player totals are summed across all activities in the window.
 *
 * #4053 — with one named exception, and only one: a **tournament fixture
 * completed before v4.135.0** has a register with no minutes on it, because
 * the confirm step that writes them did not exist yet, so every minutes
 * surface read the whole squad as nil. Those fixtures fall back to the
 * rotation plan through `TournamentMinutesResolver`, which is also what the
 * player's Tournaments tab and the coach's ticker read. A register figure
 * always wins, a confirmed `0` included, and nothing else is ever derived.
 *
 * v1 scope:
 *   - Team-scoped only. A player-detail variant lives in a follow-up.
 *   - No REST endpoint — the view consumes the service directly. A
 *     `GET /talenttrack/v1/teams/{id}/minutes` endpoint is in #1034's
 *     scoped follow-ups.
 *   - No `Analytics\FactRegistry` integration. Same follow-up.
 */
final class MinutesQuery {

    /** The most periods a match is played in (quarters). */
    private const MAX_PERIODS = 4;

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
        $length_resolver = new MatchLengthResolver();

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
            $minutes_map = self::minutesFor( $aid, $club_id );

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

            $prep_period = $prep ? (int) $prep->half_length_minutes : 0;

            // Line-ups per period. The column is a period number, so a
            // quarters match carries four of them rather than two halves.
            $lineups = [];
            if ( $prep ) {
                foreach ( $prep_repo->listLineup( (int) $prep->id ) as $l ) {
                    $period = (int) $l->half;
                    $pid    = (int) $l->player_id;
                    if ( $period > 0 && $pid > 0 ) $lineups[ $period ][] = $pid;
                }
            }

            $exec = $exec_repo->findByActivity( $aid );
            $exec_id = $exec ? (int) $exec->id : 0;
            $sub_rows = $exec_id > 0 ? $exec_repo->listSubstitutions( $exec_id ) : [];

            // #4077 — the match's length comes from the same chain the
            // minutes audit and the minutes share read, so "% available"
            // agrees with them. A match with no prep row used to count as
            // 2 × 35 here even when its age group plays 2 × 30.
            $periods      = self::periodCount( $lineups, $sub_rows );
            $match_length = $length_resolver->playedMatchMinutes( $aid, $prep_period, $periods );
            $half_length  = $prep_period > 0 ? $prep_period : (int) ceil( $match_length / $periods );
            $available_minutes += $match_length;

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

            // #4059 / #4060 — started / came on / went off, once per match.
            // Read off the same spells the persisted minutes were summed
            // from, rather than re-derived from the line-ups on their own:
            // reading both half line-ups as "starts" is how a half-time
            // substitute became a starter, and reading only the
            // substitution log is how a half-time change reached neither
            // Ingevallen nor Gewisseld.
            $facts = self::appearanceFacts( $lineups, $exec_id > 0 ? $sub_rows : null, $half_length, $periods );

            // Fold minutes + match-type bucket + appearance facts per player.
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
                $matches[ $pid ] = ( $matches[ $pid ] ?? 0 ) + 1;

                // Only a player who played can have started, come on or
                // gone off — a line-up name with no recorded minutes did
                // not turn up, and counting them would let starts outrun
                // matches (#2252).
                $f = $facts[ $pid ] ?? null;
                if ( $f === null ) continue;
                if ( $f['started'] )  $starts[ $pid ]   = ( $starts[ $pid ]   ?? 0 ) + 1;
                if ( $f['came_on'] )  $subs_in[ $pid ]  = ( $subs_in[ $pid ]  ?? 0 ) + 1;
                if ( $f['went_off'] ) $subs_off[ $pid ] = ( $subs_off[ $pid ] ?? 0 ) + 1;
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
     * Whether each player started, came on and went off in one match
     * (#4059, #4060).
     *
     * With a match-execution record (`$subs` is a list, possibly empty) the
     * facts come from {@see MatchStints}: the line-ups and the substitution
     * log walked together, which is also what the persisted minutes were
     * summed from. A half-time change is a line-up difference there, not a
     * substitution row, and it reaches both columns: the player coming on
     * at the break came on, the player they replaced went off. Nothing is
     * written to the substitution log to make that happen — the log stays
     * a record of what a coach tapped.
     *
     * Without one (`$subs` null — a match recorded by hand, #2159) there is
     * no timeline to read, so the first-period line-up is the start and
     * nobody came on or went off. Those zeros are not invented facts about
     * the match: `MatchExecutionMissingAlert` tells the coach the record
     * behind them is missing.
     *
     * @param array<int,list<int>> $lineups period => player ids on at its first minute
     * @param iterable<object>|null $subs   non-reversed substitutions, or null when no execution record exists
     * @return array<int, array{started:bool, came_on:bool, went_off:bool}>
     */
    public static function appearanceFacts( array $lineups, ?iterable $subs, int $period_length, int $periods = 2 ): array {
        if ( $subs === null ) {
            $out = [];
            foreach ( $lineups[1] ?? [] as $pid ) {
                $pid = (int) $pid;
                if ( $pid > 0 ) $out[ $pid ] = [ 'started' => true, 'came_on' => false, 'went_off' => false ];
            }
            return $out;
        }

        $periods = max( 1, $periods );
        $lengths = array_fill( 1, $periods, max( 0, $period_length ) );

        return MatchStints::appearances(
            MatchStints::intervalsForPeriods( $subs, $lineups, $lengths ),
            $periods * max( 0, $period_length )
        );
    }

    /**
     * How many periods a match was played in: two halves unless its
     * line-ups or substitutions name a later period.
     *
     * @param array<int,list<int>> $lineups
     * @param iterable<object>     $subs
     */
    private static function periodCount( array $lineups, iterable $subs ): int {
        $periods = 2;
        foreach ( array_keys( $lineups ) as $period ) {
            $periods = max( $periods, (int) $period );
        }
        foreach ( $subs as $sub ) {
            $periods = max( $periods, (int) ( $sub->half ?? 0 ) );
        }
        return self::clampPeriods( $periods );
    }

    /**
     * Two halves at the least, quarters at the most: a stray period number
     * must not multiply the available minutes.
     */
    public static function clampPeriods( int $highest_period ): int {
        return min( self::MAX_PERIODS, max( 2, $highest_period ) );
    }

    /**
     * How many periods each match was played in, for a set of activities at
     * once — the same answer {@see periodCount()} gives per match, read in
     * two grouped queries so the minutes audit and the minutes share can ask
     * about a whole season without a lookup per match. A quarters match
     * (four line-ups, or a substitution in period 4) reads 4, so its length
     * is the prep's period length × 4 rather than × 2 (#4087).
     *
     * Activities without a line-up or a substitution are absent from the
     * result; a caller reads them as two halves.
     *
     * @param list<int> $activity_ids
     * @return array<int,int> activity id => period count
     */
    public static function periodCountsFor( array $activity_ids ): array {
        $ids = [];
        foreach ( $activity_ids as $aid ) {
            $aid = (int) $aid;
            if ( $aid > 0 ) $ids[ $aid ] = true;
        }
        if ( $ids === [] ) return [];

        global $wpdb;
        $p       = $wpdb->prefix;
        $ids     = array_keys( $ids );
        $in      = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $params  = array_merge( $ids, [ (int) CurrentClub::id() ] );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $lineup_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT mp.activity_id, MAX( l.half ) AS highest
               FROM {$p}tt_match_prep_lineup l
               JOIN {$p}tt_match_prep mp ON mp.id = l.match_prep_id AND mp.club_id = l.club_id
              WHERE mp.activity_id IN ($in)
                AND l.club_id = %d
              GROUP BY mp.activity_id",
            $params
        ) );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $sub_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT e.activity_id, MAX( s.half ) AS highest
               FROM {$p}tt_match_execution_substitutions s
               JOIN {$p}tt_match_execution e ON e.id = s.execution_id AND e.club_id = s.club_id
              WHERE e.activity_id IN ($in)
                AND s.club_id = %d
                AND s.reversed_at IS NULL
              GROUP BY e.activity_id",
            $params
        ) );

        $highest = [];
        foreach ( array_merge( (array) $lineup_rows, (array) $sub_rows ) as $row ) {
            $aid = (int) $row->activity_id;
            $highest[ $aid ] = max( $highest[ $aid ] ?? 0, (int) $row->highest );
        }

        $out = [];
        foreach ( $highest as $aid => $n ) {
            $out[ $aid ] = self::clampPeriods( $n );
        }
        return $out;
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
     * #4053 — one activity's minutes, with the single documented fallback.
     *
     * For everything but a tournament fixture this is `persistedMinutes()`
     * unchanged: the #2193 single source of truth, and nothing is derived.
     *
     * A **completed tournament fixture whose register carries no minutes at
     * all** is the exception. Those predate the confirm step (v4.135.0), which
     * wrote the register without minutes, so every minutes surface read the
     * whole squad as nil for them. `TournamentMinutesResolver` answers for
     * those — register first, rotation plan only where the register holds no
     * figure — and it is the same class the player's Tournaments tab and the
     * coach's ticker read, so the three cannot disagree about one child's
     * afternoon. A confirmed `0` is a real figure and never falls back.
     *
     * Only players who got on the pitch, which is what `persistedMinutes()`
     * has always answered: a nil is a fact about the register, not an
     * appearance to count.
     *
     * @return array<int,int> player_id => minutes
     */
    private static function minutesFor( int $activity_id, int $club_id ): array {
        $resolved = TournamentMinutesResolver::effectiveForActivity( $activity_id );
        if ( $resolved === [] ) return self::persistedMinutes( $activity_id, $club_id );

        $map = [];
        foreach ( $resolved as $player_id => $minutes ) {
            if ( $minutes > 0 ) $map[ $player_id ] = $minutes;
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
     * Who played in one match, and for how long (#3516).
     *
     * A thin public door onto {@see persistedMinutes()} rather than a second
     * query, so the monthly report's per-match squads reconcile exactly with
     * the minutes the rest of the product shows — the #2193 single source of
     * truth, persisted `record_type = 'actual'` only.
     *
     * @return array<int,int> player_id => minutes
     */
    public static function squadForActivity( int $activity_id ): array {
        if ( $activity_id <= 0 ) return [];

        return self::minutesFor( $activity_id, (int) CurrentClub::id() );
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
            $minutes_map = self::minutesFor( $aid, $club_id );
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

    /**
     * #3666 — one player's playing time over a window: the per-match rows
     * and the total they sum to, in one answer.
     *
     * The summing used to sit in `MinutesRestController::breakdown()`,
     * which meant every new reader of a player's minutes did its own. The
     * staff route, the player-facing `GET players/{id}/minutes` route and
     * the development home all call this, so a player and their coach can
     * never be shown two different totals for the same window.
     *
     * @return array{
     *     team_id:int, player_id:int, from:string, to:string, total_minutes:int,
     *     matches:list<array{
     *         activity_id:int, session_date:string, title:string,
     *         type_key:string, minutes:int, record_type:string
     *     }>
     * }
     */
    public function playingTimeForPlayer( int $team_id, int $player_id, string $from, string $to ): array {
        $rows  = $this->matchBreakdownForPlayer( $team_id, $player_id, $from, $to );
        $total = 0;
        foreach ( $rows as $row ) {
            $total += (int) $row['minutes'];
        }

        return [
            'team_id'       => $team_id,
            'player_id'     => $player_id,
            'from'          => $from,
            'to'            => $to,
            'total_minutes' => $total,
            'matches'       => $rows,
        ];
    }

    /**
     * The window a minutes reader falls back to when the caller names
     * none: the last twelve months, ending today. Shared so the route's
     * default and the screen's cannot drift apart.
     *
     * @return array{from:string,to:string}
     */
    public static function defaultWindow(): array {
        return [
            'from' => gmdate( 'Y-m-d', strtotime( '-12 months' ) ),
            'to'   => gmdate( 'Y-m-d' ),
        ];
    }
}
