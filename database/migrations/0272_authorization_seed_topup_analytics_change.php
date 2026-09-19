<?php
/**
 * Migration: 0272_authorization_seed_topup_analytics_change
 *
 * #3610 — the evaluation windows were writable by anyone who could read
 * analytics: `PUT eval-coverage/windows` and the windows form both checked
 * `tt_view_analytics`. They now check `tt_edit_analytics`, bridged to
 * `analytics:change`, and the seed gives that to the two personas that set
 * windows today, head of development and academy admin.
 *
 * The seed file is only read at install time; every gate reads the live
 * matrix table. Without this top-up, an existing install's HoD and admin
 * would lose the ability to set windows the moment the gate moved. Same
 * shape as 0249: scoped to the one tuple this change adds, INSERT IGNORE on
 * the unique key, so an operator-edited row is never touched and a second
 * run adds nothing.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    /** Personas this migration is allowed to write. */
    private const PERSONAS = [ 'head_of_development', 'academy_admin' ];

    public function getName(): string {
        return '0272_authorization_seed_topup_analytics_change';
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
            if ( ! in_array( (string) ( $row['persona'] ?? '' ), self::PERSONAS, true ) ) continue;
            if ( (string) ( $row['entity'] ?? '' ) !== 'analytics' ) continue;
            if ( (string) ( $row['activity'] ?? '' ) !== 'change' ) continue;

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
