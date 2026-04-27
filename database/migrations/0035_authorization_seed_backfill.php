<?php
/**
 * Migration: 0035_authorization_seed_backfill
 *
 * #0033 follow-up — backfill `tt_authorization_matrix` with the rows
 * added in v3.37.1's seed expansion (settings / frontend_admin /
 * workflow_tasks / tasks_dashboard / workflow_templates / dev_ideas
 * across the personas that need them, plus the entity-name alignments
 * for `functional_role_assignments` and `backup`).
 *
 * Why a backfill migration rather than a reseed:
 *   - The original seeder (0026) only seeds when the table is empty;
 *     it skips on every install that already has rows. Without this
 *     migration the new entries never reach existing customer DBs.
 *   - Admin edits to existing rows must be preserved. The unique key
 *     `(persona, entity, activity, scope_kind)` lets us `INSERT IGNORE`
 *     so only the missing rows are added.
 *
 * Idempotent. Safe to re-run.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0035_authorization_seed_backfill';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $table = "{$p}tt_authorization_matrix";
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            // Migration 0026 hasn't run yet; nothing to backfill.
            return;
        }

        $seed_path = TT_PLUGIN_DIR . 'config/authorization_seed.php';
        if ( ! is_readable( $seed_path ) ) return;

        $rows = require $seed_path;
        if ( ! is_array( $rows ) ) return;

        // INSERT IGNORE so existing rows (including admin-edited ones)
        // are left untouched. Only new (persona, entity, activity,
        // scope_kind) tuples are added.
        $sql = "INSERT IGNORE INTO {$table}
                  (persona, entity, activity, scope_kind, module_class, is_default)
                VALUES (%s, %s, %s, %s, %s, 1)";

        foreach ( $rows as $row ) {
            $wpdb->query( $wpdb->prepare(
                $sql,
                (string) $row['persona'],
                (string) $row['entity'],
                (string) $row['activity'],
                (string) $row['scope_kind'],
                (string) $row['module_class']
            ) );
        }
    }
};
