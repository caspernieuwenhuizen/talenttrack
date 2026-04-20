<?php
namespace TT\Infrastructure\Evaluations;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * EvalRatingsRepository — data access for tt_eval_ratings with hierarchy awareness.
 *
 * Sprint 1I (v2.12.0). Handles two kinds of rating rows in the same table:
 *
 *   - MAIN ratings:  tt_eval_ratings.category_id points at a row in
 *                    tt_eval_categories where parent_id IS NULL.
 *   - SUB ratings:   tt_eval_ratings.category_id points at a row where
 *                    parent_id is set (the id of a main category).
 *
 * The "either/or" model chosen in Sprint 1I design: for any given
 * (evaluation, main_category), the coach either entered a direct main
 * rating, OR rated subcategories, OR did neither. If both exist, both are
 * stored; it's the display layer's job to surface whichever makes sense.
 *
 * The effectiveMainRating() helper computes, per (evaluation, main_category):
 *   1. If a direct rating for the main was stored, return it.
 *   2. Else, if subcategory ratings exist for this main, return their mean.
 *   3. Else, return null (not rated).
 *
 * Epic 2 charts and radar displays call effectiveMainRating() so they don't
 * have to know which storage mode a given evaluation used.
 */
class EvalRatingsRepository {

    private function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'tt_eval_ratings';
    }

    private function catTable(): string {
        global $wpdb;
        return $wpdb->prefix . 'tt_eval_categories';
    }

    /**
     * Every rating row for an evaluation, joined with category metadata
     * so callers can tell main-vs-sub cheaply.
     *
     * @return object[]
     */
    public function getForEvaluation( int $eval_id ): array {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT r.id,
                    r.evaluation_id,
                    r.category_id,
                    r.rating,
                    c.category_key   AS category_key,
                    c.label          AS category_label,
                    c.parent_id      AS parent_id,
                    c.display_order  AS display_order
             FROM {$this->table()} r
             LEFT JOIN {$this->catTable()} c ON r.category_id = c.id
             WHERE r.evaluation_id = %d
             ORDER BY c.parent_id IS NULL DESC, c.display_order ASC, r.id ASC",
            $eval_id
        ) );
    }

    /**
     * Effective rating for a main category on a single evaluation.
     *
     * Return shape:
     *   [ 'value' => float, 'source' => 'direct' | 'computed' | 'none',
     *     'sub_count' => int ]
     *
     * - 'direct'   — coach entered a main rating, returned as-is
     * - 'computed' — no direct rating, averaged from sub_count subcategory ratings
     * - 'none'     — neither present; value is null
     *
     * @return array{value: ?float, source: string, sub_count: int}
     */
    public function effectiveMainRating( int $eval_id, int $main_category_id ): array {
        global $wpdb;

        // 1. Direct rating?
        $direct = $wpdb->get_var( $wpdb->prepare(
            "SELECT rating FROM {$this->table()} WHERE evaluation_id = %d AND category_id = %d LIMIT 1",
            $eval_id, $main_category_id
        ) );
        if ( $direct !== null ) {
            return [ 'value' => (float) $direct, 'source' => 'direct', 'sub_count' => 0 ];
        }

        // 2. Average of subcategory ratings under this main.
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT AVG(r.rating) AS avg_rating, COUNT(*) AS n
             FROM {$this->table()} r
             INNER JOIN {$this->catTable()} c ON r.category_id = c.id
             WHERE r.evaluation_id = %d AND c.parent_id = %d",
            $eval_id, $main_category_id
        ) );
        if ( $row && (int) $row->n > 0 ) {
            return [
                'value'     => round( (float) $row->avg_rating, 2 ),
                'source'    => 'computed',
                'sub_count' => (int) $row->n,
            ];
        }

        // 3. Nothing.
        return [ 'value' => null, 'source' => 'none', 'sub_count' => 0 ];
    }

    /**
     * Same idea as effectiveMainRating(), but returns a row per main
     * category for a single evaluation — convenient for the detail view
     * and any radar-chart consumer.
     *
     * @param int[]|null $main_ids  Optional limit — if null, all actives.
     * @return array<int, array{label:string, value:?float, source:string, sub_count:int}>
     *         Keyed by main_category_id.
     */
    public function effectiveMainRatingsFor( int $eval_id, ?array $main_ids = null ): array {
        $cats_repo = new EvalCategoriesRepository();
        $mains     = $cats_repo->getMainCategories( true );
        $out       = [];

        foreach ( $mains as $m ) {
            $mid = (int) $m->id;
            if ( $main_ids !== null && ! in_array( $mid, $main_ids, true ) ) continue;
            $eff = $this->effectiveMainRating( $eval_id, $mid );
            $out[ $mid ] = [
                'label'     => (string) $m->label,
                'value'     => $eff['value'],
                'source'    => $eff['source'],
                'sub_count' => $eff['sub_count'],
            ];
        }
        return $out;
    }

    /**
     * Write helper used by the save handler. Writes or overwrites (on
     * re-save the caller first wipes the old rows and rewrites).
     */
    public function upsert( int $eval_id, int $category_id, float $rating ): bool {
        global $wpdb;
        $t = $this->table();

        $existing = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$t} WHERE evaluation_id = %d AND category_id = %d LIMIT 1",
            $eval_id, $category_id
        ) );
        if ( $existing > 0 ) {
            return $wpdb->update( $t, [ 'rating' => $rating ], [ 'id' => $existing ], [ '%f' ], [ '%d' ] ) !== false;
        }
        return $wpdb->insert( $t, [
            'evaluation_id' => $eval_id,
            'category_id'   => $category_id,
            'rating'        => $rating,
        ], [ '%d', '%d', '%f' ] ) !== false;
    }

    public function deleteForEvaluation( int $eval_id ): void {
        global $wpdb;
        $wpdb->delete( $this->table(), [ 'evaluation_id' => $eval_id ] );
    }

    /* ═══════════════ Overall rating (v2.13.0) ═══════════════ */

    /**
     * Weighted overall rating for a single evaluation.
     *
     * Algorithm:
     *   1. Resolve the evaluation → player → team → age_group chain.
     *   2. Fetch configured weights for that age group. If none, fall
     *      back to equal weights across the active main categories.
     *   3. For each active main, read its effective rating (direct or
     *      sub-rollup or null). Skip nulls — they drop out of both the
     *      weighted sum and the weight denominator.
     *   4. Return weighted mean: Σ(value × weight) / Σ(weight_of_rated)
     *      rounded to 1 decimal.
     *
     * Return shape:
     *   [
     *     'value'        => float|null,  // null if no mains rated
     *     'weighted'     => bool,        // true = used configured weights
     *     'rated_mains'  => int,         // count contributing
     *     'total_mains'  => int,         // count of active mains
     *     'age_group_id' => int|null,    // resolved age group, null if unresolvable
     *   ]
     *
     * @return array{value:?float, weighted:bool, rated_mains:int, total_mains:int, age_group_id:?int}
     */
    public function overallRating( int $eval_id ): array {
        $age_group_id = $this->resolveAgeGroupForEvaluation( $eval_id );

        $cats_repo = new EvalCategoriesRepository();
        $mains     = $cats_repo->getMainCategories( true );
        $main_ids  = array_map( fn( $m ) => (int) $m->id, $mains );

        $weights_repo = new CategoryWeightsRepository();
        $configured   = $age_group_id > 0 ? $weights_repo->getForAgeGroup( $age_group_id ) : [];
        $is_weighted  = ! empty( $configured );
        $weights      = $is_weighted ? $configured : CategoryWeightsRepository::equalWeightsForMains( $main_ids );

        $effective = $this->effectiveMainRatingsFor( $eval_id );
        $rated     = 0;
        $num       = 0.0;
        $denom     = 0;

        foreach ( $main_ids as $mid ) {
            if ( ! isset( $effective[ $mid ] ) ) continue;
            $val = $effective[ $mid ]['value'];
            if ( $val === null ) continue;
            $w = (int) ( $weights[ $mid ] ?? 0 );
            if ( $w <= 0 ) continue;
            $num   += (float) $val * $w;
            $denom += $w;
            $rated++;
        }

        $value = $denom > 0 ? round( $num / $denom, 1 ) : null;

        return [
            'value'        => $value,
            'weighted'     => $is_weighted,
            'rated_mains'  => $rated,
            'total_mains'  => count( $main_ids ),
            'age_group_id' => $age_group_id > 0 ? $age_group_id : null,
        ];
    }

    /**
     * Batch overall ratings for many evaluations. Two SQL roundtrips
     * total: one for all ratings, one for all age-group resolutions,
     * plus weight batching via CategoryWeightsRepository::getForAgeGroups.
     * In-memory compute per evaluation.
     *
     * @param int[] $eval_ids
     * @return array<int, array{value:?float, weighted:bool, rated_mains:int, total_mains:int, age_group_id:?int}>
     *         Keyed by eval_id. Entries are the same shape as overallRating().
     */
    public function overallRatingsForEvaluations( array $eval_ids ): array {
        global $wpdb;

        $clean = array_values( array_unique( array_filter( array_map( 'intval', $eval_ids ), fn( $v ) => $v > 0 ) ) );
        if ( empty( $clean ) ) return [];

        $cats_repo    = new EvalCategoriesRepository();
        $mains        = $cats_repo->getMainCategories( true );
        $main_ids     = array_map( fn( $m ) => (int) $m->id, $mains );
        $main_id_set  = array_flip( $main_ids );
        $parent_of    = []; // child_id => parent_id (for sub-rollup grouping)

        foreach ( $cats_repo->getAll( true ) as $c ) {
            if ( $c->parent_id !== null ) {
                $parent_of[ (int) $c->id ] = (int) $c->parent_id;
            }
        }

        // Age groups for each evaluation (single roundtrip).
        $p = $wpdb->prefix;
        $placeholders = implode( ',', array_fill( 0, count( $clean ), '%d' ) );
        $ag_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT e.id AS eval_id, t.age_group_id AS age_group_id
             FROM {$p}tt_evaluations e
             LEFT JOIN {$p}tt_players pl ON e.player_id = pl.id
             LEFT JOIN {$p}tt_teams t ON pl.team_id = t.id
             WHERE e.id IN ($placeholders)",
            ...$clean
        ) );
        $age_group_by_eval = [];
        $age_groups_in_play = [];
        foreach ( (array) $ag_rows as $r ) {
            $ag = $r->age_group_id !== null ? (int) $r->age_group_id : 0;
            $age_group_by_eval[ (int) $r->eval_id ] = $ag;
            if ( $ag > 0 ) $age_groups_in_play[ $ag ] = true;
        }

        // Ratings for all evaluations (single roundtrip).
        $rating_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT evaluation_id, category_id, rating FROM {$p}tt_eval_ratings WHERE evaluation_id IN ($placeholders)",
            ...$clean
        ) );

        // Weights for all age groups involved (single roundtrip, already batched).
        $weights_repo    = new CategoryWeightsRepository();
        $weights_by_ag   = $weights_repo->getForAgeGroups( array_keys( $age_groups_in_play ) );
        $equal_fallback  = CategoryWeightsRepository::equalWeightsForMains( $main_ids );

        // Bucket ratings per evaluation, splitting main vs sub.
        $direct_by_eval = []; // eval_id => main_id => rating
        $sub_sum        = []; // eval_id => main_id => [sum, count]
        foreach ( (array) $rating_rows as $r ) {
            $eid = (int) $r->evaluation_id;
            $cid = (int) $r->category_id;
            $val = (float) $r->rating;
            if ( isset( $main_id_set[ $cid ] ) ) {
                $direct_by_eval[ $eid ][ $cid ] = $val;
            } elseif ( isset( $parent_of[ $cid ] ) ) {
                $pid = $parent_of[ $cid ];
                if ( ! isset( $sub_sum[ $eid ][ $pid ] ) ) $sub_sum[ $eid ][ $pid ] = [ 0.0, 0 ];
                $sub_sum[ $eid ][ $pid ][0] += $val;
                $sub_sum[ $eid ][ $pid ][1]++;
            }
        }

        // Compute per evaluation.
        $out = [];
        foreach ( $clean as $eid ) {
            $ag           = $age_group_by_eval[ $eid ] ?? 0;
            $configured   = $ag > 0 && ! empty( $weights_by_ag[ $ag ] ) ? $weights_by_ag[ $ag ] : [];
            $is_weighted  = ! empty( $configured );
            $weights      = $is_weighted ? $configured : $equal_fallback;

            $rated = 0;
            $num   = 0.0;
            $denom = 0;
            foreach ( $main_ids as $mid ) {
                // Effective rating: direct if present, else sub rollup.
                $val = null;
                if ( isset( $direct_by_eval[ $eid ][ $mid ] ) ) {
                    $val = $direct_by_eval[ $eid ][ $mid ];
                } elseif ( isset( $sub_sum[ $eid ][ $mid ] ) && $sub_sum[ $eid ][ $mid ][1] > 0 ) {
                    $val = $sub_sum[ $eid ][ $mid ][0] / $sub_sum[ $eid ][ $mid ][1];
                }
                if ( $val === null ) continue;
                $w = (int) ( $weights[ $mid ] ?? 0 );
                if ( $w <= 0 ) continue;
                $num   += $val * $w;
                $denom += $w;
                $rated++;
            }

            $out[ $eid ] = [
                'value'        => $denom > 0 ? round( $num / $denom, 1 ) : null,
                'weighted'     => $is_weighted,
                'rated_mains'  => $rated,
                'total_mains'  => count( $main_ids ),
                'age_group_id' => $ag > 0 ? $ag : null,
            ];
        }
        return $out;
    }

    /**
     * Resolve the age_group_id relevant for an evaluation, via the
     * evaluation → player → team → age_group chain. Returns 0 if any
     * link is missing (player deleted, team unassigned, team has no
     * age group set, etc.) — the caller treats 0 as "no weights
     * applicable, use equal fallback".
     */
    private function resolveAgeGroupForEvaluation( int $eval_id ): int {
        global $wpdb;
        $p = $wpdb->prefix;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT t.age_group_id AS age_group_id
             FROM {$p}tt_evaluations e
             LEFT JOIN {$p}tt_players pl ON e.player_id = pl.id
             LEFT JOIN {$p}tt_teams t ON pl.team_id = t.id
             WHERE e.id = %d LIMIT 1",
            $eval_id
        ) );
        if ( ! $row || $row->age_group_id === null ) return 0;
        return (int) $row->age_group_id;
    }
}
