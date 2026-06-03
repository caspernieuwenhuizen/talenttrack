<?php
/**
 * Migration 0146 — Rename tt_session_principles → tt_activity_principles (#1153).
 *
 * Companion to migration 0027 (sessions → activities rename) that was
 * never written. The link table was created as `tt_session_principles`
 * in migration 0015 with a `session_id` column, but every consumer
 * (PrincipleLinksRepository, ActivitiesPage, ActivitiesRestController,
 * the new-activity wizard's PrinciplesStep, the activity edit form,
 * the planner's principle pills) reads/writes against the post-rename
 * names `tt_activity_principles` / `activity_id`. Result: every write
 * silently failed (`$wpdb->insert` returned `false`), every read
 * returned empty. Pilot 2026-06-03 caught it when the new-activity
 * wizard's principle picker appeared to work but persisted nothing.
 *
 * Steps:
 *   1. Rename table `tt_session_principles` → `tt_activity_principles`
 *      if the old name exists and the new doesn't.
 *   2. Inside the (now-renamed) table, rename column `session_id`
 *      → `activity_id` if still present.
 *   3. Rename the related indexes (uniq_session_principle, idx_session)
 *      to their activity-named counterparts.
 *   4. Fresh-install fallback: if both names are absent (e.g. install
 *      starting after this migration ships AND missing 0015), create
 *      the table at the new name via dbDelta.
 *
 * Idempotent: every step gates on current state. Safe to re-run.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0146_rename_session_principles_to_activity_principles';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;
        $old = "{$p}tt_session_principles";
        $new = "{$p}tt_activity_principles";

        $old_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $old ) ) === $old;
        $new_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $new ) ) === $new;

        if ( $old_exists && ! $new_exists ) {
            $wpdb->query( "RENAME TABLE {$old} TO {$new}" );
            $new_exists = true;
        }

        if ( $new_exists ) {
            // Rename session_id → activity_id if it's still present.
            $col = $wpdb->get_var( $wpdb->prepare(
                "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'session_id'",
                $new
            ) );
            if ( $col === 'session_id' ) {
                $wpdb->query( "ALTER TABLE {$new} CHANGE session_id activity_id BIGINT UNSIGNED NOT NULL" );
            }

            // Rename indexes — uniq_session_principle + idx_session — if still present.
            $uniq = $wpdb->get_var( $wpdb->prepare(
                "SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = 'uniq_session_principle'",
                $new
            ) );
            if ( $uniq === 'uniq_session_principle' ) {
                $wpdb->query( "ALTER TABLE {$new} DROP INDEX uniq_session_principle" );
                $wpdb->query( "ALTER TABLE {$new} ADD UNIQUE KEY uniq_activity_principle (activity_id, principle_id)" );
            }
            $idx = $wpdb->get_var( $wpdb->prepare(
                "SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = 'idx_session'",
                $new
            ) );
            if ( $idx === 'idx_session' ) {
                $wpdb->query( "ALTER TABLE {$new} DROP INDEX idx_session" );
                $wpdb->query( "ALTER TABLE {$new} ADD KEY idx_activity (activity_id)" );
            }
            return;
        }

        // Neither old nor new exists — recreate at the new name (covers
        // edge case where 0015 didn't run or the table got dropped).
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( "CREATE TABLE {$new} (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            activity_id     BIGINT UNSIGNED NOT NULL,
            principle_id    BIGINT UNSIGNED NOT NULL,
            sort_order      INT             NOT NULL DEFAULT 0,
            created_at      DATETIME        DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_activity_principle (activity_id, principle_id),
            KEY idx_activity (activity_id),
            KEY idx_principle (principle_id)
        ) {$charset};" );
    }
};
