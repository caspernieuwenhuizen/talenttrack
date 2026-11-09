<?php
/**
 * Migration 0321 — the read-only observer opens the evaluations list
 * (#4997).
 *
 * `evaluations_panel` is the tile and dispatch entity of
 * `?tt_view=evaluations`. The observer reads `evaluations` already, so
 * `GET /evaluations` answered while the list said "not authorized". The
 * panel was held back until the evaluation notes were masked for this seat
 * (#4881); the owner decided in #4904 that the observer sees the list.
 *
 * The seed carries `readonly_observer` / `evaluations_panel`: read, global.
 * The matrix reseed never runs on upgrade, so this adds the row to existing
 * installs. A cell an academy already decided keeps its value
 * (`MigrationHelpers::matrixCellChanged()`, the rule 0310 and 0312 use).
 * INSERT IGNORE on the unique key, so a second run writes nothing. Only the
 * `read` row is written, whatever the seed file later says, and nothing is
 * deleted. Run-alone (no other migration in parallel).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;
use TT\Infrastructure\Database\MigrationHelpers;
use TT\Modules\Authorization\Matrix\MatrixRepository;

return new class extends Migration {

    public const PERSONA = 'readonly_observer';
    public const ENTITY  = 'evaluations_panel';

    public function getName(): string {
        return '0321_authorization_observer_evaluations_panel';
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

        if ( MigrationHelpers::matrixCellChanged( self::PERSONA, self::ENTITY ) ) {
            $this->report( 0, 'the observer evaluations_panel cell is already set and was kept' );
            return;
        }

        $insert = "INSERT IGNORE INTO {$table}
                     (persona, entity, activity, scope_kind, module_class, is_default)
                   VALUES (%s, %s, %s, %s, %s, 1)";

        $written = 0;
        foreach ( $rows as $row ) {
            if ( (string) ( $row['persona'] ?? '' ) !== self::PERSONA ) continue;
            if ( (string) ( $row['entity'] ?? '' ) !== self::ENTITY ) continue;
            if ( (string) ( $row['activity'] ?? '' ) !== 'read' ) continue;

            $written += $this->exec( $wpdb->prepare(
                $insert,
                self::PERSONA,
                self::ENTITY,
                'read',
                (string) $row['scope_kind'],
                (string) $row['module_class']
            ) );
        }

        $this->report(
            $written,
            $written > 0 ? 'added the observer evaluations_panel read row' : 'no observer evaluations_panel read row to add'
        );

        if ( class_exists( MatrixRepository::class ) ) {
            MatrixRepository::clearCache();
        }
    }
};
