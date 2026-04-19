<?php
/**
 * Migration 0004 — Schema reconciliation for v2.x columns.
 *
 * v2.6.4: Rewritten to use the legacy Migration base class pattern (matching
 * migrations 0001-0003) for maximum runner compatibility. All helper logic
 * is inlined here instead of delegating to an external MigrationHelpers class
 * — this keeps the migration self-contained and removes autoload timing
 * from the equation.
 *
 * Safe to re-run. Non-destructive. See v2.6.2 CHANGES.md for full context.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0004_schema_reconciliation';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        /* ═══ tt_evaluations ═══ */
        $this->addColumnIfMissing( "{$p}tt_evaluations", 'eval_type_id',   'BIGINT(20) UNSIGNED NULL', 'coach_id' );
        $this->addColumnIfMissing( "{$p}tt_evaluations", 'opponent',       'VARCHAR(255) NULL' );
        $this->addColumnIfMissing( "{$p}tt_evaluations", 'competition',    'VARCHAR(255) NULL' );
        $this->addColumnIfMissing( "{$p}tt_evaluations", 'match_result',   'VARCHAR(50) NULL' );
        $this->addColumnIfMissing( "{$p}tt_evaluations", 'home_away',      'VARCHAR(10) NULL' );
        $this->addColumnIfMissing( "{$p}tt_evaluations", 'minutes_played', 'SMALLINT(5) UNSIGNED NULL' );
        $this->addColumnIfMissing( "{$p}tt_evaluations", 'updated_at',     'DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP' );

        // Relax legacy NOT NULL constraints on category_id and rating so they
        // don't block v2.x inserts that don't populate these columns.
        $this->makeColumnNullable( "{$p}tt_evaluations", 'category_id', 'BIGINT(20) UNSIGNED NULL' );
        $this->makeColumnNullable( "{$p}tt_evaluations", 'rating',      'DECIMAL(3,1) NULL' );

        /* ═══ tt_attendance ═══ */
        $added_status = $this->addColumnIfMissing( "{$p}tt_attendance", 'status', "VARCHAR(50) NULL DEFAULT 'present'", 'player_id' );

        // Backfill status from legacy `present` tinyint if that column exists.
        if ( $added_status && $this->columnExists( "{$p}tt_attendance", 'present' ) ) {
            $wpdb->query( "UPDATE {$p}tt_attendance SET status='present' WHERE present=1 AND (status IS NULL OR status='')" );
            $wpdb->query( "UPDATE {$p}tt_attendance SET status='absent'  WHERE present=0 AND (status IS NULL OR status='')" );
        }

        /* ═══ tt_goals ═══ */
        $this->addColumnIfMissing( "{$p}tt_goals", 'priority', "VARCHAR(50) NULL DEFAULT 'medium'", 'status' );
    }

    /* ═══ inline helpers ═══ */

    private function columnExists( string $table, string $column ): bool {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( "SHOW COLUMNS FROM `$table` LIKE %s", $column ) );
        return $row !== null;
    }

    private function addColumnIfMissing( string $table, string $column, string $definition, string $after = '' ): bool {
        global $wpdb;
        if ( $this->columnExists( $table, $column ) ) {
            return true;
        }
        $after_clause = $after !== '' && $this->columnExists( $table, $after ) ? " AFTER `$after`" : '';
        $sql = "ALTER TABLE `$table` ADD COLUMN `$column` $definition{$after_clause}";
        $result = $wpdb->query( $sql );
        return $result !== false;
    }

    private function makeColumnNullable( string $table, string $column, string $definition ): bool {
        global $wpdb;
        if ( ! $this->columnExists( $table, $column ) ) {
            return true;
        }
        $row = $wpdb->get_row( $wpdb->prepare( "SHOW COLUMNS FROM `$table` LIKE %s", $column ) );
        if ( ! $row || ( $row->Null ?? '' ) === 'YES' ) {
            return true;
        }
        $result = $wpdb->query( "ALTER TABLE `$table` MODIFY COLUMN `$column` $definition" );
        return $result !== false;
    }
};
