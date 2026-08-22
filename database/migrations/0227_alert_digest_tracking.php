<?php
/**
 * Migration 0227 — digest tracking on alert occurrences (#2634, epic #2629).
 *
 * One column: `tt_alert_occurrences.digested_at`.
 *
 * Without it a digest cannot know what it has already told someone about. An
 * occurrence stays open for as long as the underlying problem does — days,
 * sometimes weeks — so a digest that selected purely on "open and unread"
 * would re-send the same three unmarked activities every morning until
 * somebody fixed them. That is the precise behaviour that teaches a coach to
 * filter the sender to spam, taking the alerts that mattered with it.
 *
 * Deliberately a timestamp rather than a boolean: "when did we last tell
 * them" is answerable, supports a future weekly-versus-daily cadence
 * comparison, and degrades gracefully if a cadence changes underneath a
 * user. A boolean would have to be reset by something, and nothing sensible
 * owns that reset.
 *
 * Column add on an existing table, so `MigrationHelpers::addColumnIfMissing`
 * and never `dbDelta` — dbDelta silently no-ops an ALTER when the live table
 * has drifted from the CREATE statement (the #1331/0129 incident class, now
 * a CI gate).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;
use TT\Infrastructure\Database\MigrationHelpers;

return new class extends Migration {

    public function getName(): string {
        return '0227_alert_digest_tracking';
    }

    public function up(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_alert_occurrences';

        // Defensive: the alerts foundation (0220) may not have run on an
        // install that is mid-upgrade. A missing table here is not an error
        // worth failing the run for — 0220 will create it with this column's
        // sibling columns and the next run of this migration adds it.
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }

        MigrationHelpers::addColumnIfMissing(
            $table,
            'digested_at',
            'DATETIME DEFAULT NULL',
            'read_at'
        );
    }
};
