<?php
/**
 * Migration 0333 — the read-only observer reads the trials, masked
 * (#4694, PR b).
 *
 * The seed carries `readonly_observer` read at global scope on the six
 * trial entities the product owner named. `trial_cases` read at global
 * scope lists and opens every case read-only
 * (`TrialCaseAccessPolicy::readsEveryCase()`); the case notes, extension
 * justifications and input notes are masked for this seat
 * (`seesCaseText()`). The synthesis stays with the panel, and decisions,
 * letters and every write stay with `tt_manage_trials`.
 *
 * The matrix reseed never runs on upgrade, so this adds the rows to existing
 * installs. A cell an academy already decided keeps its value
 * (`MigrationHelpers::matrixCellChanged()`, the rule 0310, 0312 and 0321
 * use). INSERT IGNORE on the unique key, so a second run writes nothing.
 * Only `read` rows are written, and nothing is deleted. Run-alone.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;
use TT\Infrastructure\Database\MigrationHelpers;
use TT\Modules\Authorization\Matrix\MatrixRepository;

return new class extends Migration {

    public const PERSONA  = 'readonly_observer';
    public const ENTITIES = [ 'trial_cases', 'trial_inputs', 'trial_synthesis', 'trial_decisions', 'trial_letters_generated' ];

    public function getName(): string {
        return '0333_authorization_observer_reads_trials';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $table = "{$p}tt_authorization_matrix";
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            $this->report( 0, 'tt_authorization_matrix is missing' );
            return;
        }

        $seed_path = TT_PLUGIN_DIR . 'config/authorization_seed.php';
        if ( ! is_readable( $seed_path ) ) {
            $this->report( 0, 'the authorization seed is not readable' );
            return;
        }

        $rows = require $seed_path;
        if ( ! is_array( $rows ) ) {
            $this->report( 0, 'the authorization seed did not parse' );
            return;
        }

        $insert = "INSERT IGNORE INTO {$table}
                     (persona, entity, activity, scope_kind, module_class, is_default)
                   VALUES (%s, %s, %s, %s, %s, 1)";

        $written = 0;
        $notes   = [];
        foreach ( self::ENTITIES as $entity ) {
            if ( MigrationHelpers::matrixCellChanged( self::PERSONA, $entity ) ) {
                $notes[] = $entity . ': already set, kept';
                continue;
            }
            $added = 0;
            foreach ( $rows as $row ) {
                if ( (string) ( $row['persona'] ?? '' ) !== self::PERSONA ) continue;
                if ( (string) ( $row['entity'] ?? '' ) !== $entity ) continue;
                if ( (string) ( $row['activity'] ?? '' ) !== 'read' ) continue;

                $added += $this->exec( $wpdb->prepare(
                    $insert,
                    self::PERSONA,
                    $entity,
                    'read',
                    (string) $row['scope_kind'],
                    (string) $row['module_class']
                ) );
            }
            $written += $added;
            $notes[]  = $entity . ': ' . ( $added > 0 ? 'added the read row' : 'no read row to add' );
        }

        $this->report( $written, implode( '; ', $notes ) );

        if ( class_exists( MatrixRepository::class ) ) {
            MatrixRepository::clearCache();
        }
    }
};
