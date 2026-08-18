<?php
/**
 * Migration 0214 — seed the `training_plan` matrix entity (#2496, epic #2493).
 *
 * The matrix reseed is a manual, destructive TRUNCATE+reinsert that never
 * runs on upgrade, so an already-installed site would never gain the new
 * `training_plan` rows — every coach would hold `tt_training_plan` as a raw
 * role capability and still be refused by the matrix. This top-up adds the
 * missing grants, mirroring 0190_measurements / 0191_strava /
 * 0193_player_strava / 0194_module_management.
 *
 * Idempotent / re-runnable. INSERT IGNORE on the unique key
 * (persona, entity, activity, scope_kind) leaves any operator-edited rows
 * untouched and only adds the missing tuples. Scoped to the single
 * `training_plan` entity so it never touches another. WP administrators
 * bypass every tt_* cap unconditionally (LegacyCapMapper), so no
 * administrator depends on these rows. Run-alone (no other migration in
 * parallel).
 *
 * Personas seeded, straight from config/authorization_seed.php:
 *   assistant_coach / head_coach    rcd at team scope
 *   head_of_development / academy_admin  rcd at global scope
 *
 * Team manager and scout are deliberately absent. A team manager
 * administers a squad and a scout assesses players; neither builds
 * training. They read what was trained through the player file once
 * #2500 ships, which is a different entity.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    private const ENTITIES = [ 'training_plan' ];

    public function getName(): string {
        return '0214_authorization_seed_training_plan';
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
