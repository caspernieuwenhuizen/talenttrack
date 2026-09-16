<?php
/**
 * Migration 0266 — a scheduled report can carry its own report composition
 * (#3462, epic #3457).
 *
 * `tt_scheduled_reports` only knew one kind of report: a KPI, rendered as a
 * CSV. The team monthly report is a second kind, and what it renders is a
 * composition — team, layout, sections, window — rather than a KPI key.
 *
 *   - `report_key` names the kind. Every existing row is a KPI schedule, so
 *     the column defaults to `kpi` and those rows keep working untouched.
 *   - `composition_json` is the schedule's **own copy** of the composition.
 *     Never a saved-view id: saved views are personal and scoped to their
 *     owner, and the cron has no user. A schedule bound to a preset would
 *     mail a different document the day its owner renamed or deleted it.
 *   - `last_error` is why the last run did not send, shown on the schedules
 *     screen. A scheduled report that silently stops arriving looks exactly
 *     like one that was never set up.
 *
 * The table already carries `club_id` and `uuid` (0075).
 *
 * Additive + idempotent — column-adds through MigrationHelpers::addColumnIfMissing,
 * so a re-run is a no-op. Forward-only. Run alone (schema migration).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;
use TT\Infrastructure\Database\MigrationHelpers;

return new class extends Migration {

    public function getName(): string {
        return '0266_scheduled_reports_composition';
    }

    public function up(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_scheduled_reports';

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }

        MigrationHelpers::addColumnIfMissing( $table, 'report_key', "VARCHAR(40) NOT NULL DEFAULT 'kpi'", 'name' );
        MigrationHelpers::addColumnIfMissing( $table, 'composition_json', 'LONGTEXT DEFAULT NULL', 'explorer_state_json' );
        MigrationHelpers::addColumnIfMissing( $table, 'last_error', 'VARCHAR(255) DEFAULT NULL', 'last_run_at' );
    }
};
