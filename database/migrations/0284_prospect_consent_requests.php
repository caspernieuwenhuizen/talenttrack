<?php
/**
 * Migration: 0284_prospect_consent_requests
 *
 * #3812 — between "I spotted a child at another club" and "the family has
 * said yes" there is a real step: asking the child's own club to pass the
 * request on. TalentTrack had nowhere to put it. `tt_prospects` carries
 * `parent_name`, `parent_email`, `parent_phone` and `consent_given_at`,
 * all of which must stay empty until the family answers — so the one
 * record that protects the child, the proof that the academy went through
 * the coordinator and never collected family data, lived in a scout's
 * mailbox.
 *
 * `tt_prospect_consent_requests` is the dated log of that asking. Same
 * shape as `tt_prospect_visit_observations` (0279), the module's existing
 * dated-log pattern: `created_by`, a date, free text, and a `uuid` +
 * `club_id` scaffold per CLAUDE.md §4.
 *
 * ## No family-identifying column, ever
 *
 * `asked_of` names the CLUB or the coordinator the academy went through —
 * the route used — and nothing about the family. There is deliberately no
 * parent name, no email, no phone and no address here, because a family
 * that has not consented is exactly who this table must not describe. A
 * reviewer should refuse a PR that adds one.
 *
 * ## The log is the record; the task is the state
 *
 * The prospect's pipeline stage keeps coming from `tt_workflow_tasks` via
 * `ProspectStageClassifier` — `tt_prospects` still has no status column,
 * by the 0066 decision, and this table does not become a second state
 * machine. `ProspectStageClassifier` reads the `request_consent` task and
 * never this table.
 *
 * Idempotent: `CREATE TABLE IF NOT EXISTS`, and nothing to backfill —
 * there was no earlier place this was recorded.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0284_prospect_consent_requests';
    }

    public function up(): void {
        global $wpdb;
        $p       = $wpdb->prefix;
        $charset = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = "{$p}tt_prospect_consent_requests";

        dbDelta( "CREATE TABLE IF NOT EXISTS {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            uuid CHAR(36) NOT NULL,
            club_id INT UNSIGNED NOT NULL DEFAULT 1,
            prospect_id BIGINT UNSIGNED NOT NULL,
            asked_at DATE NOT NULL,
            asked_of VARCHAR(255) NOT NULL,
            outcome VARCHAR(32) NOT NULL DEFAULT 'awaiting',
            notes TEXT NULL,
            created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_uuid (uuid),
            KEY idx_club_prospect (club_id, prospect_id),
            KEY idx_club_outcome (club_id, outcome, asked_at)
        ) $charset;" );

        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
        $this->report(
            $exists ? 1 : 0,
            $exists
                ? 'tt_prospect_consent_requests is present'
                : 'CREATE TABLE did not produce tt_prospect_consent_requests'
        );
    }
};
