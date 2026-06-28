<?php
/**
 * Migration 0190 — backfill the Measurements matrix entities (#2114, from #1856).
 *
 * #1856 added the `measurements`, `measurement_sessions` and
 * `measurement_definitions` rows to the seed file
 * (`config/authorization_seed.php`) for every staff persona, but shipped no
 * top-up migration. The matrix reseed is a manual, destructive
 * TRUNCATE+reinsert that never runs on upgrade, so already-installed sites
 * have ZERO measurement rows in `tt_authorization_matrix`. The Measurements
 * views + REST gate on `MatrixGate::canAnyScope('measurements', …)`, which is
 * matrix-only — so on upgraded installs academy_admin, head_of_development
 * and the coach personas are silently denied access to the entire module
 * (record results, testing coverage, manage tests).
 *
 * `0175_measurements_foundation` shipped the schema; this is the missing
 * authorization companion, mirroring 0179_tournaments / 0187_recycle_bin.
 *
 * Idempotent / re-runnable. INSERT IGNORE on the unique key
 * (persona, entity, activity, scope_kind) leaves any operator-edited rows
 * untouched and only adds the missing tuples. Scoped to the three
 * measurement entities so it never re-adds rows an operator deliberately
 * removed for other entities. Run-alone (no other migration in parallel).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    private const ENTITIES = [ 'measurements', 'measurement_sessions', 'measurement_definitions' ];

    public function getName(): string {
        return '0190_authorization_seed_topup_measurements';
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
            if ( ! in_array( $row['entity'] ?? '', self::ENTITIES, true ) ) {
                continue;
            }
            $this->exec( $wpdb->prepare(
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
