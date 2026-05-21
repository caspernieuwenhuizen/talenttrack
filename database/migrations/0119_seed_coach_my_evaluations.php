<?php
/**
 * Migration 0119 — backfill `my_evaluations × read × self` for the
 * `head_coach` and `assistant_coach` personas in the authorization
 * matrix (#846).
 *
 * Pilot 2026-05-21: a `tt_coach` user clicks the "My evaluations this
 * week" KPI tile on their coach dashboard and lands on a "Not
 * authorized" page. Root cause: `config/authorization_seed.php` only
 * granted `my_evaluations` to `player` and `parent` personas (the
 * KPI tile was added to the coach dashboard template without the
 * matching seed grant). The matrix gate returned false → deny.
 *
 * Fix is two-layer: the seed file gets the grant for fresh installs
 * (this PR also edits `config/authorization_seed.php`), and this
 * migration backfills the same row on existing installs whose matrix
 * is already seeded. Without the migration, upgraded installs would
 * stay on the broken state until someone manually re-ran the seed.
 *
 * Idempotent — `INSERT IGNORE` on the unique key + `is_default = 1`
 * guard so re-runs touch nothing and operator-edited rows are
 * preserved.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0119_seed_coach_my_evaluations';
    }

    public function up(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_authorization_matrix';

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) return;

        $module_class = 'TT\\Modules\\Evaluations\\EvaluationsModule';

        $rows = [
            // (persona, entity, activity, scope_kind)
            [ 'head_coach',      'my_evaluations', 'read', 'self' ],
            [ 'assistant_coach', 'my_evaluations', 'read', 'self' ],
        ];

        foreach ( $rows as $r ) {
            $wpdb->query( $wpdb->prepare(
                "INSERT IGNORE INTO {$table}
                   (persona, entity, activity, scope_kind, module_class, is_default)
                 VALUES (%s, %s, %s, %s, %s, 1)",
                $r[0], $r[1], $r[2], $r[3], $module_class
            ) );
        }
    }
};
