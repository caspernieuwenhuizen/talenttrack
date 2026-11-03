<?php
/**
 * Migration 0315 — Report Studio is closed to the read-only observer
 * (#4695, epic #4687).
 *
 * Every Studio read now asks `report_studio` read at some scope as well as
 * `reports` read. The observer holds the second club-wide and never the
 * first. Three steps:
 *
 *   - `team_manager` / `report_studio`: read, team, from the seed. The team
 *     manager reads the reports published to them and builds none (building
 *     asks create/delete). A cell an academy already decided keeps its value
 *     (`MigrationHelpers::matrixCellChanged()`, the rule 0310 and 0312 use).
 *     INSERT IGNORE on the unique key.
 *   - `readonly_observer` rows on `report_studio` and `report_publishing`
 *     are removed. The seed never granted them; the Studio is closed to the
 *     seat whatever the matrix was edited to.
 *   - Publications to the `readonly_observer` persona are removed. A report
 *     published to the observer and to other personas stays published to
 *     the others.
 *
 * A second run finds nothing to do. Only the observer's cells and the team
 * manager's `report_studio` cell are touched. Run-alone (no other migration
 * in parallel).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;
use TT\Infrastructure\Database\MigrationHelpers;
use TT\Modules\Authorization\Matrix\MatrixRepository;

return new class extends Migration {

    /** The observer's cells the migration removes. */
    public const OBSERVER_ENTITIES = [ 'report_studio', 'report_publishing' ];

    public function getName(): string {
        return '0315_report_studio_closed_to_observer';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $written = 0;
        $notes   = [];

        $matrix = "{$p}tt_authorization_matrix";
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $matrix ) ) === $matrix ) {
            $written += $this->addTeamManagerRead( $matrix, $notes );

            $removed = 0;
            foreach ( self::OBSERVER_ENTITIES as $entity ) {
                $removed += $this->exec( $wpdb->prepare(
                    "DELETE FROM {$matrix} WHERE persona = %s AND entity = %s",
                    'readonly_observer',
                    $entity
                ) );
            }
            $written += $removed;
            $notes[]  = sprintf( '%d observer Studio row(s) removed', $removed );
        } else {
            $notes[] = 'tt_authorization_matrix is missing';
        }

        $publications = "{$p}tt_report_publications";
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $publications ) ) === $publications ) {
            $unpublished = $this->exec( $wpdb->prepare(
                "DELETE FROM {$publications} WHERE persona_key = %s",
                'readonly_observer'
            ) );
            $written += $unpublished;
            $notes[]  = sprintf( '%d publication(s) to the observer removed', $unpublished );
        } else {
            $notes[] = 'tt_report_publications is missing';
        }

        $this->report( $written, implode( '; ', $notes ) );

        if ( class_exists( MatrixRepository::class ) ) {
            MatrixRepository::clearCache();
        }
    }

    /**
     * @param list<string> $notes
     */
    private function addTeamManagerRead( string $matrix, array &$notes ): int {
        global $wpdb;

        $seed_path = TT_PLUGIN_DIR . 'config/authorization_seed.php';
        if ( ! is_readable( $seed_path ) ) {
            $notes[] = 'the authorization seed is not readable';
            return 0;
        }
        $rows = require $seed_path;
        if ( ! is_array( $rows ) ) {
            $notes[] = 'the authorization seed did not parse';
            return 0;
        }

        if ( MigrationHelpers::matrixCellChanged( 'team_manager', 'report_studio' ) ) {
            $notes[] = 'the team manager\'s Studio cell was already set and was kept';
            return 0;
        }

        $insert = "INSERT IGNORE INTO {$matrix}
                     (persona, entity, activity, scope_kind, module_class, is_default)
                   VALUES (%s, %s, %s, %s, %s, 1)";

        $written = 0;
        foreach ( $rows as $row ) {
            if ( (string) ( $row['persona'] ?? '' ) !== 'team_manager' ) continue;
            if ( (string) ( $row['entity'] ?? '' ) !== 'report_studio' ) continue;
            if ( (string) ( $row['activity'] ?? '' ) !== 'read' ) continue;

            $written += $this->exec( $wpdb->prepare(
                $insert,
                'team_manager',
                'report_studio',
                'read',
                (string) $row['scope_kind'],
                (string) $row['module_class']
            ) );
        }
        $notes[] = $written > 0 ? 'added the team manager\'s Studio read' : 'no team manager Studio read to add';
        return $written;
    }
};
