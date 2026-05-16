<?php
/**
 * Migration 0095 — rating scale 1–5 → 5–10 (v3.110.120).
 *
 * Operator decision: shift the evaluation rating scale from the
 * v1 5-point scale (1–5) to a Dutch academic 6-point scale (5–10).
 * Floor = 5, ceiling = 10. A rating below 5 is no longer used.
 *
 * Two atomic changes:
 *
 *   1. `tt_config[rating_min]` flips 1 → 5
 *      `tt_config[rating_max]` flips 5 → 10
 *
 *   2. Every existing `tt_eval_ratings.rating` value is remapped
 *      with the linear transform:
 *
 *          new = 5 + (old - 1) * 1.25
 *
 *      Sample table:
 *          old=1   →  new=5.00
 *          old=2   →  new=6.25
 *          old=3   →  new=7.50
 *          old=4   →  new=8.75
 *          old=5   →  new=10.00
 *
 *      Existing 0.5-step ratings (1.5, 2.5, 3.5, 4.5) map cleanly:
 *          old=1.5 →  new=5.625
 *          old=2.5 →  new=6.875
 *          old=3.5 →  new=8.125
 *          old=4.5 →  new=9.375
 *
 * Idempotency strategy: the migration runs the remap ONLY when
 * the existing `rating_max` config row reads <= 5. If an operator
 * has already flipped to 10 (or this migration has already run),
 * the row reads 10 and the remap path is skipped — preventing
 * double-application that would push every rating off the top of
 * the new scale.
 *
 * Defensive: rating rows already above 5 (corrupt / out-of-spec
 * data, or pre-applied operator hand-fixes) are left untouched.
 * The remap clause has an explicit `WHERE rating <= 5` guard so
 * a partial migration on a chaotic table can't lose top-end data.
 *
 * Multi-club: `tt_config` is club-scoped (one row per club_id);
 * the UPDATE here walks every club's row in one statement. The
 * tt_eval_ratings remap is club-agnostic — every row gets the
 * same linear transform applied — because the scale is a global
 * decision, not a per-club one.
 *
 * @see src/Modules/Configuration/Admin/ConfigurationPage.php — UI
 *      where the operator can re-flip these values back if needed
 * @see src/Shared/Frontend/Components/RatingPillComponent.php —
 *      tier thresholds (strong / developing / attention) renormed
 *      in the same v3.110.116 ship
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0095_rating_scale_5_to_10';
    }

    public function up(): void {
        global $wpdb;
        $config_table = $wpdb->prefix . 'tt_config';

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $config_table ) ) !== $config_table ) {
            return;
        }

        // Idempotency check — read the current rating_max. If already
        // > 5, the operator (or a previous run of this migration) has
        // already flipped the scale; skip both the config update and
        // the data remap to avoid double-application.
        $current_max = (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT config_value FROM {$config_table} WHERE config_key = %s LIMIT 1",
            'rating_max'
        ) );
        if ( $current_max > 5 ) {
            return;
        }

        // Phase 1 — remap existing rating data on every table that
        // shares the global rating scale. Run BEFORE the config flip
        // so a parallel read of rating_max during the migration can't
        // see the new max while the data is still on the old scale
        // (which would clamp UI inputs to a range that excludes half
        // the existing rows).
        //
        // The remap formula `new = 5 + (old - 1) * 1.25` maps the
        // legacy 1–5 range onto the new 5–10 range linearly:
        //   1 → 5, 2 → 6.25, 3 → 7.5, 4 → 8.75, 5 → 10
        //   1.5 → 5.625, 2.5 → 6.875, 3.5 → 8.125, 4.5 → 9.375
        //
        // Each `WHERE rating BETWEEN 1 AND 5` clause guards against
        // already-migrated rows + corrupt outliers above 5. Tables
        // that don't exist on this install (modules disabled, very old
        // installs that pre-date a given migration) are skipped via
        // the SHOW TABLES check per table.
        $rating_tables = [
            // Main evaluation ratings — most rows, most callers.
            'tt_eval_ratings'             => 'rating',
            // Player behaviour ratings (#0057). Same scale as eval
            // ratings per the v1 spec.
            'tt_player_behaviour_ratings' => 'rating',
            // Trial case overall rating — DECIMAL(3,2) per migration
            // 0036. Hardcoded 1–5 in the staff-input form pre-v3.110.116.
            'tt_trial_cases'              => 'overall_rating',
        ];
        foreach ( $rating_tables as $tbl => $col ) {
            $full = $wpdb->prefix . $tbl;
            if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full ) ) !== $full ) {
                continue;
            }
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared — column/table names are hardcoded above.
            $wpdb->query( "UPDATE {$full}
                              SET {$col} = 5 + ( {$col} - 1 ) * 1.25
                            WHERE {$col} BETWEEN 1 AND 5" );
        }

        // Phase 2 — flip the config. Walks every club's row in one
        // statement. UPSERT semantics not needed — the migration 0001
        // seed guarantees these rows exist on every install.
        $wpdb->query( "UPDATE {$config_table}
                          SET config_value = '5'
                        WHERE config_key = 'rating_min'" );
        $wpdb->query( "UPDATE {$config_table}
                          SET config_value = '10'
                        WHERE config_key = 'rating_max'" );
    }
};
