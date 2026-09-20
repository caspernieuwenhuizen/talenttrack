<?php
/**
 * Migration: 0276_authorization_seed_topup_coach_formation_load
 *
 * #3706 — the assistant coach could not read their own team's formation,
 * blueprint or chemistry board (`team_chemistry` was removed from the
 * persona by #1060), and neither coach persona could read the team's
 * training load (`vct_workload` existed only at global scope for head of
 * development and academy admin). The seed now carries
 * `team_chemistry [read, team]` for the assistant coach and
 * `vct_workload [read, team]` for both coach personas.
 *
 * The seed file is only read at install time; every gate reads the live
 * matrix table. Without this top-up an existing install keeps the 403.
 * Same shape as 0272: scoped to the exact tuples this change adds,
 * INSERT IGNORE on the unique key, so an operator-edited row is never
 * touched and a second run adds nothing.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    /**
     * The exact (persona, entity) pairs this migration is allowed to write.
     * Activity is always `read`.
     *
     * @var array<int, array{0:string,1:string}>
     */
    private const GRANTS = [
        [ 'assistant_coach', 'team_chemistry' ],
        [ 'assistant_coach', 'vct_workload' ],
        [ 'head_coach',      'vct_workload' ],
    ];

    public function getName(): string {
        return '0276_authorization_seed_topup_coach_formation_load';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $table = "{$p}tt_authorization_matrix";
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            // #3854 — every exit path says what happened. These three
            // returns used to be silent, which made a missing table
            // indistinguishable from a clean run afterwards.
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

        // INSERT IGNORE reports 0 for a row that is already there, so 0
        // written with all three grants seen is the healthy repeat run.
        // 0 written with 0 seen means the seed no longer carries them,
        // which is the case worth knowing about.
        $this->report( $written, sprintf( '%d of %d grant(s) found in the seed', $seen, count( self::GRANTS ) ) );

        if ( class_exists( '\\TT\\Modules\\Authorization\\Matrix\\MatrixRepository' ) ) {
            \TT\Modules\Authorization\Matrix\MatrixRepository::clearCache();
        }
    }
};
