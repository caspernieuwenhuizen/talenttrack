<?php
/**
 * Migration 0312 — the head of development reads settings; the read-only
 * observer reads the player timeline and cohort transitions (#4795, epic
 * #4687).
 *
 * The seed carries three rows the matrix reseed never brings to an existing
 * install:
 *
 *   - `head_of_development` / `settings`: read, global. The HoD block's own
 *     comment says settings were narrowed to R-only, and the row was
 *     missing. HoD reads Configuration and changes none of it; the System
 *     section and the access matrix ask rights this does not grant.
 *   - `readonly_observer` / `player_timeline` and `cohort_transitions`:
 *     read, global. A PDP verdict entry follows the PDP verdict right on
 *     every journey surface, and the cohort route asks `cohort_transitions`
 *     read.
 *
 * A cell an academy already decided keeps its value
 * (`MigrationHelpers::matrixCellChanged()`, the rule 0310 uses). INSERT
 * IGNORE on the unique key, so a second run writes nothing. Only `read`
 * rows are written, whatever the seed file later says, and nothing is
 * deleted. Run-alone (no other migration in parallel).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;
use TT\Infrastructure\Database\MigrationHelpers;
use TT\Modules\Authorization\Matrix\MatrixRepository;

return new class extends Migration {

    /** @var list<array{0:string,1:string}> persona, entity */
    public const CELLS = [
        [ 'head_of_development', 'settings' ],
        [ 'readonly_observer', 'player_timeline' ],
        [ 'readonly_observer', 'cohort_transitions' ],
    ];

    public function getName(): string {
        return '0312_authorization_hod_settings_observer_journey';
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

        $wanted = [];
        foreach ( self::CELLS as [ $persona, $entity ] ) {
            $wanted[ $persona . '|' . $entity ] = true;
        }

        $insert = "INSERT IGNORE INTO {$table}
                     (persona, entity, activity, scope_kind, module_class, is_default)
                   VALUES (%s, %s, %s, %s, %s, 1)";

        $written = 0;
        $kept    = 0;
        foreach ( $rows as $row ) {
            $persona = (string) ( $row['persona'] ?? '' );
            $entity  = (string) ( $row['entity'] ?? '' );
            if ( ! isset( $wanted[ $persona . '|' . $entity ] ) ) continue;
            if ( (string) ( $row['activity'] ?? '' ) !== 'read' ) continue;

            if ( MigrationHelpers::matrixCellChanged( $persona, $entity ) ) {
                $kept++;
                continue;
            }

            $written += $this->exec( $wpdb->prepare(
                $insert,
                $persona,
                $entity,
                'read',
                (string) $row['scope_kind'],
                (string) $row['module_class']
            ) );
        }

        $this->report(
            $written,
            $written > 0
                ? sprintf( 'added the read rows; %d cell(s) already set were kept', $kept )
                : sprintf( 'no read row to add; %d cell(s) already set were kept', $kept )
        );

        if ( class_exists( MatrixRepository::class ) ) {
            MatrixRepository::clearCache();
        }
    }
};
