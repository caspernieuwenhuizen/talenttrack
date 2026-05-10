<?php
/**
 * Migration 0091 — extend `eval_type` lookup with the wizard's missing values.
 *
 * Background: two parallel taxonomies covered the same conceptual ground —
 *
 *   - `tt_lookups` lookup_type='eval_type' (3 rows: Training / Match / Friendly,
 *     with `meta.requires_match_details` flag) — used by the flat evaluation
 *     form and the edit form.
 *   - `tt_lookups` lookup_type='evaluation_setting' (5 rows: training / match /
 *     tournament / observation / other, no metadata) — used by the new-evaluation
 *     wizard's HybridDeepRateStep "Setting" picker.
 *
 * The wizard captured `evaluation_setting` in its session state but never wrote
 * it to `tt_evaluations` — the wizard's submit path skipped `eval_type_id`
 * entirely. Net effect: a coach who picked "observation" in the wizard saw a
 * different list (Training / Match / Friendly) when they reopened the eval for
 * edit, and the edit form fell back to the first option because `eval_type_id`
 * was 0.
 *
 * Resolution (v3.110.67): unify on `eval_type` as the single source of truth.
 * This migration extends `eval_type` with the three values the wizard offered
 * that weren't already in `eval_type`:
 *
 *   - Tournament (requires_match_details: true — same shape as Match/Friendly)
 *   - Observation (requires_match_details: false — ad-hoc spotting, no game)
 *   - Other (requires_match_details: false — catch-all)
 *
 * Idempotent. SELECT-then-INSERT-IF-MISSING. Existing `eval_type` rows are
 * left untouched. The `evaluation_setting` lookup rows stay in place for
 * backward compat (no consumer reads them after v3.110.67) — a future cleanup
 * migration can drop them when we're confident no install holds references.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0091_extend_eval_type_lookup';
    }

    public function up(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_lookups';

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }

        // Highest existing sort_order on eval_type, so the new rows append
        // rather than re-shuffle the seeded ones.
        $max_sort = (int) $wpdb->get_var(
            "SELECT COALESCE( MAX(sort_order), 0 ) FROM {$table} WHERE lookup_type = 'eval_type'"
        );

        $rows = [
            [
                'name'        => 'Tournament',
                'description' => 'Tournament / cup match evaluation',
                'meta'        => '{"requires_match_details":true}',
            ],
            [
                'name'        => 'Observation',
                'description' => 'Ad-hoc observation not anchored to a specific match or training',
                'meta'        => '{"requires_match_details":false}',
            ],
            [
                'name'        => 'Other',
                'description' => 'Catch-all for evaluations that do not fit the seeded types',
                'meta'        => '{"requires_match_details":false}',
            ],
        ];

        foreach ( $rows as $i => $row ) {
            $existing = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$table} WHERE lookup_type = 'eval_type' AND name = %s LIMIT 1",
                $row['name']
            ) );
            if ( $existing ) continue;
            $wpdb->insert( $table, [
                'lookup_type' => 'eval_type',
                'name'        => $row['name'],
                'description' => $row['description'],
                'meta'        => $row['meta'],
                'sort_order'  => $max_sort + $i + 1,
            ] );
        }
    }
};
