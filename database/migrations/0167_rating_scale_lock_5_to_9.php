<?php
/**
 * Migration 0167 — rating scale 5–10 → 5–9, locked to whole steps (#1641).
 *
 * The evaluation rating control becomes a 1–5 star widget where the five
 * stars map to the integer values 5 / 6 / 7 / 8 / 9 (onvoldoende …
 * uitstekend). Two atomic changes:
 *
 *   1. `tt_config` rating_min / rating_max / rating_step → 5 / 9 / 1.
 *
 *   2. Every existing rating on the shared scale is converted onto 5–9:
 *
 *          new = LEAST( 9, GREATEST( 5, ROUND( old ) ) )
 *
 *      i.e. round to the nearest whole number and clamp into 5–9, so
 *      10 → 9, 9.5 → 9, 7.5 → 8, 6.25 → 6, and existing 5–9 integers are
 *      untouched. This is idempotent: re-running rounds integers to
 *      themselves and the clamp is a no-op.
 *
 * Idempotency: skip when rating_max already reads 9. The data clamp is
 * idempotent regardless, so a re-run can't corrupt values.
 *
 * @see src/Shared/Frontend/Components/RatingInputComponent.php — the star widget
 * @see database/migrations/0095_rating_scale_5_to_10.php — the prior scale shift
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0167_rating_scale_lock_5_to_9';
    }

    public function up(): void {
        global $wpdb;
        $config_table = $wpdb->prefix . 'tt_config';

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $config_table ) ) !== $config_table ) {
            return;
        }

        // Idempotency — once rating_max reads 9 this migration has run.
        $current_max = (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT config_value FROM {$config_table} WHERE config_key = %s LIMIT 1",
            'rating_max'
        ) );
        if ( (int) round( $current_max ) === 9 ) {
            return;
        }

        // Phase 1 — clamp existing rating data onto 5–9 BEFORE flipping the
        // config, so a parallel read of rating_max can't clamp UI inputs to
        // a range that still excludes some stored rows. `> 0` skips
        // not-rated / null rows.
        $rating_tables = [
            'tt_eval_ratings'             => 'rating',
            'tt_player_behaviour_ratings' => 'rating',
            'tt_trial_cases'              => 'overall_rating',
        ];
        foreach ( $rating_tables as $tbl => $col ) {
            $full = $wpdb->prefix . $tbl;
            if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full ) ) !== $full ) {
                continue;
            }
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared — column/table names are hardcoded above.
            $wpdb->query( "UPDATE {$full}
                              SET {$col} = LEAST( 9, GREATEST( 5, ROUND( {$col} ) ) )
                            WHERE {$col} > 0" );
        }

        // Phase 2 — lock the scale. Walks every club's row in one statement.
        $wpdb->query( "UPDATE {$config_table} SET config_value = '5' WHERE config_key = 'rating_min'" );
        $wpdb->query( "UPDATE {$config_table} SET config_value = '9' WHERE config_key = 'rating_max'" );
        $wpdb->query( "UPDATE {$config_table} SET config_value = '1' WHERE config_key = 'rating_step'" );
    }
};
