<?php
/**
 * Migration 0104 — upgrade scout × prospects matrix rows from
 * `self` to `global` (v3.110.154 policy change).
 *
 * Original policy (#0081, v3.95.x): scouts saw only their own
 * prospects via `'rcd', 'self'`. Pilot follow-up: two scouts
 * working the same age group need to see each other's prospects so
 * they don't duplicate visits or step on each other's outreach.
 * Seed change in `config/authorization_seed.php` flips the new
 * default to `global`. This migration walks existing installs and
 * upgrades the seeded rows in-place — only where `is_default = 1`,
 * so operator-edited rows (the matrix tracks customisations via
 * that flag) keep whatever scope the admin manually set.
 *
 * Touches three rows: `scout × prospects × {r, c, d} × self`.
 *
 * Idempotent. Re-run is a no-op because the UPDATE's WHERE only
 * matches rows still at the old shape.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0104_scout_prospects_scope_to_global';
    }

    public function up(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_authorization_matrix';

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) return;

        $wpdb->query( $wpdb->prepare(
            "UPDATE {$table}
                SET scope_kind = %s
              WHERE persona      = %s
                AND entity       = %s
                AND activity     IN ('r', 'c', 'd')
                AND scope_kind   = %s
                AND is_default   = 1",
            'global',
            'scout',
            'prospects',
            'self'
        ) );
    }
};
