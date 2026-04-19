<?php
/**
 * Migration 0004 — Schema reconciliation for v2.x columns.
 *
 * Purpose: Sites that were running TalentTrack before v2.0.0 (or that encountered
 * dbDelta limitations during the v2.0.0 upgrade) may have existing tables that
 * lack columns the v2.x+ code expects. This causes $wpdb->insert() to silently
 * fail, leading to "save reports success but no row appears" bugs.
 *
 * This migration runs idempotent ALTER TABLE statements guarded by column-exists
 * checks. Safe to re-run. Non-destructive — no columns are dropped, no data is
 * modified except to backfill tt_attendance.status from the legacy `present`
 * column.
 *
 * Affected tables:
 *   - tt_evaluations: adds eval_type_id, opponent, competition, match_result,
 *     home_away, minutes_played, updated_at. Relaxes legacy category_id/rating
 *     to NULL so the v1.x inline-rating columns don't block new inserts.
 *   - tt_attendance: adds status column (v1.x used boolean `present`).
 *   - tt_goals: adds priority column.
 *
 * Legacy tables (tt_eval_categories) and legacy columns (tt_evaluations.category_id,
 * tt_evaluations.rating, tt_attendance.present) are NOT dropped. They remain for
 * historical data preservation and can be manually cleaned up later.
 */

use TT\Infrastructure\Database\MigrationHelpers;

return new class {

    public function up( \wpdb $wpdb ): void {
        $p = $wpdb->prefix;

        /* ═══ tt_evaluations ═══ */

        MigrationHelpers::addColumnIfMissing(
            "{$p}tt_evaluations",
            'eval_type_id',
            'BIGINT(20) UNSIGNED NULL',
            'coach_id'
        );
        MigrationHelpers::addColumnIfMissing(
            "{$p}tt_evaluations",
            'opponent',
            'VARCHAR(255) NULL'
        );
        MigrationHelpers::addColumnIfMissing(
            "{$p}tt_evaluations",
            'competition',
            'VARCHAR(255) NULL'
        );
        MigrationHelpers::addColumnIfMissing(
            "{$p}tt_evaluations",
            'match_result',
            'VARCHAR(50) NULL'
        );
        MigrationHelpers::addColumnIfMissing(
            "{$p}tt_evaluations",
            'home_away',
            'VARCHAR(10) NULL'
        );
        MigrationHelpers::addColumnIfMissing(
            "{$p}tt_evaluations",
            'minutes_played',
            'SMALLINT(5) UNSIGNED NULL'
        );
        MigrationHelpers::addColumnIfMissing(
            "{$p}tt_evaluations",
            'updated_at',
            'DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'
        );

        // The v1.x schema has NOT NULL on category_id and rating (they used to be
        // the inline rating columns). New v2.x saves don't populate them, so inserts
        // would fail with "Field doesn't have a default value" unless we relax them.
        MigrationHelpers::makeColumnNullable(
            "{$p}tt_evaluations",
            'category_id',
            'BIGINT(20) UNSIGNED NULL'
        );
        MigrationHelpers::makeColumnNullable(
            "{$p}tt_evaluations",
            'rating',
            'DECIMAL(3,1) NULL'
        );

        /* ═══ tt_attendance ═══ */

        $added_status = MigrationHelpers::addColumnIfMissing(
            "{$p}tt_attendance",
            'status',
            "VARCHAR(50) NULL DEFAULT 'present'",
            'player_id'
        );

        // Backfill from legacy `present` column if present.
        if ( $added_status && MigrationHelpers::columnExists( "{$p}tt_attendance", 'present' ) ) {
            $wpdb->query( "UPDATE {$p}tt_attendance SET status='present' WHERE present=1 AND (status IS NULL OR status='')" );
            $wpdb->query( "UPDATE {$p}tt_attendance SET status='absent' WHERE present=0 AND (status IS NULL OR status='')" );
        }

        /* ═══ tt_goals ═══ */

        MigrationHelpers::addColumnIfMissing(
            "{$p}tt_goals",
            'priority',
            "VARCHAR(50) NULL DEFAULT 'medium'",
            'status'
        );
    }
};
