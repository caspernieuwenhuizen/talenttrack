<?php
/**
 * Migration 0232 — `tt_exercises.principles_reviewed_at` (#2753).
 *
 * ## Why a column and not just the join table
 *
 * Principles live in `tt_exercise_principles`, so "has no principles"
 * is expressed by the absence of rows. That cannot distinguish two
 * quite different states:
 *
 *   - nobody has looked at this exercise yet, and
 *   - somebody looked and decided none apply.
 *
 * On the seeded academy 39 of the 69 unclassified exercises are
 * warm-ups, cool-downs and conditioning work. Most of them genuinely
 * should not carry a tactical principle — a warm-up does not train
 * "opbouw via de vrije back". Without somewhere to record that
 * decision they sit in the work list forever, the count never reaches
 * zero, and a coach re-examines the same warm-ups every time they open
 * the screen until they stop opening it.
 *
 * So the tagging surface's work list is "not reviewed", not "not
 * tagged". This column is what lets the job be finished, and be seen to
 * be finished.
 *
 * Nullable with no default: every existing row starts unreviewed, which
 * is true — nobody has been through them.
 *
 * `addColumnIfMissing` rather than dbDelta: the table already exists,
 * and dbDelta on a live table silently no-ops its ALTERs when the
 * schema has drifted (the #1331 class the migration-lint gate refuses).
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;
use TT\Infrastructure\Database\MigrationHelpers;

return new class extends Migration {

    public function getName(): string {
        return '0232_exercise_principles_reviewed';
    }

    public function up(): void {
        global $wpdb;

        // No index. An exercise library is a catalogue a club curates by
        // hand — 123 rows on the pilot install, and low thousands is a
        // generous ceiling. An index on a nullable datetime would cost a
        // write on every edit to save nothing measurable on a scan of
        // that size.
        MigrationHelpers::addColumnIfMissing(
            $wpdb->prefix . 'tt_exercises',
            'principles_reviewed_at',
            'DATETIME NULL DEFAULT NULL',
            'seed_revision'
        );
    }
};
