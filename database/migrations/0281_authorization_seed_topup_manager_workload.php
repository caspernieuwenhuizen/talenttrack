<?php
/**
 * Migration: 0281_authorization_seed_topup_manager_workload
 *
 * #3808 — the team manager could read `minutes` (what has been played) and
 * nothing at all about `vct_workload` (what is planned), so the two halves
 * of the availability conversation sat in different rooms. The seed now
 * carries `vct_workload [read, team]` and `training_exposure [read, team]`
 * for the `team_manager` persona.
 *
 * The seed file is only read at install time; every gate reads the live
 * matrix table. Without this top-up an existing install keeps the 403.
 *
 * ## Why this one reports
 *
 * #3854 found that 0276, 0277 and 0278 each open with guards that `return`
 * without writing and **without saying so** — a missing table, an unreadable
 * seed, a seed that did not parse — after which the runner records the
 * migration as run. A clean run and a failed one were indistinguishable, and
 * that is precisely why nobody could tell whether #3706's grant had ever
 * reached an installed matrix.
 *
 * `Migration::report()` exists for that, and this is the first top-up
 * written on top of it. Every exit path says what it did.
 *
 * Scoped to the exact tuples this change adds, INSERT IGNORE on the unique
 * key, so an operator-edited row is never touched and a second run adds
 * nothing.
 *
 * The `manager` FUNCTIONAL ROLE half needs no migration: functional-role
 * grants are read from `config/functional_role_grants.php` at runtime, not
 * stored in the matrix table.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    /**
     * The exact (persona, entity) pairs this migration may write.
     * Activity is always `read`.
     *
     * @var array<int, array{0:string,1:string}>
     */
    private const GRANTS = [
        [ 'team_manager', 'vct_workload' ],
        [ 'team_manager', 'training_exposure' ],
    ];

    public function getName(): string {
        return '0281_authorization_seed_topup_manager_workload';
    }

    public function up(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_authorization_matrix';

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            $this->report( 0, 'tt_authorization_matrix is missing' );
            return;
        }

        $seed_path = TT_PLUGIN_DIR . 'config/authorization_seed.php';
        if ( ! is_readable( $seed_path ) ) {
            $this->report( 0, 'authorization_seed.php is not readable' );
            return;
        }

        $rows = require $seed_path;
        if ( ! is_array( $rows ) ) {
            $this->report( 0, 'authorization_seed.php did not return an array' );
            return;
        }

        $sql = "INSERT IGNORE INTO {$table}
                  (persona, entity, activity, scope_kind, module_class, is_default)
                VALUES (%s, %s, %s, %s, %s, 1)";

        $written = 0;
        $seen    = 0;
        foreach ( $rows as $row ) {
            $persona = (string) ( $row['persona'] ?? '' );
            $entity  = (string) ( $row['entity'] ?? '' );
            if ( (string) ( $row['activity'] ?? '' ) !== 'read' ) continue;
            if ( ! in_array( [ $persona, $entity ], self::GRANTS, true ) ) continue;

            $seen++;
            $written += (int) $wpdb->query( $wpdb->prepare(
                $sql,
                $persona,
                $entity,
                'read',
                (string) $row['scope_kind'],
                (string) $row['module_class']
            ) );
        }

        // INSERT IGNORE reports 0 for a row already present, so 0 written
        // with both grants seen is the healthy repeat run. 0 seen means the
        // seed no longer carries them, which is the case worth knowing about.
        $this->report( $written, sprintf( '%d of %d grant(s) found in the seed', $seen, count( self::GRANTS ) ) );

        if ( class_exists( '\\TT\\Modules\\Authorization\\Matrix\\MatrixRepository' ) ) {
            \TT\Modules\Authorization\Matrix\MatrixRepository::clearCache();
        }
    }
};
