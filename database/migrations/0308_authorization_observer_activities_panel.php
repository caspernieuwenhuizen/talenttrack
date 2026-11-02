<?php
/**
 * Migration 0308 — the read-only observer opens the activities panel
 * (#4661).
 *
 * The observer reads `activities` at global scope, but the dashboard
 * dispatches `?tt_view=activities` on `activities_panel`, which the seat
 * did not hold. So it could open neither the activities list nor an
 * activity's page, and the "View match prep" entry on that page was out
 * of its reach. The seed now carries `activities_panel` read at global
 * scope for `readonly_observer`; the matrix reseed never runs on upgrade,
 * so this adds the row to existing installs.
 *
 * Idempotent: INSERT IGNORE on the unique key (persona, entity, activity,
 * scope_kind) only adds what is missing, and an operator-edited row is
 * left alone. Scoped to the one persona and entity, and to `read`: the
 * seat writes nothing, whatever the seed file later says. Adds rows and
 * deletes none. Run-alone (no other migration in parallel).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    private const PERSONA = 'readonly_observer';
    private const ENTITY  = 'activities_panel';

    public function getName(): string {
        return '0308_authorization_observer_activities_panel';
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
            if ( (string) ( $row['persona'] ?? '' ) !== self::PERSONA ) continue;
            if ( (string) ( $row['entity'] ?? '' ) !== self::ENTITY ) continue;
            if ( (string) ( $row['activity'] ?? '' ) !== 'read' ) continue;

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
