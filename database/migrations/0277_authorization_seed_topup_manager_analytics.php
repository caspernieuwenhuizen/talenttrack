<?php
/**
 * Migration: 0277_authorization_seed_topup_manager_analytics
 *
 * #3770 — a team manager was refused the attendance-at-risk report for
 * their own team. The three attendance report routes gate on
 * `tt_view_analytics`, which bridges to `analytics: read`, and the seed
 * granted that to head of development and academy admin only. The seed
 * now carries `analytics [read, team]` for the `team_manager` persona.
 *
 * The seed file is only read at install time; every gate reads the live
 * matrix table. Without this top-up an existing install keeps the 403.
 * Same shape as 0272: scoped to the one tuple this change adds,
 * INSERT IGNORE on the unique key, so an operator-edited row is never
 * touched and a second run adds nothing.
 *
 * The sibling grant on the `manager` functional role needs no migration:
 * `config/functional_role_grants.php` is read at authorization time, not
 * seeded into a table.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    /** The one persona this migration is allowed to write. */
    private const PERSONA = 'team_manager';

    public function getName(): string {
        return '0277_authorization_seed_topup_manager_analytics';
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
            if ( (string) ( $row['persona'] ?? '' ) !== self::PERSONA ) continue;
            if ( (string) ( $row['entity'] ?? '' ) !== 'analytics' ) continue;
            if ( (string) ( $row['activity'] ?? '' ) !== 'read' ) continue;

            $wpdb->query( $wpdb->prepare(
                $sql,
                (string) $row['persona'],
                (string) $row['entity'],
                (string) $row['activity'],
                (string) $row['scope_kind'],
                (string) $row['module_class']
            ) );
        }

        if ( class_exists( '\\TT\\Modules\\Authorization\\Matrix\\MatrixRepository' ) ) {
            \TT\Modules\Authorization\Matrix\MatrixRepository::clearCache();
        }
    }
};
