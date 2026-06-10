<?php
/**
 * Migration 0151 — Repair `tt_team_blueprint_assignments` ref columns (#1331).
 *
 * Migration 0129 added `ref_kind`, `guest_name`, `guest_position`,
 * `custom_label` via `dbDelta`. `dbDelta` silently no-ops column
 * additions when the existing table definition diverges from what it
 * expects — and the MigrationRunner marks the migration applied either
 * way. On affected installs `INSERT INTO tt_team_blueprint_assignments`
 * 500s with `Unknown column 'ref_kind'` and the repair never runs.
 *
 * This migration uses explicit ALTERs via `MigrationHelpers::addColumnIfMissing()`
 * so the columns are added when missing and the no-op stays a no-op
 * when 0129 succeeded.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;
use TT\Infrastructure\Database\MigrationHelpers;

return new class extends Migration {

    public function getName(): string {
        return '0151_blueprint_assignment_refs_repair';
    }

    public function up(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_team_blueprint_assignments';

        MigrationHelpers::addColumnIfMissing( $table, 'ref_kind',       "VARCHAR(20) NOT NULL DEFAULT 'player'", 'tier' );
        MigrationHelpers::addColumnIfMissing( $table, 'guest_name',     'VARCHAR(120) DEFAULT NULL',             'player_id' );
        MigrationHelpers::addColumnIfMissing( $table, 'guest_position', 'VARCHAR(60) DEFAULT NULL',              'guest_name' );
        MigrationHelpers::addColumnIfMissing( $table, 'custom_label',   'VARCHAR(120) DEFAULT NULL',             'guest_position' );
    }

    public function down(): void {
        // Forward-only. Dropping the columns would erase guest / custom
        // assignment rows. Operators who need to roll back should
        // restore from backup.
    }
};
