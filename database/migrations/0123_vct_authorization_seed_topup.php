<?php
/**
 * Migration 0123 — VCT authorization-seed top-up (#907, VCT-2, epic #905).
 *
 * The matrix table (`tt_authorization_matrix`) is seeded only on fresh
 * installs or when an operator clicks "Reset to defaults" — see migration
 * 0026's `seedIfEmpty()` guard. So adding the new VCT rows to
 * `config/authorization_seed.php` (head_coach + assistant_coach get `vct`
 * at team scope; head_of_development + academy_admin get `vct` +
 * `vct_library` + `vct_workload` globally) doesn't reach existing
 * installs unless we backfill explicitly. This migration walks the seed
 * file and `INSERT IGNORE`s every (persona, entity) tuple whose entity
 * is one of the three VCT entities. Existing operator-edited rows stay
 * untouched (UNIQUE key suppresses re-insert); only the new VCT rows
 * land. Same pattern as migration 0069 for player_notes.
 *
 * After the migration runs, the matrix cache is invalidated so the new
 * grants take effect on the next request without a full rebuild cycle
 * (per the spec: "AuthorizationModule::flushCaches() is invoked so
 * bridges take effect immediately").
 *
 * Idempotent — re-running on already-backfilled installs is a no-op.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;
use TT\Modules\Authorization\Matrix\MatrixRepository;

return new class extends Migration {

    public function getName(): string {
        return '0123_vct_authorization_seed_topup';
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

        $vct_entities = [ 'vct', 'vct_library', 'vct_workload' ];
        $sql = "INSERT IGNORE INTO {$table}
                  (persona, entity, activity, scope_kind, module_class, is_default)
                VALUES (%s, %s, %s, %s, %s, 1)";

        foreach ( $rows as $row ) {
            if ( ! in_array( ( $row['entity'] ?? '' ), $vct_entities, true ) ) continue;
            $wpdb->query( $wpdb->prepare(
                $sql,
                (string) $row['persona'],
                (string) $row['entity'],
                (string) $row['activity'],
                (string) $row['scope_kind'],
                (string) $row['module_class']
            ) );
        }

        if ( class_exists( MatrixRepository::class ) ) {
            MatrixRepository::clearCache();
        }
    }
};
