<?php
/**
 * Migration: 0249_authorization_seed_topup_observer_and_staff
 *
 * #3177 — backfill the `readonly_observer` and `staff` persona rows into
 * `tt_authorization_matrix` on existing installs.
 *
 * The seed file is only read at install time; every gate reads the live
 * matrix table. So without this migration the two personas exist in
 * `config/authorization_seed.php` and nowhere that matters, and every
 * install that already ran its migrations keeps the bug the issue
 * describes: a Read-Only Observer sees an empty team list and empty
 * pickers, and a `tt_staff` user on a matrix-active install is denied
 * every capability their role grants.
 *
 * Same shape as `0233_authorization_seed_topup_scouting_visits_panel`,
 * and for the same reason — scoped to the new personas only, so it can
 * never re-add a row an operator deliberately removed for somebody else.
 *
 * Access-preserving in the direction that matters. It grants the matrix
 * what the capability bridge already grants:
 *
 *   readonly_observer — read at global scope on team, players, people,
 *   evaluations, activities, goals, reports, settings. Exactly the eight
 *   `RolesService::VIEW_CAPS` maps to, and no write verb anywhere.
 *
 *   staff — read/change at team scope on team (read only), players,
 *   people and player_notes, plus my_person at self scope. NOT
 *   `players:create_delete`: `tt_manage_players` gates season rollover,
 *   player-account provisioning, custom fields and deletion, which is
 *   not a kit manager's surface.
 *
 * Idempotent / re-runnable. INSERT IGNORE on the unique key
 * (persona, entity, activity, scope_kind) leaves operator-edited rows
 * untouched and only adds the missing tuples.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    /** Personas this migration is allowed to write. */
    private const PERSONAS = [ 'readonly_observer', 'staff' ];

    public function getName(): string {
        return '0249_authorization_seed_topup_observer_and_staff';
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
            if ( ! in_array( (string) ( $row['persona'] ?? '' ), self::PERSONAS, true ) ) {
                continue;
            }

            // Belt and braces on the read-only promise: even if the seed
            // file is later edited to give the observer a write verb, this
            // migration will not be the thing that writes it to an
            // existing install.
            if ( (string) $row['persona'] === 'readonly_observer'
                && (string) ( $row['activity'] ?? '' ) !== 'read' ) {
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
