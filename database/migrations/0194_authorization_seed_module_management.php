<?php
/**
 * Migration 0194 — seed the `module_management` matrix entity (#2187).
 *
 * The Modules admin surface (wp-admin `tt-modules` + frontend
 * `?tt_view=modules`) previously gated on a role-string compare
 * (`current_user_can('administrator')`) in ModulesPage, and the
 * `tt_manage_modules` cap bridged to `feature_toggles:change` (#1941),
 * conflating module enable/disable with the read-mostly feature-toggle
 * config entity. #2187 makes the surface matrix-driven via a dedicated
 * `module_management` entity and re-points `tt_manage_modules` at it in
 * LegacyCapMapper.
 *
 * The matrix reseed is a manual, destructive TRUNCATE+reinsert that never
 * runs on upgrade, so already-installed sites would never gain the new
 * `module_management` rows — and because `tt_manage_modules` no longer
 * resolves through `feature_toggles`, the academy_admin persona would
 * silently lose the Modules page until a manual reseed. This top-up adds
 * the missing grant, mirroring 0190_measurements / 0191_strava /
 * 0193_player_strava.
 *
 * Idempotent / re-runnable. INSERT IGNORE on the unique key
 * (persona, entity, activity, scope_kind) leaves any operator-edited rows
 * untouched and only adds the missing tuples. Scoped to the single
 * `module_management` entity AND the `academy_admin` persona so it never
 * touches another entity or persona. WP administrators bypass every tt_*
 * cap unconditionally (LegacyCapMapper), so no administrator loses access
 * on upgrade regardless of the matrix rows. Run-alone (no other migration
 * in parallel).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    private const ENTITIES = [ 'module_management' ];
    private const PERSONAS = [ 'academy_admin' ];

    public function getName(): string {
        return '0194_authorization_seed_module_management';
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
            if ( ! in_array( $row['persona'] ?? '', self::PERSONAS, true ) ) {
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
