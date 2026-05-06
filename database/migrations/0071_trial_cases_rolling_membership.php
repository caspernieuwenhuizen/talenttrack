<?php
/**
 * Migration 0071 — #0081 child 4 trial-cases rolling membership.
 *
 * Two changes:
 *   - tt_trial_cases gains a `continued_until` DATE column. Set when the
 *     trial-group review task chooses `continue_in_trial_group`. The
 *     existing `end_date` keeps its meaning as the open window's hard
 *     stop; `continued_until` is the soft "we've decided to keep them
 *     in the trial group through this date" marker that the next
 *     `ReviewTrialGroupMembershipTemplate` re-spawn anchors on.
 *   - tt_teams gains a `team_kind` VARCHAR(32) column. NULL = regular
 *     academy team (the historic shape, preserved by default). The
 *     trial-group pseudo-team per club + age group is created lazily
 *     with `team_kind = 'trial_group'`, letting the existing `tt_team_people`
 *     join handle membership and the existing `tt_attendance` flow handle
 *     weekly attendance. No bespoke roster + attendance tables.
 *
 * Idempotent. SHOW COLUMNS guards on both ALTERs.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0071_trial_cases_rolling_membership';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $cases_table = "{$p}tt_trial_cases";
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $cases_table ) ) === $cases_table ) {
            $col = $wpdb->get_var( $wpdb->prepare(
                "SHOW COLUMNS FROM {$cases_table} LIKE %s", 'continued_until'
            ) );
            if ( $col !== 'continued_until' ) {
                $wpdb->query( "ALTER TABLE {$cases_table} ADD COLUMN continued_until DATE DEFAULT NULL AFTER end_date" );
                $wpdb->query( "ALTER TABLE {$cases_table} ADD KEY idx_continued_until (continued_until)" );
            }
        }

        $teams_table = "{$p}tt_teams";
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $teams_table ) ) === $teams_table ) {
            $col = $wpdb->get_var( $wpdb->prepare(
                "SHOW COLUMNS FROM {$teams_table} LIKE %s", 'team_kind'
            ) );
            if ( $col !== 'team_kind' ) {
                $wpdb->query( "ALTER TABLE {$teams_table} ADD COLUMN team_kind VARCHAR(32) DEFAULT NULL" );
                $wpdb->query( "ALTER TABLE {$teams_table} ADD KEY idx_team_kind (team_kind)" );
            }
        }
    }
};
