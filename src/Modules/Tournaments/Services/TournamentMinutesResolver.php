<?php
namespace TT\Modules\Tournaments\Services;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * TournamentMinutesResolver (#4053) — the one answer to "what are this
 * player's minutes for this tournament fixture".
 *
 * ## Why it exists
 *
 * Two surfaces disagreed. The completion step (#4032) asks the coach to
 * confirm the minutes each child actually got and writes them to the
 * fixture's own register, because the auto-planned figures were wrong — two
 * keepers on ten minutes and thirteen children on nil, when they had all
 * played about twelve. The minutes grid and the minutes reports read that
 * register. The player's own Tournaments tab read the rotation plan instead,
 * so a coach's correction never reached the child's record.
 *
 * The rule, locked on #4053: **the register is a player's tournament
 * minutes; the rotation plan is the fallback where nothing was confirmed.**
 * A child's record carries what was played, not what was scheduled.
 *
 * This class is the only place that rule is written down. A second copy of
 * the fallback would recreate exactly the divergence it exists to close, so
 * every surface that reports tournament minutes comes through here:
 * `PlayerTournamentHistoryQuery` (the tab and `GET /players/{id}/tournaments`),
 * `TournamentsRestController::computeTotals()` (the coach's ticker),
 * `MinutesGridQuery` (the grid and `GET /activities/{id}/minutes`) and
 * `MinutesQuery` (the minutes reports and the player report).
 *
 * ## A confirmed zero is not an absent value
 *
 * {@see arbitrate()} keys the fallback on whether the register holds a
 * figure at all, never on the figure being `0`. If a coach confirmed that a
 * player did not get on, that is a real 0 and it must not be overwritten by
 * the plan's twelve minutes — which would be the same class of wrong number
 * the confirm step was built to fix.
 *
 * ## No migration, no backfill
 *
 * A fixture completed before v4.135.0 has a register with no minutes on it.
 * The fallback covers it, which is cheaper and safer than rewriting
 * historical registers: nothing is lost if the plan changes meaning later,
 * and there is no one-way migration to regret.
 *
 * ## Planned is not played
 *
 * {@see plannedByActivity()} answers for **completed** fixtures only. The
 * surfaces keyed on the register report minutes that were played, and a
 * fixture still to come has none — handing them its plan would make planned
 * minutes read as played, which is #3713's rule. The player's tab, which
 * reports both and labels them apart, uses {@see forMatches()} instead.
 */
final class TournamentMinutesResolver {

    /** The figure a coach confirmed on the fixture's register. */
    public const SOURCE_REGISTER = 'register';

    /** The figure the rotation plan implies, where the register holds none. */
    public const SOURCE_PLAN = 'plan';

    /**
     * The arbitration, once.
     *
     * A register figure wins whenever there is one — including a confirmed
     * `0`. Only where the register holds nothing at all does the plan answer.
     * A player in neither is absent from the result rather than present at
     * zero: an unrecorded minute is blank, not nil (#4021's sibling rule).
     *
     * @param array<int, int|null> $register player id => the confirmed figure,
     *                                      or null where the register holds
     *                                      nothing for them.
     * @param array<int, int>      $planned  player id => the rotation plan's figure.
     * @return array<int, array{minutes:int, source:string}>
     */
    public static function arbitrate( array $register, array $planned ): array {
        $out = [];

        foreach ( $register as $player_id => $minutes ) {
            if ( $minutes === null ) continue;
            $out[ (int) $player_id ] = [
                'minutes' => (int) $minutes,
                'source'  => self::SOURCE_REGISTER,
            ];
        }

        foreach ( $planned as $player_id => $minutes ) {
            $player_id = (int) $player_id;
            if ( isset( $out[ $player_id ] ) ) continue;
            $out[ $player_id ] = [
                'minutes' => (int) $minutes,
                'source'  => self::SOURCE_PLAN,
            ];
        }

        return $out;
    }

    /**
     * Every player's minutes for the named fixtures, register-first.
     *
     * Each entry carries the arbitrated `minutes` and its `source`, both
     * inputs (`register` is null where there is none), and the rotation
     * plan's own reading of the fixture — `role`, `started`, `full` and
     * `positions` — which stays plan-derived on purpose: the coach's ticker
     * counts a planned start as a start, and the player file showing a
     * different number from the screen the coach was looking at is the drift
     * the parity constraint exists to prevent.
     *
     * @param list<int> $match_ids
     * @param int|null  $player_id Narrow the whole read to one player.
     * @return array<int, array<int, array{
     *     minutes:int, source:string, planned:int, register:int|null,
     *     periods_played:int, started:bool, full:bool, role:string,
     *     positions:list<string>
     * }>> match id => player id => …
     */
    public static function forMatches( array $match_ids, ?int $player_id = null ): array {
        $fixtures = self::fixtures( 'm.id', $match_ids );
        if ( $fixtures === [] ) return [];

        $plan     = self::planRows( array_keys( $fixtures ), $player_id );
        $register = self::registerRows( self::activityIds( $fixtures ), $player_id );

        $out = [];
        foreach ( $fixtures as $match_id => $fixture ) {
            $activity_id = (int) $fixture['activity_id'];
            $shape       = $fixture['shape'];

            /** @var array<int, array{minutes:int, periods_played:int, started:bool, full:bool, role:string, positions:list<string>}> $planned_rows */
            $planned_rows = [];
            foreach ( $plan[ $match_id ] ?? [] as $pid => $assignments ) {
                $planned_rows[ $pid ] = TournamentMinutesCalculator::forPlayer( $shape, $assignments );
            }

            $planned_minutes = [];
            foreach ( $planned_rows as $pid => $row ) {
                $planned_minutes[ $pid ] = (int) $row['minutes'];
            }

            $register_minutes = $activity_id > 0 ? ( $register[ $activity_id ] ?? [] ) : [];
            $resolved         = self::arbitrate( $register_minutes, $planned_minutes );

            $rows = [];
            foreach ( $resolved as $pid => $answer ) {
                $planned_row = $planned_rows[ $pid ] ?? null;
                $rows[ $pid ] = [
                    'minutes'        => $answer['minutes'],
                    'source'         => $answer['source'],
                    'planned'        => (int) ( $planned_minutes[ $pid ] ?? 0 ),
                    'register'       => isset( $register_minutes[ $pid ] ) ? (int) $register_minutes[ $pid ] : null,
                    'periods_played' => $planned_row !== null ? (int) $planned_row['periods_played'] : 0,
                    'started'        => $planned_row !== null && (bool) $planned_row['started'],
                    'full'           => $planned_row !== null && (bool) $planned_row['full'],
                    'role'           => self::role( $answer['minutes'], $planned_row ),
                    'positions'      => $planned_row !== null ? $planned_row['positions'] : [],
                ];
            }

            $out[ $match_id ] = $rows;
        }

        return $out;
    }

    /**
     * The rotation plan's figures for the **completed** fixtures among these
     * activities, for a caller that has already read the register itself and
     * needs only the fallback to hand to {@see arbitrate()}.
     *
     * An uncompleted fixture is deliberately absent: those surfaces report
     * what was played, and a fixture still to come has nothing to report.
     *
     * @param list<int> $activity_ids
     * @param int|null  $player_id
     * @return array<int, array<int, int>> activity id => player id => minutes
     */
    public static function plannedByActivity( array $activity_ids, ?int $player_id = null ): array {
        $fixtures = self::fixtures( 'm.activity_id', $activity_ids, true );
        if ( $fixtures === [] ) return [];

        $plan = self::planRows( array_keys( $fixtures ), $player_id );

        $out = [];
        foreach ( $fixtures as $match_id => $fixture ) {
            $activity_id = (int) $fixture['activity_id'];
            if ( $activity_id <= 0 ) continue;

            foreach ( $plan[ $match_id ] ?? [] as $pid => $assignments ) {
                $row = TournamentMinutesCalculator::forPlayer( $fixture['shape'], $assignments );
                // A day with two fixtures on the same activity is not a shape
                // the planner produces (one fixture, one activity), but adding
                // rather than overwriting is the safe reading if it ever is.
                $out[ $activity_id ][ $pid ] = (int) ( $out[ $activity_id ][ $pid ] ?? 0 ) + (int) $row['minutes'];
            }
        }

        return $out;
    }

    /**
     * One activity's effective minutes, register first, for a surface keyed on
     * the activity rather than on the fixture.
     *
     * `[]` for anything that is not a **completed** tournament fixture — an
     * ordinary match, a training, the tournament day, or a fixture still to
     * come — so a caller can tell "this is not mine to answer" from "nobody
     * played". Those surfaces report what was played, and handing them an
     * uncompleted fixture's plan would make planned minutes read as played
     * (#3713).
     *
     * A confirmed `0` is present and is `0`; a player in neither the register
     * nor the plan is absent.
     *
     * @return array<int, int> player id => minutes
     */
    public static function effectiveForActivity( int $activity_id ): array {
        if ( $activity_id <= 0 ) return [];

        $fixtures = self::fixtures( 'm.activity_id', [ $activity_id ], true );
        if ( $fixtures === [] ) return [];

        $plan     = self::planRows( array_keys( $fixtures ), null );
        $register = self::registerRows( [ $activity_id ], null )[ $activity_id ] ?? [];

        $planned = [];
        foreach ( $fixtures as $match_id => $fixture ) {
            foreach ( $plan[ $match_id ] ?? [] as $pid => $assignments ) {
                $row = TournamentMinutesCalculator::forPlayer( $fixture['shape'], $assignments );
                $planned[ $pid ] = (int) ( $planned[ $pid ] ?? 0 ) + (int) $row['minutes'];
            }
        }

        $out = [];
        foreach ( self::arbitrate( $register, $planned ) as $pid => $answer ) {
            $out[ $pid ] = $answer['minutes'];
        }

        return $out;
    }

    /**
     * How the fixture reads on the child's record, once the register has had
     * its say.
     *
     * `started` and `full` stay plan-derived — the coach's ticker counts a
     * planned start as a start and the two screens have to agree (the #3558
     * parity constraint) — but the **role** describes what happened. A player
     * the plan had on the bench whose register carries twelve minutes came on,
     * and "Bench · 12 of 20 min" on a child's record is simply wrong; a player
     * the plan started whom the coach confirmed at nil did not get on.
     *
     * @param array{minutes:int, periods_played:int, started:bool, full:bool, role:string, positions:list<string>}|null $planned_row
     */
    private static function role( int $minutes, ?array $planned_row ): string {
        if ( $minutes <= 0 ) return TournamentMinutesCalculator::ROLE_BENCH;

        $role = $planned_row !== null
            ? (string) $planned_row['role']
            : TournamentMinutesCalculator::ROLE_SUB;

        return $role === TournamentMinutesCalculator::ROLE_BENCH
            ? TournamentMinutesCalculator::ROLE_SUB
            : $role;
    }

    /**
     * Fixture rows keyed by match id, already divided into periods.
     *
     * @param list<int> $ids
     * @return array<int, array{activity_id:int, completed:bool, shape:array{duration:int, periods:int, per_period:int}}>
     */
    private static function fixtures( string $column, array $ids, bool $completed_only = false ): array {
        $ids = self::ints( $ids );
        if ( $ids === [] ) return [];

        global $wpdb;
        $p            = $wpdb->prefix;
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $params       = $ids;
        $params[]     = (int) CurrentClub::id();
        $gate         = $completed_only ? 'AND m.completed_at IS NOT NULL' : '';

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the column name is a class-local literal, the values are bound.
        $sql = "SELECT m.id, m.activity_id, m.duration_min, m.substitution_windows, m.completed_at
                  FROM {$p}tt_tournament_matches m
                 WHERE {$column} IN ($placeholders)
                   AND m.club_id = %d
                   {$gate}";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A );

        $out = [];
        foreach ( is_array( $rows ) ? $rows : [] as $row ) {
            $out[ (int) $row['id'] ] = [
                'activity_id' => isset( $row['activity_id'] ) ? (int) $row['activity_id'] : 0,
                'completed'   => ! empty( $row['completed_at'] ),
                'shape'       => TournamentMinutesCalculator::fixtureShape(
                    (int) $row['duration_min'],
                    (string) $row['substitution_windows']
                ),
            ];
        }

        return $out;
    }

    /**
     * Assignment rows grouped by fixture and player — the rotation plan, read
     * here so no reporting surface has to read `tt_tournament_assignments`
     * for minutes itself.
     *
     * @param list<int> $match_ids
     * @return array<int, array<int, list<array{period_index:int, position_code:string}>>>
     */
    private static function planRows( array $match_ids, ?int $player_id ): array {
        $match_ids = self::ints( $match_ids );
        if ( $match_ids === [] ) return [];

        global $wpdb;
        $p            = $wpdb->prefix;
        $placeholders = implode( ',', array_fill( 0, count( $match_ids ), '%d' ) );
        $params       = $match_ids;
        $params[]     = (int) CurrentClub::id();
        $filter       = '';
        if ( $player_id !== null && $player_id > 0 ) {
            $filter   = 'AND a.player_id = %d';
            $params[] = (int) $player_id;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders are generated, values are bound below.
        $sql = "SELECT a.match_id, a.player_id, a.period_index, a.position_code
                  FROM {$p}tt_tournament_assignments a
                 WHERE a.match_id IN ($placeholders)
                   AND a.club_id = %d
                   {$filter}";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A );

        $out = [];
        foreach ( is_array( $rows ) ? $rows : [] as $row ) {
            $out[ (int) $row['match_id'] ][ (int) $row['player_id'] ][] = [
                'period_index'  => (int) $row['period_index'],
                'position_code' => (string) $row['position_code'],
            ];
        }

        return $out;
    }

    /**
     * The register, keyed activity => player => figure-or-null.
     *
     * `null` is the whole point: a row that exists with no minutes on it is a
     * fixture completed before the confirm step shipped, and only that state
     * may fall back to the plan. Effective minutes are
     * `COALESCE(minutes_override, minutes_played)`, the same reading the
     * minutes grid and the minutes reports use, so a coach's later correction
     * on the grid is what the player's record shows too.
     *
     * @param list<int> $activity_ids
     * @return array<int, array<int, int|null>>
     */
    private static function registerRows( array $activity_ids, ?int $player_id ): array {
        $activity_ids = self::ints( $activity_ids );
        if ( $activity_ids === [] ) return [];

        global $wpdb;
        $p            = $wpdb->prefix;
        $placeholders = implode( ',', array_fill( 0, count( $activity_ids ), '%d' ) );
        $params       = $activity_ids;
        $params[]     = (int) CurrentClub::id();
        $filter       = '';
        if ( $player_id !== null && $player_id > 0 ) {
            $filter   = 'AND player_id = %d';
            $params[] = (int) $player_id;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders are generated, values are bound below.
        $sql = "SELECT activity_id, player_id,
                       MAX( COALESCE( minutes_override, minutes_played ) ) AS minutes
                  FROM {$p}tt_attendance
                 WHERE activity_id IN ($placeholders)
                   AND club_id = %d
                   AND is_guest = 0
                   AND record_type = 'actual'
                   AND player_id > 0
                   {$filter}
              GROUP BY activity_id, player_id";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A );

        $out = [];
        foreach ( is_array( $rows ) ? $rows : [] as $row ) {
            $out[ (int) $row['activity_id'] ][ (int) $row['player_id'] ] =
                $row['minutes'] !== null ? (int) $row['minutes'] : null;
        }

        return $out;
    }

    /**
     * @param array<int, array{activity_id:int, completed:bool, shape:array{duration:int, periods:int, per_period:int}}> $fixtures
     * @return list<int>
     */
    private static function activityIds( array $fixtures ): array {
        $out = [];
        foreach ( $fixtures as $fixture ) {
            $activity_id = (int) $fixture['activity_id'];
            if ( $activity_id > 0 ) $out[ $activity_id ] = true;
        }
        return array_keys( $out );
    }

    /**
     * @param array<int|string, mixed> $ids
     * @return list<int>
     */
    private static function ints( array $ids ): array {
        $out = [];
        foreach ( $ids as $id ) {
            $id = (int) $id;
            if ( $id > 0 ) $out[ $id ] = true;
        }
        return array_keys( $out );
    }
}
