<?php
namespace TT\Modules\Analytics\Reports;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * CoachEvalQualityQuery (#1367) — per-coach rating distribution /
 * variance stats, the HoD's "rate-everyone-a-6 coach" spot-check
 * (docs/head-of-development-actions.md #5) as a queryable report.
 *
 * Per coach (rows): evaluation count, rating count, mean rating,
 * population standard deviation, the modal rating value + the share
 * of all ratings sitting at it, and the last-evaluation date.
 * Filterable by team + date range.
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
 */
final class CoachEvalQualityQuery {

    public const LOW_VARIANCE_THRESHOLD = 0.5;
    public const MIN_RATINGS_FOR_FLAG   = 10;

    /**
     * @param array{team_id?: int, date_from?: string, date_to?: string} $filters
     *        Dates as `Y-m-d`; team filter joins the player's team.
     * @return list<array{
     *     coach_id:int, coach_name:string,
     *     eval_count:int, rating_count:int,
     *     mean_rating:?float, stddev:?float,
     *     modal_value:?float, modal_pct:?float,
     *     last_eval_date:?string, low_variance:bool
     * }>
     */
    public function rows( array $filters = [] ): array {
        global $wpdb;
        $p       = $wpdb->prefix;
        $club_id = (int) CurrentClub::id();

        $where  = [ 'e.archived_at IS NULL', '( e.club_id = %d OR e.club_id IS NULL )' ];
        $params = [ $club_id ];
        $join   = '';

        $team_id = (int) ( $filters['team_id'] ?? 0 );
        if ( $team_id > 0 ) {
            // Team scope rides the player's CURRENT team — evaluations
            // don't snapshot the team at write time.
            $join     = " JOIN {$p}tt_players pl ON pl.id = e.player_id";
            $where[]  = 'pl.team_id = %d';
            $params[] = $team_id;
        }
        if ( ! empty( $filters['date_from'] ) ) {
            $where[]  = 'e.eval_date >= %s';
            $params[] = (string) $filters['date_from'];
        }
        if ( ! empty( $filters['date_to'] ) ) {
            $where[]  = 'e.eval_date <= %s';
            $params[] = (string) $filters['date_to'];
        }
        $where_sql = implode( ' AND ', $where );

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
               JOIN {$p}tt_eval_ratings r ON r.evaluation_id = e.id
               LEFT JOIN {$wpdb->users} u ON u.ID = e.coach_id
               {$join}
              WHERE {$where_sql}
              GROUP BY e.coach_id, u.display_name
              ORDER BY eval_count DESC, coach_name ASC",
            ...$params
        ) );
        if ( ! is_array( $agg ) || empty( $agg ) ) return [];

        // Frequency table per (coach, rating value) → PHP-side mode.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $freq = $wpdb->get_results( $wpdb->prepare(
            "SELECT e.coach_id, r.rating, COUNT(*) AS n
               FROM {$p}tt_evaluations e
               JOIN {$p}tt_eval_ratings r ON r.evaluation_id = e.id
               {$join}
              WHERE {$where_sql}
              GROUP BY e.coach_id, r.rating",
            ...$params
        ) );
        $mode = []; // coach_id => [value, count]
        foreach ( (array) $freq as $f ) {
            $cid = (int) $f->coach_id;
            $n   = (int) $f->n;
            if ( ! isset( $mode[ $cid ] ) || $n > $mode[ $cid ][1] ) {
                $mode[ $cid ] = [ (float) $f->rating, $n ];
            }
        }

        $rows = [];
        foreach ( $agg as $a ) {
            $cid          = (int) $a->coach_id;
            $rating_count = (int) $a->rating_count;
            $stddev       = $a->stddev !== null ? round( (float) $a->stddev, 2 ) : null;
            $modal_value  = isset( $mode[ $cid ] ) ? $mode[ $cid ][0] : null;
            $modal_pct    = ( isset( $mode[ $cid ] ) && $rating_count > 0 )
                ? round( ( $mode[ $cid ][1] / $rating_count ) * 100, 1 )
                : null;
            $rows[] = [
                'coach_id'       => $cid,
                'coach_name'     => (string) ( $a->coach_name ?? '' ) !== ''
                    ? (string) $a->coach_name
                    : sprintf( '#%d', $cid ),
                'eval_count'     => (int) $a->eval_count,
                'rating_count'   => $rating_count,
                'mean_rating'    => $a->mean_rating !== null ? round( (float) $a->mean_rating, 2 ) : null,
                'stddev'         => $stddev,
                'modal_value'    => $modal_value,
                'modal_pct'      => $modal_pct,
                'last_eval_date' => $a->last_eval_date !== null ? (string) $a->last_eval_date : null,
                'low_variance'   => $stddev !== null
                    && $stddev < self::LOW_VARIANCE_THRESHOLD
                    && $rating_count >= self::MIN_RATINGS_FOR_FLAG,
            ];
        }
        return $rows;
    }
}
