<?php
/**
 * Migration 0309 — the Strava club console gets its own matrix entity.
 *
 * The club console (the connections list, the webhook subscription, the
 * app credentials and `?tt_view=strava-admin`) is configuration, not a
 * player's own Strava connection. `tt_view_strava` and
 * `tt_edit_strava_credentials` now ask `strava_operator_console` at global
 * scope, and `strava_integration` stays the entity for a player's own
 * connection. The seed carries the new rows for academy_admin (read,
 * change, create_delete, global), head_of_development (read, global) and
 * head_coach (read, team); the matrix reseed never runs on upgrade, so
 * this adds them to existing installs.
 *
 * Idempotent: INSERT IGNORE on the unique key (persona, entity, activity,
 * scope_kind) only adds what is missing, and an operator-edited row is
 * left alone. Scoped to the one entity and the three personas. Adds rows
 * and deletes none. Run-alone (no other migration in parallel).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    private const ENTITY   = 'strava_operator_console';
    private const PERSONAS = [ 'academy_admin', 'head_of_development', 'head_coach' ];

    public function getName(): string {
        return '0309_authorization_strava_operator_console';
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

        $insert = "INSERT IGNORE INTO {$table}
                     (persona, entity, activity, scope_kind, module_class, is_default)
                   VALUES (%s, %s, %s, %s, %s, 1)";

        foreach ( $rows as $row ) {
            if ( (string) ( $row['entity'] ?? '' ) !== self::ENTITY ) continue;
            if ( ! in_array( (string) ( $row['persona'] ?? '' ), self::PERSONAS, true ) ) continue;

            $this->exec( $wpdb->prepare(
                $insert,
                (string) $row['persona'],
                (string) $row['entity'],
                (string) $row['activity'],
                (string) $row['scope_kind'],
                (string) $row['module_class']
            ) );
        }
    }
};
