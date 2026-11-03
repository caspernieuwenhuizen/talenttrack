<?php
/**
 * Migration 0311 — a Report Studio name per draft and per version (#4737).
 *
 * A rename is an edit like any other and waits for publish: the builder
 * renames their draft, readers keep the name of the version they read until
 * the next publish. Until now the one name was `tt_reports.name`, so a rename
 * reached readers at once.
 *
 * Adds `name VARCHAR(255) NOT NULL DEFAULT ''` to `tt_report_drafts` and to
 * `tt_report_versions`, and fills each new column from `tt_reports.name`, so
 * every existing draft and version starts with the name the report has
 * today. The backfill runs only in the run that adds the column: a second
 * run finds both columns and writes nothing, so a draft name cleared later
 * is never filled in again. Forward-only.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0311_report_draft_names';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        foreach ( [ 'tt_report_drafts', 'tt_report_versions' ] as $table ) {
            $full = $p . $table;
            if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full ) ) !== $full ) continue;
            if ( $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$full} LIKE %s", 'name' ) ) === 'name' ) continue;

            $wpdb->query( "ALTER TABLE {$full} ADD COLUMN name VARCHAR(255) NOT NULL DEFAULT '' AFTER report_id" );
            $wpdb->query(
                "UPDATE {$full} t
                   JOIN {$p}tt_reports r ON r.id = t.report_id AND r.club_id = t.club_id
                    SET t.name = r.name"
            );
        }
    }

    public function down(): void {
        // Forward-only.
    }
};
