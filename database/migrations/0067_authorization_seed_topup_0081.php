<?php
/**
 * Migration: 0067_authorization_seed_topup_0081
 *
 * #0081 child 1 follow-up — backfill `tt_authorization_matrix` with the
 * two new entities introduced in v3.95.0:
 *   prospects, test_trainings
 *
 * Same pattern as 0063 / 0064: walk the seed file and INSERT IGNORE
 * every (persona, entity, activity, scope_kind) tuple. Existing rows —
 * including operator-edited ones — stay untouched; only the new tuples
 * land. Per `feedback_seed_changes_need_topup_migration.md`: adding
 * rows to the seed alone doesn't reach existing installs because
 * migration 0026 only seeds on fresh install or on the admin "Reset to
 * defaults" button.
 *
 * Idempotent. Safe to re-run on already-backfilled installs.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0067_authorization_seed_topup_0081';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $table = "{$p}tt_authorization_matrix";
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }

        $seed_path = TT_PLUGIN_DIR . 'config/authorization_seed.php';
        if ( ! is_readable( $seed_path ) ) return;

        $rows = require $seed_path;
        if ( ! is_array( $rows ) ) return;

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
