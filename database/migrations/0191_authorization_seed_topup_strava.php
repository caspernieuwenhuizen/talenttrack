<?php
/**
 * Migration 0191 — seed the Strava matrix entity (#2127, from epic #2002).
 *
 * The Strava integration (#2002, v4.62.0) shipped operator endpoints gated on
 * `manage_options` and no matrix entity. #2127 introduces the operator console
 * and re-gates the operator surface onto the matrix cap pair
 * `tt_view_strava` / `tt_edit_strava_credentials`, bridged to the
 * `strava_integration` entity (read / change) via `LegacyCapMapper`.
 *
 * The matrix reseed is a manual, destructive TRUNCATE+reinsert that never runs
 * on upgrade, so already-installed sites would have ZERO `strava_integration`
 * rows in `tt_authorization_matrix`. With the matrix active, the operator
 * console + its REST routes gate on `MatrixGate::canAnyScope('strava_integration', …)`,
 * which is matrix-only — so without this top-up academy_admin and
 * head_of_development would be silently denied the console on upgraded installs.
 *
 * `0188_strava_integration_foundation` shipped the schema; this is the missing
 * authorization companion, mirroring 0187_recycle_bin / 0190_measurements.
 *
 * Idempotent / re-runnable. INSERT IGNORE on the unique key
 * (persona, entity, activity, scope_kind) leaves any operator-edited rows
 * untouched and only adds the missing tuples. Scoped to the single
 * `strava_integration` entity so it never re-adds rows an operator deliberately
 * removed for other entities. Run-alone (no other migration in parallel).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    private const ENTITIES = [ 'strava_integration' ];

    public function getName(): string {
        return '0191_authorization_seed_topup_strava';
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
