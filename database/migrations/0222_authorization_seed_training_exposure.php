<?php
/**
 * Migration 0222 — seed the `training_exposure` matrix entity
 * (#2500, epic #2493).
 *
 * The matrix reseed is a manual, destructive TRUNCATE+reinsert that never
 * runs on upgrade, so an already-installed site would never gain the new
 * `training_exposure` rows and every coach would be refused the player's
 * training tab. This top-up adds the missing grants, mirroring
 * 0190_measurements / 0191_strava / 0193_player_strava /
 * 0194_module_management / 0214_training_plan.
 *
 * Idempotent / re-runnable. INSERT IGNORE on the unique key
 * (persona, entity, activity, scope_kind) leaves any operator-edited rows
 * untouched and only adds the missing tuples. Scoped to the single
 * `training_exposure` entity so it never touches another. WP
 * administrators bypass every tt_* cap unconditionally, so no
 * administrator depends on these rows. Run-alone.
 *
 * Personas seeded, straight from config/authorization_seed.php (D16):
 *   assistant_coach / head_coach          read at team scope
 *   head_of_development / academy_admin   read at global scope
 *   parent                                read at player scope
 *
 * Read-only for everyone, because exposure is derived from runs — there
 * is nothing to author, and a writable aggregate would just be a way to
 * make a player's history say something it did not.
 *
 * Absent on purpose:
 *   - **player** — a minor reading a per-principle ledger of their own
 *     shortfalls is a coaching conversation, not a dashboard.
 *   - **scout** and **team_manager** — neither is part of a player's
 *     development picture. If that changes it is a product decision with
 *     its own migration, not a quiet widening here.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    private const ENTITIES = [ 'training_exposure' ];

    public function getName(): string {
        return '0222_authorization_seed_training_exposure';
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
