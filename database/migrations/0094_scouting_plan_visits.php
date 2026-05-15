<?php
/**
 * Migration 0094 — Scouting plan visits + prospect linkage (#0081 follow-up).
 *
 * Adds the entity a scout uses to plan / record an off-site visit (a
 * match, tournament, or training open day where they expect to spot
 * potential prospects). Distinct from `tt_test_trainings` (one-off
 * club-hosted training a prospect attends to be observed) — a
 * scouting visit is *outbound* from the club to where prospects
 * already are.
 *
 * Schema:
 *
 *   tt_scouting_plan_visits — one row per planned/completed visit.
 *     - scout_user_id        the planner (typically also the visitor)
 *     - visit_date, visit_time   when
 *     - location, event_description   what
 *     - age_groups_csv       comma-separated lookup keys for the age groups
 *                            expected; informational, not enforced
 *     - status               planned / completed / cancelled
 *     - notes                free-text scout notes
 *     - uuid                 stable identifier for the SaaS port
 *     - club_id              tenant scope
 *
 *   tt_prospects.scouting_visit_id  — nullable FK to the visit that
 *     led to this prospect being logged. The wizard's new optional
 *     ScoutingVisitStep writes it; back-fill on already-logged
 *     prospects is not attempted.
 *
 * Per CLAUDE.md §4 SaaS-readiness:
 *   - club_id NOT NULL DEFAULT 1
 *   - uuid CHAR(36) UNIQUE on the root entity
 *
 * Idempotent. Safe to re-run on already-migrated installs.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0094_scouting_plan_visits';
    }

    public function up(): void {
        global $wpdb;
        $p       = $wpdb->prefix;
        $charset = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta( "CREATE TABLE IF NOT EXISTS {$p}tt_scouting_plan_visits (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            uuid CHAR(36) NOT NULL,
            club_id INT UNSIGNED NOT NULL DEFAULT 1,
            scout_user_id BIGINT UNSIGNED NOT NULL,
            visit_date DATE NOT NULL,
            visit_time TIME DEFAULT NULL,
            location VARCHAR(255) NOT NULL DEFAULT '',
            event_description VARCHAR(500) DEFAULT NULL,
            age_groups_csv VARCHAR(255) DEFAULT NULL,
            notes TEXT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'planned',
            archived_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_uuid (uuid),
            KEY idx_club_scout_date (club_id, scout_user_id, visit_date),
            KEY idx_club_status (club_id, status)
        ) $charset;" );

        $prospects_table = $p . 'tt_prospects';
        $col_exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = %s
                AND COLUMN_NAME = 'scouting_visit_id'",
            $prospects_table
        ) );
        if ( ! $col_exists ) {
            $wpdb->query( "ALTER TABLE {$prospects_table}
                ADD COLUMN scouting_visit_id BIGINT UNSIGNED DEFAULT NULL AFTER discovered_by_user_id,
                ADD KEY idx_scouting_visit (scouting_visit_id)" );
        }
    }
};
