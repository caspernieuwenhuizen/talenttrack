<?php
/**
 * Migration: 0233_authorization_seed_topup_scouting_visits_panel
 *
 * #2007 — backfill the new `scouting_visits_panel` tile-visibility entity
 * into `tt_authorization_matrix`.
 *
 * The Scouting visits tile used to declare the `prospects` entity, which
 * made it inseparable from the onboarding-pipeline funnel: a head coach
 * reads prospects at team scope on purpose (#0081, their own age group's
 * funnel) and was therefore handed the scout's outbound visit planner too.
 * The tile now hangs off its own visibility entity — the #0079 pattern —
 * seeded for scout, head_of_development and academy_admin.
 *
 * **Without this migration the tile disappears for everyone** on an
 * existing install: the entity would exist in the seed file but not in the
 * live matrix, and the dashboard's dispatch gate reads the live matrix. So
 * this is access-preserving in the direction that matters — it gives the
 * three personas back exactly what the `prospects` grant was giving them,
 * and leaves head_coach without it, which is the point.
 *
 * Scoped to the new entity only (same shape as
 * `0180_authorization_seed_topup_exercises`) so it never re-adds rows an
 * operator deliberately removed for other entities.
 *
 * Idempotent / re-runnable. INSERT IGNORE on the unique key
 * (persona, entity, activity, scope_kind) leaves operator-edited rows
 * untouched and only adds the missing tuples.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0233_authorization_seed_topup_scouting_visits_panel';
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
            if ( ( $row['entity'] ?? '' ) !== 'scouting_visits_panel' ) {
                continue;
            }
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
