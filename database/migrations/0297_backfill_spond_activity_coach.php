<?php
/**
 * Migration 0297 — Spond-imported activities get their team's head coach (#4163).
 *
 * The Spond sync stored `coach_id = 0` on every event it imported, while
 * every other create path has defaulted to the team's head coach since
 * #3745. Synced teams therefore showed "no coach" on their activities, and
 * the register reminders for them had no recipient.
 *
 * The sync is fixed for new events; this repairs the rows already there,
 * past and future: every `spond` activity with no coach, on a team with
 * exactly one head coach who has an account. Teams with no head coach, or
 * two, are left alone. The rule lives in
 * `ActivityCoachAssignment::backfillSpondActivities()`.
 *
 * Idempotent: a second run finds nothing unassigned on those teams.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;
use TT\Modules\Activities\Services\ActivityCoachAssignment;

return new class extends Migration {

    public function getName(): string {
        return '0297_backfill_spond_activity_coach';
    }

    public function up(): void {
        global $wpdb;
        $activities = $wpdb->prefix . 'tt_activities';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $activities ) ) !== $activities ) return;
        if ( ! class_exists( ActivityCoachAssignment::class ) ) return;

        $written = ActivityCoachAssignment::backfillSpondActivities();

        if ( class_exists( '\\TT\\Infrastructure\\Logging\\Logger' ) ) {
            \TT\Infrastructure\Logging\Logger::info( 'migration.0297.spond_activity_coach_backfilled', [ 'rows' => $written ] );
        }
    }
};
