<?php
namespace TT\Infrastructure\Stats;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Evaluations\EvalCategoriesRepository;
use TT\Infrastructure\Evaluations\EvalRatingsRepository;

/**
 * PlayerStatsService — rate-card analytics for a single player.
 *
 * Sprint 2A (v2.14.0). Composes data that already exists:
 *   - tt_evaluations + tt_eval_ratings (the raw rating rows)
 *   - EvalRatingsRepository::overallRating / effectiveMainRating
 *     (the rollup logic we built in Epic 3)
 *
 * Adds aggregation across multiple evaluations to produce headline
 * numbers, per-main trends, per-sub breakdowns, and time-series suited
 * to the line + radar charts on the rate card.
 *
 * All methods compute on read — no caching, no stored aggregates. At
 * realistic club scale (hundreds of evaluations per player max) this
 * is trivially fast. If the scale ever gets interesting we batch.
 *
 * Filter shape (every public method accepts it):
 *   [
 *     'date_from'     => 'YYYY-MM-DD' | '',
 *     'date_to'       => 'YYYY-MM-DD' | '',
 *     'eval_type_id'  => int | 0,
 *   ]
 */
class PlayerStatsService {

    private EvalRatingsRepository $ratings_repo;
    private EvalCategoriesRepository $cats_repo;

    /**
     * Per-instance read memo for getEvaluationsForPlayer(). A rate card, a
     * podium card or a player report asks this service two or three
     * questions about the same player in a row, and each one re-ran the
     * identical evaluation query (#3702). The service is compute-on-read
     * and every caller builds a fresh instance for one render pass, so the
     * memo lives exactly as long as that pass. Keyed by player + filters,
     * because the comparison and overview views reuse one instance across
     * several players.
     *
     * @var array<string, object[]>
     */
    private array $evals_memo = [];

    public function __construct() {
        $this->ratings_repo = new EvalRatingsRepository();
        $this->cats_repo    = new EvalCategoriesRepository();
    }

    // Raw evaluations

    /**
     * Evaluations matching the filters, oldest first (so time-series
     * naturally progress left-to-right on a chart).
     *
     * @return object[]
     */
    public function getEvaluationsForPlayer( int $player_id, array $filters = [] ): array {
        global $wpdb;
        $p = $wpdb->prefix;

        // #1469 — archived evaluations must never contribute to a
        // player's displayed rating/score. The aggregate report paths
        // already exclude them; this rate-card path was the leak.
        $clauses = [ 'player_id = %d', 'archived_at IS NULL' ];
        $args    = [ $player_id ];

        $date_from = $filters['date_from'] ?? '';
        $date_to   = $filters['date_to']   ?? '';
        $type_id   = (int) ( $filters['eval_type_id'] ?? 0 );

        $memo_key = $player_id . '|' . (string) $date_from . '|' . (string) $date_to . '|' . $type_id;
        if ( isset( $this->evals_memo[ $memo_key ] ) ) {
            return $this->evals_memo[ $memo_key ];
        }

        if ( $date_from !== '' ) { $clauses[] = 'eval_date >= %s'; $args[] = $date_from; }
        if ( $date_to   !== '' ) { $clauses[] = 'eval_date <= %s'; $args[] = $date_to;   }
        if ( $type_id   > 0    ) { $clauses[] = 'eval_type_id = %d'; $args[] = $type_id; }

        $where = implode( ' AND ', $clauses );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, eval_date, eval_type_id, notes
             FROM {$p}tt_evaluations
             WHERE {$where}
             ORDER BY eval_date ASC, id ASC",
            ...$args
        ) );
        $this->evals_memo[ $memo_key ] = is_array( $rows ) ? $rows : [];
        return $this->evals_memo[ $memo_key ];
    }

    // Headline numbers

    /**
     * The three summary numbers at the top of the rate card.
     *
     * @return array{
     *   latest: ?float, latest_date: ?string,
     *   rolling: ?float, rolling_count: int,
     *   alltime: ?float, alltime_count: int,
     *   eval_count: int
     * }
     */
    public function getHeadlineNumbers( int $player_id, array $filters = [], int $rolling_n = 5 ): array {
        $evals = $this->getEvaluationsForPlayer( $player_id, $filters );
        $count = count( $evals );

        if ( $count === 0 ) {
            return [
                'latest'        => null, 'latest_date' => null,
                'rolling'       => null, 'rolling_count' => 0,
                'alltime'       => null, 'alltime_count' => 0,
                'eval_count'    => 0,
            ];
        }

        // Overall per evaluation, batched.
        $eval_ids  = array_map( fn( $e ) => (int) $e->id, $evals );
        $overalls  = $this->ratings_repo->overallRatingsForEvaluations( $eval_ids );

        // Build a values list, chronological (evals are already ASC).
        $values = [];
        $dates  = [];
        foreach ( $evals as $ev ) {
            $eid = (int) $ev->id;
            $v   = $overalls[ $eid ]['value'] ?? null;
            if ( $v !== null ) {
                $values[] = (float) $v;
                $dates[]  = (string) $ev->eval_date;
            }
        }
        $rated_count = count( $values );

        if ( $rated_count === 0 ) {
            return [
                'latest'        => null, 'latest_date' => null,
                'rolling'       => null, 'rolling_count' => 0,
                'alltime'       => null, 'alltime_count' => 0,
                'eval_count'    => $count,
            ];
        }

        $latest       = (float) end( $values );
        $latest_date  = (string) end( $dates );
        $alltime_mean = round( array_sum( $values ) / $rated_count, 1 );

        // Rolling — last N (or fewer if not enough).
        $rolling_slice = array_slice( $values, - $rolling_n );
        $rolling_mean  = round( array_sum( $rolling_slice ) / count( $rolling_slice ), 1 );

        return [
            'latest'        => $latest,
            'latest_date'   => $latest_date,
            'rolling'       => $rolling_mean,
            'rolling_count' => count( $rolling_slice ),
            'alltime'       => $alltime_mean,
            'alltime_count' => $rated_count,
            'eval_count'    => $count,
        ];
    }

    // Main category breakdown

    /**
     * Per-main-category all-time mean, most recent value, and trend
     * direction. Trend compares the older half of the filtered
     * evaluations against the newer half — a simple, defensible signal
     * that avoids over-fitting to a single noisy evaluation.
     *
     * @return array<int, array{
     *   main_id: int, label: string,
     *   alltime: ?float, alltime_count: int,
     *   latest: ?float, latest_date: ?string,
     *   trend: 'up'|'down'|'flat'|'insufficient',
     *   older_mean: ?float, newer_mean: ?float
     * }>
     */
    public function getMainCategoryBreakdown( int $player_id, array $filters = [] ): array {
        $evals = $this->getEvaluationsForPlayer( $player_id, $filters );
        $mains = $this->cats_repo->getMainCategories( true );

        $out = [];
        foreach ( $mains as $m ) {
            $mid = (int) $m->id;
            $out[ $mid ] = [
                'main_id'       => $mid,
                'label'         => (string) $m->label,
                'alltime'       => null,
                'alltime_count' => 0,
                'latest'        => null,
                'latest_date'   => null,
                'trend'         => 'insufficient',
                'older_mean'    => null,
                'newer_mean'    => null,
            ];
        }

        if ( empty( $evals ) ) return $out;

        // Gather per-eval effective-main rating for each main category.
        // Lists are maintained in chronological order (evals are ASC).
        $series = []; // main_id => [ [date, value], ... ]
        foreach ( $mains as $m ) $series[ (int) $m->id ] = [];

        // #3702 — one batched lookup for the whole history. This loop used
        // to call effectiveMainRatingsFor() per evaluation, which is one
        // category query plus one or two rating queries per main category,
        // per evaluation. A player with 200 evaluations cost well over a
        // thousand roundtrips, and the podium renders twelve of these cards.
        $eval_ids   = [];
        $eval_dates = []; // eval_id => eval_date
        foreach ( $evals as $ev ) {
            $eid                = (int) $ev->id;
            $eval_ids[]         = $eid;
            $eval_dates[ $eid ] = (string) $ev->eval_date;
        }
        $effective_by_eval = $this->ratings_repo->effectiveMainRatingsForEvaluations( $eval_ids );

        foreach ( $eval_ids as $eid ) {
            $effective = $effective_by_eval[ $eid ] ?? [];
            foreach ( $mains as $m ) {
                $mid = (int) $m->id;
                $v   = $effective[ $mid ]['value'] ?? null;
                if ( $v !== null ) {
                    $series[ $mid ][] = [ $eval_dates[ $eid ], (float) $v ];
                }
            }
        }

        foreach ( $mains as $m ) {
            $mid  = (int) $m->id;
            $pts  = $series[ $mid ];
            $n    = count( $pts );
            if ( $n === 0 ) continue;

            $values = array_map( fn( $p ) => $p[1], $pts );
            $out[ $mid ]['alltime']       = round( array_sum( $values ) / $n, 1 );
            $out[ $mid ]['alltime_count'] = $n;
            $out[ $mid ]['latest']        = (float) end( $values );
            $out[ $mid ]['latest_date']   = (string) $pts[ $n - 1 ][0];

            // Trend: split in half, compare means.
            if ( $n < 2 ) {
                $out[ $mid ]['trend'] = 'insufficient';
                continue;
            }
            $mid_idx = intdiv( $n, 2 );
            $older   = array_slice( $values, 0, $mid_idx );
            $newer   = array_slice( $values, $mid_idx );
            if ( empty( $older ) || empty( $newer ) ) {
                $out[ $mid ]['trend'] = 'insufficient';
                continue;
            }
            $om = array_sum( $older ) / count( $older );
            $nm = array_sum( $newer ) / count( $newer );
            $out[ $mid ]['older_mean'] = round( $om, 2 );
            $out[ $mid ]['newer_mean'] = round( $nm, 2 );

            // Dead zone: 0.15 absolute difference. Below that we call it flat
            // — single-rating noise can easily move a player's mean by 0.1.
            $delta = $nm - $om;
            if ( abs( $delta ) < 0.15 ) {
                $out[ $mid ]['trend'] = 'flat';
            } else {
                $out[ $mid ]['trend'] = $delta > 0 ? 'up' : 'down';
            }
        }
        return $out;
    }

    // Subcategory breakdown

    /**
     * All-time mean per subcategory, grouped under parent main.
     * Only subcategories that actually have ratings for this player
     * under the filter appear — silent ones (never rated) are omitted.
     *
     * @return array<int, array{
     *   main_id: int, main_label: string,
     *   subs: array<int, array{sub_id:int, label:string, mean:float, count:int}>
     * }>  Keyed by main_id.
     */
    public function getSubcategoryBreakdown( int $player_id, array $filters = [] ): array {
        global $wpdb;
        $p = $wpdb->prefix;

        $evals = $this->getEvaluationsForPlayer( $player_id, $filters );
        $out   = [];
        if ( empty( $evals ) ) return $out;

        $mains = $this->cats_repo->getMainCategories( true );
        foreach ( $mains as $m ) {
            $out[ (int) $m->id ] = [
                'main_id'    => (int) $m->id,
                'main_label' => (string) $m->label,
                'subs'       => [],
            ];
        }

        $eval_ids = array_map( fn( $e ) => (int) $e->id, $evals );
        $placeholders = implode( ',', array_fill( 0, count( $eval_ids ), '%d' ) );

        // Pull rating rows + category metadata. parent_id IS NOT NULL filters to subs.
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT r.rating, c.id AS cid, c.parent_id, c.label
             FROM {$p}tt_eval_ratings r
             INNER JOIN {$p}tt_eval_categories c ON r.category_id = c.id
             WHERE r.evaluation_id IN ($placeholders) AND c.parent_id IS NOT NULL",
            ...$eval_ids
        ) );

        $acc = []; // parent_id => sub_id => [sum, count, label]
        foreach ( (array) $rows as $r ) {
            $pid   = (int) $r->parent_id;
            $cid   = (int) $r->cid;
            $label = (string) $r->label;
            $v     = (float) $r->rating;
            if ( ! isset( $acc[ $pid ][ $cid ] ) ) {
                $acc[ $pid ][ $cid ] = [ 0.0, 0, $label ];
            }
            $acc[ $pid ][ $cid ][0] += $v;
            $acc[ $pid ][ $cid ][1]++;
        }

        foreach ( $acc as $pid => $subs ) {
            if ( ! isset( $out[ $pid ] ) ) continue;
            // Preserve category display_order by sorting sub_ids via a lookup.
            $children = $this->cats_repo->getChildren( $pid, true );
            $order    = [];
            foreach ( $children as $i => $c ) $order[ (int) $c->id ] = $i;

            $sub_rows = [];
            foreach ( $subs as $cid => $data ) {
                $sub_rows[] = [
                    'sub_id' => $cid,
                    'label'  => $data[2],
                    'mean'   => round( $data[0] / $data[1], 1 ),
                    'count'  => $data[1],
                    '_sort'  => $order[ $cid ] ?? 9999,
                ];
            }
            usort( $sub_rows, fn( $a, $b ) => $a['_sort'] <=> $b['_sort'] );
            foreach ( $sub_rows as &$s ) unset( $s['_sort'] );
            $out[ $pid ]['subs'] = $sub_rows;
        }

        // Drop mains with no sub data at all — keeps the accordion clean.
        foreach ( $out as $mid => $entry ) {
            if ( empty( $entry['subs'] ) ) unset( $out[ $mid ] );
        }
        return $out;
    }

    // Time-series (for line chart)

    /**
     * Per-main, per-evaluation values suitable for a Chart.js line chart.
     * Only points where that main had a value (direct or sub-rollup)
     * are returned — Chart.js handles gaps fine.
     *
     * @return array{
     *   labels: string[],            // evaluation dates in chronological order
     *   series: array<int, array{
     *     main_id: int, label: string,
     *     points: array<int, ?float>  // same length as labels; null for gaps
     *   }>
     * }
     */
    public function getTrendSeries( int $player_id, array $filters = [] ): array {
        $evals = $this->getEvaluationsForPlayer( $player_id, $filters );
        $mains = $this->cats_repo->getMainCategories( true );

        $labels = [];
        foreach ( $evals as $ev ) $labels[] = (string) $ev->eval_date;

        $series = [];
        foreach ( $mains as $m ) {
            $series[] = [
                'main_id' => (int) $m->id,
                'label'   => (string) $m->label,
                'points'  => array_fill( 0, count( $evals ), null ),
            ];
        }

        // Fill points evaluation by evaluation, from one batched lookup
        // rather than a query set per evaluation (#3702).
        $eval_ids = array_map( fn( $e ) => (int) $e->id, $evals );
        $effective_by_eval = $this->ratings_repo->effectiveMainRatingsForEvaluations( $eval_ids );

        foreach ( $eval_ids as $i => $eid ) {
            $effective = $effective_by_eval[ $eid ] ?? [];
            foreach ( $series as $k => $s ) {
                $mid = $s['main_id'];
                $val = $effective[ $mid ]['value'] ?? null;
                $series[ $k ]['points'][ $i ] = $val === null ? null : (float) $val;
            }
        }

        // v4.0.7 (#878) — prepend an "Overall" series as the per-date
        // mean across all main-category points. Consumers that read
        // $series[0] as the "overall" line (FrontendComparisonView,
        // wp-admin PlayerComparisonPage) now get a legitimate aggregate
        // instead of the first main category — which broke whenever
        // that category had no data in scope.
        $overall_points = array_fill( 0, count( $labels ), null );
        foreach ( $labels as $idx => $_ ) {
            $vals = [];
            foreach ( $series as $ser ) {
                $pt = $ser['points'][ $idx ] ?? null;
                if ( $pt !== null ) $vals[] = (float) $pt;
            }
            if ( count( $vals ) > 0 ) {
                $overall_points[ $idx ] = round( array_sum( $vals ) / count( $vals ), 2 );
            }
        }
        array_unshift( $series, [
            'main_id' => 0,
            'label'   => __( 'Overall', 'talenttrack' ),
            'points'  => $overall_points,
        ] );

        return [ 'labels' => $labels, 'series' => $series ];
    }

    /**
     * #3949 — the category notes behind the trend's points, aligned with
     * `getTrendSeries()`: one entry per main category, in the same order
     * (without the leading "Overall" series), each a list the length of
     * `labels` holding the note text at that evaluation, or '' for none.
     *
     * A main category's point gathers its own note and its subcategories'
     * notes, each sub note prefixed with the sub's name, so the story
     * behind a moving line is one tap away. Callers show this only where
     * they already show the evaluations it comes from.
     *
     * @param array<string,mixed> $filters
     * @return array<int, array{main_id:int, notes: array<int, string>}>
     */
    public function getTrendNotes( int $player_id, array $filters = [] ): array {
        $evals    = $this->getEvaluationsForPlayer( $player_id, $filters );
        $mains    = $this->cats_repo->getMainCategories( true );
        $eval_ids = array_map( fn( $e ) => (int) $e->id, $evals );
        $by_eval  = ( new \TT\Infrastructure\Evaluations\EvalCategoryNotesRepository() )->forEvaluations( $eval_ids );

        // Sub category id to [parent id, label], for the categories that
        // carry a note at all.
        $parents = [];
        foreach ( $mains as $m ) {
            foreach ( $this->cats_repo->getChildren( (int) $m->id, false ) as $sub ) {
                $parents[ (int) $sub->id ] = [
                    (int) $m->id,
                    \TT\Infrastructure\Evaluations\EvalCategoriesRepository::displayLabel( (string) ( $sub->label ?? '' ), (int) $sub->id ),
                ];
            }
        }

        $out = [];
        foreach ( $mains as $m ) {
            $mid   = (int) $m->id;
            $notes = [];
            foreach ( $eval_ids as $i => $eid ) {
                $parts = [];
                $for_eval = $by_eval[ $eid ] ?? [];
                if ( isset( $for_eval[ $mid ] ) ) $parts[] = $for_eval[ $mid ];
                foreach ( $for_eval as $cid => $note ) {
                    if ( ( $parents[ $cid ][0] ?? 0 ) !== $mid ) continue;
                    $parts[] = $parents[ $cid ][1] . ': ' . $note;
                }
                $notes[ $i ] = implode( "\n", $parts );
            }
            $out[] = [ 'main_id' => $mid, 'notes' => $notes ];
        }
        return $out;
    }

    // Radar snapshots

    /**
     * Last N evaluations' per-main effective rating — one dataset per
     * evaluation, shape matches the existing radar infrastructure.
     *
     * @return array{
     *   labels: string[],
     *   datasets: array<int, array{label: string, values: array<int, float|int>}>
     * }
     */
    public function getRadarSnapshots( int $player_id, array $filters = [], int $count = 3 ): array {
        $evals = $this->getEvaluationsForPlayer( $player_id, $filters );
        $mains = $this->cats_repo->getMainCategories( true );

        $labels = [];
        foreach ( $mains as $m ) $labels[] = (string) $m->label;

        // Take last N (filter already limited by date/type).
        $slice = array_slice( $evals, - max( 1, $count ) );

        // One batched lookup for the slice, not one per evaluation (#3702).
        $eval_ids   = [];
        $eval_dates = [];
        foreach ( $slice as $ev ) {
            $eval_ids[]   = (int) $ev->id;
            $eval_dates[] = (string) $ev->eval_date;
        }
        $effective_by_eval = $this->ratings_repo->effectiveMainRatingsForEvaluations( $eval_ids );

        $datasets = [];
        foreach ( $eval_ids as $i => $eid ) {
            $effective = $effective_by_eval[ $eid ] ?? [];
            $values = [];
            foreach ( $mains as $m ) {
                $mid = (int) $m->id;
                $values[] = $effective[ $mid ]['value'] ?? 0;
            }
            $datasets[] = [
                'label'  => $eval_dates[ $i ],
                'values' => $values,
            ];
        }
        return [ 'labels' => $labels, 'datasets' => $datasets ];
    }

    // Helpers

    /**
     * Normalize + sanitize filter input (from $_GET or an array).
     * Unknown keys are dropped; bad dates become empty.
     *
     * @param array<string,mixed> $raw
     * @return array{date_from:string, date_to:string, eval_type_id:int}
     */
    public static function sanitizeFilters( array $raw ): array {
        return [
            'date_from'    => self::sanitizeDate( $raw['date_from'] ?? '' ),
            'date_to'      => self::sanitizeDate( $raw['date_to']   ?? '' ),
            'eval_type_id' => isset( $raw['eval_type_id'] ) ? (int) $raw['eval_type_id'] : 0,
        ];
    }

    private static function sanitizeDate( $v ): string {
        $s = is_string( $v ) ? trim( $v ) : '';
        if ( $s === '' ) return '';
        // Accept YYYY-MM-DD only; anything else → empty.
        if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $s ) === 1 ) {
            return $s;
        }
        return '';
    }
}
