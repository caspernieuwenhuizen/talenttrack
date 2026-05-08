<?php
/**
 * Migration 0083 — Backfill `tt_eval_ratings.club_id` from the parent
 * evaluation row.
 *
 * Three writer paths inserted into `tt_eval_ratings` without setting
 * `club_id`:
 *
 *   - `EvaluationsRestController::write_ratings()` (REST POST evaluations)
 *   - `EvaluationInserter::insert()` (#0072 wizard helper)
 *   - `ReviewStep::submit()` (legacy wizard path)
 *
 * Migration 0038's `ALTER TABLE … ADD COLUMN club_id INT UNSIGNED NOT
 * NULL DEFAULT 1` should have caught the omission with the column
 * default. But strict-mode MySQL installs that loaded the schema
 * differently, plus rows written between the wp_evaluations insert
 * (which set `club_id` correctly) and the rating insert that
 * silently dropped to `0`, leave us with a population of rating rows
 * stuck at `club_id = 0` — invisible to every read scoped by
 * `CurrentClub::id()`.
 *
 * Symptom on the player surface: the `My evaluations` tile rendered
 * the overall-rating badge from `EvalRatingsRepository::overallRatingsForEvaluations()`
 * but the per-category pill row was empty because
 * `QueryHelpers::get_evaluation()` filtered out the rating rows on
 * `WHERE r.club_id = %d`.
 *
 * The going-forward fix lives in the writer paths (v3.110.x). This
 * migration patches existing data: copy `tt_evaluations.club_id` onto
 * any `tt_eval_ratings` row whose `club_id` is 0.
 *
 * Idempotent: re-running is a no-op once every row has a non-zero
 * club_id. Defensive: short-circuits when neither table has rows.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0083_eval_ratings_club_id_backfill';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $ratings_table = $p . 'tt_eval_ratings';
        $evals_table   = $p . 'tt_evaluations';

        $found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $ratings_table ) );
        if ( $found !== $ratings_table ) return;
        $found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $evals_table ) );
        if ( $found !== $evals_table ) return;

        // Copy each rating row's parent eval's club_id when the rating
        // currently has 0. UPDATE … JOIN avoids the application-layer
        // round-trip per row.
        $wpdb->query(
            "UPDATE {$ratings_table} r
                JOIN {$evals_table} e ON e.id = r.evaluation_id
                SET r.club_id = e.club_id
              WHERE r.club_id = 0
                AND e.club_id <> 0"
        );
    }
};
