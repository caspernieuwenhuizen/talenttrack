<?php
/**
 * Migration 0310 — the read-only observer reads the non-sensitive entities
 * (#4688, epic #4687).
 *
 * Owner decision, 2 November 2026: the observer reads everything that is
 * not sensitive, read-only, and sensitive fields are masked through
 * `RecordRedactor`. The seed carries global `read` for `readonly_observer`
 * on the entities in `ENTITIES`; the matrix reseed never runs on upgrade,
 * so this adds the rows to existing installs.
 *
 * An academy that changed one of these observer cells keeps its value. A
 * cell counts as changed when the matrix already holds a row for the
 * observer on that entity (at any scope), or when the authorization
 * changelog records an edit to it after the last reset or import. In
 * either case the cell is left exactly as it is.
 *
 * Idempotent: INSERT IGNORE on the unique key (persona, entity, activity,
 * scope_kind), and a second run finds the row and skips the cell. Scoped
 * to the one persona, the listed entities and `read`: the seat writes
 * nothing, whatever the seed file later says. Adds rows and deletes none.
 * Run-alone (no other migration in parallel).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;
use TT\Modules\Authorization\Matrix\MatrixRepository;

return new class extends Migration {

    private const PERSONA = 'readonly_observer';

    /** Keep equal to the second observer block in `config/authorization_seed.php`. */
    public const ENTITIES = [
        'player_tournaments',
        'seasons',
        'methodology',
        'football_actions',
        'vct_library',
        'vct_workload',
        'exercises',
        'evaluation_categories',
        'category_weights',
        'rating_scale',
        'goals_panel',
        'holidays',
        'documentation',
    ];

    public function getName(): string {
        return '0310_authorization_observer_reads_non_sensitive';
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
        $kept    = 0;
        foreach ( $rows as $row ) {
            if ( (string) ( $row['persona'] ?? '' ) !== self::PERSONA ) continue;
            if ( (string) ( $row['activity'] ?? '' ) !== 'read' ) continue;
            $entity = (string) ( $row['entity'] ?? '' );
            if ( ! in_array( $entity, self::ENTITIES, true ) ) continue;

            if ( $this->cellChanged( $entity ) ) {
                $kept++;
                continue;
            }

            $written += $this->exec( $wpdb->prepare(
                $insert,
                self::PERSONA,
                $entity,
                'read',
                (string) $row['scope_kind'],
                (string) $row['module_class']
            ) );
        }

        $this->report(
            $written,
            $written > 0
                ? sprintf( 'added the observer read rows; %d cell(s) already set were kept', $kept )
                : sprintf( 'no observer read row to add; %d cell(s) already set were kept', $kept )
        );

        if ( class_exists( MatrixRepository::class ) ) {
            MatrixRepository::clearCache();
        }
    }

    /**
     * Has the matrix, or an academy, already decided this observer cell?
     */
    private function cellChanged( string $entity ): bool {
        global $wpdb;
        $p = $wpdb->prefix;

        $held = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}tt_authorization_matrix WHERE persona = %s AND entity = %s",
            self::PERSONA,
            $entity
        ) );
        if ( $held > 0 ) return true;

        $changelog = "{$p}tt_authorization_changelog";
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $changelog ) ) !== $changelog ) {
            return false;
        }

        // A reset or an import replaces the whole matrix, so an edit made
        // before the latest one no longer stands.
        $since = (int) $wpdb->get_var(
            "SELECT COALESCE( MAX(id), 0 ) FROM {$changelog} WHERE change_type IN ( 'reset', 'bulk_import' )"
        );

        $edits = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$changelog} WHERE persona = %s AND entity = %s AND id > %d",
            self::PERSONA,
            $entity,
            $since
        ) );
        return $edits > 0;
    }
};
