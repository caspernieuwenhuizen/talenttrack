<?php
/**
 * Migration: 0063_authorization_seed_topup_0079
 *
 * #0079 follow-up — backfill `tt_authorization_matrix` with the 10 new
 * tile-visibility entities introduced in v3.91.0:
 *   team_roster_panel, coach_player_list_panel, people_directory_panel,
 *   evaluations_panel, activities_panel, goals_panel, podium_panel,
 *   team_chemistry_panel, pdp_panel, wp_admin_portal.
 *
 * Why this is needed:
 *   - v3.91.0 added the entities to `config/authorization_seed.php`, but
 *     the seed file is only loaded into `tt_authorization_matrix` on
 *     fresh install (migration 0026) or via the admin "Reset to
 *     defaults" button. Existing installs that updated to v3.91.0
 *     therefore have the new tile gates pointing at matrix rows that
 *     don't exist — the lookup returns false and FR-assigned coaches
 *     stay locked out of the coach-side dashboard despite the migration
 *     0062 scope-row backfill having run successfully.
 *   - This migration walks the seed file the same way 0035 did and
 *     `INSERT IGNORE`s every (persona, entity, activity, scope_kind)
 *     tuple. Existing rows (including operator-edited ones) are left
 *     untouched.
 *
 * Idempotent. Safe to re-run on already-backfilled installs.
 *
 * Bug found on the pilot install: head-coach Kevin Raes (linked WP user
 * + FR assignment + scope row all in place) saw only Methodologie +
 * Analytics tiles after v3.91.0. Root cause: matrix lookup for
 * `team_roster_panel` returned false because the seed entries hadn't
 * landed in the live table.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0063_authorization_seed_topup_0079';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $table = "{$p}tt_authorization_matrix";
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            // Matrix table doesn't exist yet (migration 0026 hasn't run).
            // Nothing to backfill — the initial seed will pick up the
            // new entities when it runs.
            return;
        }

        $seed_path = TT_PLUGIN_DIR . 'config/authorization_seed.php';
        if ( ! is_readable( $seed_path ) ) return;

        $rows = require $seed_path;
        if ( ! is_array( $rows ) ) return;

        // INSERT IGNORE on the unique key (persona, entity, activity,
        // scope_kind) so existing rows — including any an admin
        // customised on the matrix admin page — stay untouched. Only
        // the new tuples land.
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
