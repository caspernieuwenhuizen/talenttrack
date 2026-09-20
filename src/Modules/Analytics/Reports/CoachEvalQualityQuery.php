<?php
namespace TT\Modules\Analytics\Reports;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Analytics\EvalCoverageService;

/**
 * CoachEvalQualityQuery (#1367) — per-coach rating distribution /
 * variance stats, the HoD's "rate-everyone-a-6 coach" spot-check
 * (docs/head-of-development-actions.md #5) as a queryable report.
 *
 * Per coach (rows): evaluation count, rating count, mean rating,
 * population standard deviation, the modal rating value + the share
 * of all ratings sitting at it, the last-evaluation date, and the
 * squad side of the same question — squad size, how many of them were
 * evaluated in the window, how many have not been evaluated at all
 * this season, and how long ago the coach last evaluated anyone.
 * Filterable by team + date range.
 *
 * #3809 — the row set starts from the coaches who hold a team, not
 * from the evaluations. Grouping `tt_evaluations` by `coach_id` can
 * only ever list coaches who wrote something, and the coach the head
 * of development needs to ring is precisely the one who wrote nothing:
 * a new U13 coach with an empty October had no row at all. Coaches are
 * resolved through {@see EvalCoverageService::headCoaches()}, the same
 * team-staff path the evaluation-coverage report uses, so the two
 * reports cannot disagree about who a team's coach is. A coach who
 * wrote evaluations without holding a team still gets their row.
 *
 * The rating join is an OUTER join for the same reason: evaluation
 * counts and rating statistics are separate facts, and a coach with
 * evaluations but no rating rows should show their count with empty
 * statistics rather than vanish.
 *
 * Low-variance flag: σ < LOW_VARIANCE_THRESHOLD *and* at least
 * MIN_RATINGS_FOR_FLAG rating rows — a coach with three ratings has
 * no meaningful variance yet and shouldn't be flagged.
 *
 * Consumed by both `FrontendStandardReportsView` (the
 * coach-evaluation-quality standard report incl. its CSV stream) and
 * `ReportsRestController` (`GET /reports/coach-evaluation-quality`)
 * so a SaaS frontend gets identical answers — CLAUDE.md §4.
 *
 * The mode is computed in PHP from a per-coach × per-rating-value
 * frequency query rather than SQL window functions — the WP install
 * floor (MySQL 5.6) predates ROW_NUMBER().
 *
 * @phpstan-type CoachQualityRow array{
 *     coach_id:int, coach_name:string,
 *     eval_count:int, rating_count:int,
 *     mean_rating:?float, stddev:?float,
 *     modal_value:?float, modal_pct:?float,
 *     last_eval_date:?string, low_variance:bool,
 *     squad_size:int, players_evaluated:int,
 *     players_never_evaluated:int, days_since_last_eval:?int
 * }
 */
final class CoachEvalQualityQuery {

    public const LOW_VARIANCE_THRESHOLD = 0.5;
    public const MIN_RATINGS_FOR_FLAG   = 10;

    private EvalCoverageService $coverage;

    public function __construct( ?EvalCoverageService $coverage = null ) {
        $this->coverage = $coverage ?? new EvalCoverageService();
    }

    /**
     * The report: the window that was applied, plus a row per coach.
     *
     * #3809 — the window is echoed the way the attendance reports echo
     * theirs. It resolves to the current-season window when neither
     * bound is supplied, and a reader who cannot see which period the
     * numbers describe cannot act on them.
     *
     * @param array{team_id?:int, from?:string, to?:string, date_from?:string, date_to?:string} $filters
     *        Dates as `Y-m-d`, under either spelling; team filter joins
     *        the player's team.
     * @return array{from:string, to:string, rows:list<CoachQualityRow>}
     */
    public function report( array $filters = [] ): array {
        $team_id = (int) ( $filters['team_id'] ?? 0 );
        [ $from, $to ] = self::window( $filters );
        $season = ReportFilters::seasonDefaultWindow();

        $stats  = $this->evaluationStats( $team_id, $from, $to );
        $mode   = $this->modalRatings( $team_id, $from, $to );
        $latest = $this->lastEvaluationPerCoach( $team_id );
        $squads = $this->squadStats( $team_id, $from, $to, $season['from'], $season['to'] );
        $teams  = $this->coverage->headCoaches();

        // The coaches to report on: everyone holding a team, plus anyone
        // who evaluated inside the window. A team without a head coach
        // contributes no row — its gaps are the evaluation-coverage
        // report's "Unassigned" bucket, not a nameless row here.
        /** @var array<int,string> $names */
        $names = [];
        /** @var array<int,int> $squad_size */
        $squad_size = [];
        /** @var array<int,int> $evaluated */
        $evaluated = [];
        /** @var array<int,int> $never */
        $never = [];
        foreach ( $teams as $team => $coach ) {
            $cid = $coach['coach_id'];
            if ( $cid <= 0 ) continue;
            if ( $team_id > 0 && $team !== $team_id ) continue;
            if ( ( $names[ $cid ] ?? '' ) === '' ) $names[ $cid ] = $coach['coach_name'];
            $squad = $squads[ $team ] ?? [ 'squad' => 0, 'evaluated' => 0, 'never' => 0 ];
            $squad_size[ $cid ] = ( $squad_size[ $cid ] ?? 0 ) + $squad['squad'];
            $evaluated[ $cid ]  = ( $evaluated[ $cid ] ?? 0 )  + $squad['evaluated'];
            $never[ $cid ]      = ( $never[ $cid ] ?? 0 )      + $squad['never'];
        }
        foreach ( $stats as $cid => $stat ) {
            if ( ( $names[ $cid ] ?? '' ) === '' ) $names[ $cid ] = $stat['coach_name'];
        }

        $today = gmdate( 'Y-m-d' );
        $rows  = [];
        foreach ( array_keys( $names ) as $cid ) {
            $stat         = $stats[ $cid ] ?? null;
            $rating_count = $stat !== null ? $stat['rating_count'] : 0;
            $stddev       = $stat !== null && $stat['stddev'] !== null ? round( $stat['stddev'], 2 ) : null;
            $modal_value  = isset( $mode[ $cid ] ) ? $mode[ $cid ][0] : null;
            $modal_pct    = ( isset( $mode[ $cid ] ) && $rating_count > 0 )
                ? round( ( $mode[ $cid ][1] / $rating_count ) * 100, 1 )
                : null;
            $rows[] = [
                'coach_id'                => $cid,
                'coach_name'              => $names[ $cid ] !== '' ? $names[ $cid ] : sprintf( '#%d', $cid ),
                'eval_count'              => $stat !== null ? $stat['eval_count'] : 0,
                'rating_count'            => $rating_count,
                'mean_rating'             => $stat !== null && $stat['mean_rating'] !== null ? round( $stat['mean_rating'], 2 ) : null,
                'stddev'                  => $stddev,
                'modal_value'             => $modal_value,
                'modal_pct'               => $modal_pct,
                'last_eval_date'          => $stat !== null ? $stat['last_eval_date'] : null,
                'low_variance'            => $stddev !== null
                    && $stddev < self::LOW_VARIANCE_THRESHOLD
                    && $rating_count >= self::MIN_RATINGS_FOR_FLAG,
                'squad_size'              => $squad_size[ $cid ] ?? 0,
                'players_evaluated'       => $evaluated[ $cid ] ?? 0,
                'players_never_evaluated' => $never[ $cid ] ?? 0,
                // Days since this coach last evaluated ANYONE, window or
                // not. Bounded by the window it would be null for exactly
                // the coach the report exists to surface.
                'days_since_last_eval'    => isset( $latest[ $cid ] )
                    ? self::daysBetween( $latest[ $cid ], $today )
                    : null,
            ];
        }

        usort( $rows, static function ( array $a, array $b ): int {
            return [ $b['eval_count'], $a['coach_name'] ] <=> [ $a['eval_count'], $b['coach_name'] ];
        } );

        return [ 'from' => $from, 'to' => $to, 'rows' => array_values( $rows ) ];
    }

    /**
     * The rows alone, for callers that don't need the window.
     *
     * @param array{team_id?:int, from?:string, to?:string, date_from?:string, date_to?:string} $filters
     * @return list<CoachQualityRow>
     */
    public function rows( array $filters = [] ): array {
        return $this->report( $filters )['rows'];
    }

    /**
     * `from` / `to` are the declared names; `date_from` / `date_to` are
     * the accepted aliases (#3809). A bound that isn't a date falls back
     * to the current-season window, which is what the report echoes.
     *
     * @param array{team_id?:int, from?:string, to?:string, date_from?:string, date_to?:string} $filters
     * @return array{0:string,1:string}
     */
    private static function window( array $filters ): array {
        $default = ReportFilters::seasonDefaultWindow();
        $from    = (string) ( $filters['from'] ?? '' );
        $to      = (string) ( $filters['to'] ?? '' );
        if ( $from === '' ) $from = (string) ( $filters['date_from'] ?? '' );
        if ( $to === '' )   $to   = (string) ( $filters['date_to'] ?? '' );
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) ) $from = $default['from'];
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) )   $to   = $default['to'];
        return [ $from, $to ];
    }

    private static function daysBetween( string $date, string $today ): ?int {
        $then = strtotime( $date . ' 00:00:00 UTC' );
        $now  = strtotime( $today . ' 00:00:00 UTC' );
        if ( $then === false || $now === false ) return null;
        return (int) max( 0, floor( ( $now - $then ) / DAY_IN_SECONDS ) );
    }

    /**
     * The WHERE + JOIN an evaluation read shares across the three
     * statistics queries below.
     *
     * @return array{join:string, where:string, params:list<int|string>}
     */
    private function evaluationScope( int $team_id, string $from, string $to ): array {
        global $wpdb;
        $where  = [ 'e.archived_at IS NULL', '( e.club_id = %d OR e.club_id IS NULL )' ];
        $params = [ (int) CurrentClub::id() ];
        $join   = '';

        if ( $team_id > 0 ) {
            // Team scope rides the player's CURRENT team — evaluations
            // don't snapshot the team at write time.
            $join     = " JOIN {$wpdb->prefix}tt_players pl ON pl.id = e.player_id";
            $where[]  = 'pl.team_id = %d';
            $params[] = $team_id;
        }
        if ( $from !== '' ) {
            $where[]  = 'e.eval_date >= %s';
            $params[] = $from;
        }
        if ( $to !== '' ) {
            $where[]  = 'e.eval_date <= %s';
            $params[] = $to;
        }

        return [ 'join' => $join, 'where' => implode( ' AND ', $where ), 'params' => $params ];
    }

    /**
     * Per-coach evaluation + rating statistics inside the window.
     *
     * @return array<int,array{coach_name:string, eval_count:int, rating_count:int, mean_rating:?float, stddev:?float, last_eval_date:?string}>
     */
    private function evaluationStats( int $team_id, string $from, string $to ): array {
        global $wpdb;
        $p     = $wpdb->prefix;
        $scope = $this->evaluationScope( $team_id, $from, $to );

        /** @var list<object> $agg */
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $agg = $wpdb->get_results( $wpdb->prepare(
            "SELECT e.coach_id,
                    u.display_name AS coach_name,
                    COUNT(DISTINCT e.id)   AS eval_count,
                    COUNT(r.id)            AS rating_count,
                    AVG(r.rating)          AS mean_rating,
                    STDDEV_POP(r.rating)   AS stddev,
                    MAX(e.eval_date)       AS last_eval_date
               FROM {$p}tt_evaluations e
               LEFT JOIN {$p}tt_eval_ratings r ON r.evaluation_id = e.id
               LEFT JOIN {$wpdb->users} u ON u.ID = e.coach_id
               {$scope['join']}
              WHERE {$scope['where']}
              GROUP BY e.coach_id, u.display_name",
            ...$scope['params']
        ) );

        $out = [];
        foreach ( is_array( $agg ) ? $agg : [] as $a ) {
            $cid = (int) ( $a->coach_id ?? 0 );
            if ( $cid <= 0 ) continue;
            $out[ $cid ] = [
                'coach_name'     => (string) ( $a->coach_name ?? '' ),
                'eval_count'     => (int) ( $a->eval_count ?? 0 ),
                'rating_count'   => (int) ( $a->rating_count ?? 0 ),
                'mean_rating'    => $a->mean_rating !== null ? (float) $a->mean_rating : null,
                'stddev'         => $a->stddev !== null ? (float) $a->stddev : null,
                'last_eval_date' => $a->last_eval_date !== null ? (string) $a->last_eval_date : null,
            ];
        }
        return $out;
    }

    /**
     * Frequency table per (coach, rating value) → the PHP-side mode.
     * Inner join on purpose: no rating rows means no most-given rating.
     *
     * @return array<int,array{0:float,1:int}>
     */
    private function modalRatings( int $team_id, string $from, string $to ): array {
        global $wpdb;
        $p     = $wpdb->prefix;
        $scope = $this->evaluationScope( $team_id, $from, $to );

        /** @var list<object> $freq */
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $freq = $wpdb->get_results( $wpdb->prepare(
            "SELECT e.coach_id, r.rating, COUNT(*) AS n
               FROM {$p}tt_evaluations e
               JOIN {$p}tt_eval_ratings r ON r.evaluation_id = e.id
               {$scope['join']}
              WHERE {$scope['where']}
              GROUP BY e.coach_id, r.rating",
            ...$scope['params']
        ) );

        $mode = [];
        foreach ( is_array( $freq ) ? $freq : [] as $f ) {
            $cid = (int) ( $f->coach_id ?? 0 );
            $n   = (int) ( $f->n ?? 0 );
            if ( $cid <= 0 ) continue;
            if ( ! isset( $mode[ $cid ] ) || $n > $mode[ $cid ][1] ) {
                $mode[ $cid ] = [ (float) ( $f->rating ?? 0 ), $n ];
            }
        }
        return $mode;
    }

    /**
     * Each coach's most recent evaluation, unbounded by the window —
     * "last evaluated 52 days ago" is the number the chase runs on, and
     * a window-bounded one is null for every coach worth chasing.
     *
     * @return array<int,string>
     */
    private function lastEvaluationPerCoach( int $team_id ): array {
        global $wpdb;
        $p     = $wpdb->prefix;
        $scope = $this->evaluationScope( $team_id, '', '' );

        /** @var list<object> $rows */
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT e.coach_id, MAX(e.eval_date) AS last_eval_date
               FROM {$p}tt_evaluations e
               {$scope['join']}
              WHERE {$scope['where']}
              GROUP BY e.coach_id",
            ...$scope['params']
        ) );

        $out = [];
        foreach ( is_array( $rows ) ? $rows : [] as $row ) {
            $cid  = (int) ( $row->coach_id ?? 0 );
            $date = (string) ( $row->last_eval_date ?? '' );
            if ( $cid <= 0 || $date === '' ) continue;
            $out[ $cid ] = $date;
        }
        return $out;
    }

    /**
     * Per team: squad size, how many of the squad were evaluated inside
     * the window, and how many have not been evaluated at all this
     * season. Same squad definition as the coverage report — active,
     * unarchived players currently on the team.
     *
     * @return array<int,array{squad:int, evaluated:int, never:int}>
     */
    private function squadStats( int $team_id, string $from, string $to, string $season_from, string $season_to ): array {
        global $wpdb;
        $p       = $wpdb->prefix;
        $params  = [ $from, $to, $season_from, $season_to, (int) CurrentClub::id() ];
        $narrow  = '';
        if ( $team_id > 0 ) {
            $narrow   = ' AND p.team_id = %d';
            $params[] = $team_id;
        }

        /** @var list<object> $rows */
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.team_id,
                    COUNT(DISTINCT p.id) AS squad,
                    COUNT(DISTINCT CASE WHEN e.eval_date BETWEEN %s AND %s THEN p.id END) AS evaluated,
                    COUNT(DISTINCT CASE WHEN e.eval_date BETWEEN %s AND %s THEN p.id END) AS in_season
               FROM {$p}tt_players p
               LEFT JOIN {$p}tt_evaluations e
                 ON e.player_id = p.id
                AND e.archived_at IS NULL
                AND ( e.club_id = p.club_id OR e.club_id IS NULL )
              WHERE p.club_id = %d
                AND p.status = 'active'
                AND p.archived_at IS NULL
                AND p.team_id > 0{$narrow}
              GROUP BY p.team_id",
            ...$params
        ) );

        $out = [];
        foreach ( is_array( $rows ) ? $rows : [] as $row ) {
            $team  = (int) ( $row->team_id ?? 0 );
            $squad = (int) ( $row->squad ?? 0 );
            if ( $team <= 0 ) continue;
            $out[ $team ] = [
                'squad'     => $squad,
                'evaluated' => (int) ( $row->evaluated ?? 0 ),
                'never'     => max( 0, $squad - (int) ( $row->in_season ?? 0 ) ),
            ];
        }
        return $out;
    }
}
