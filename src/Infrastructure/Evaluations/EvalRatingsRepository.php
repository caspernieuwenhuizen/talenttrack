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
                    c.`key`          AS category_key,
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
}
