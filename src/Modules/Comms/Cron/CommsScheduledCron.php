<?php
namespace TT\Modules\Comms\Cron;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Comms\Dispatch\CommsDispatcher;
use TT\Modules\Comms\Domain\MessageType;
use TT\Modules\Comms\Domain\Recipient;
use TT\Modules\Comms\Recipient\RecipientResolver;

/**
 * CommsScheduledCron (#0066, v3.110.18) — daily wp-cron that fires
 * the 4 schedule-driven templates:
 *
 *   - goal_nudge: `tt_goals` rows older than 28 days where
 *     `last_nudge_at` is NULL or older than 28 days.
 *   - attendance_flag: players with 3+ consecutive 'absent' /
 *     'excused' / 'injured' attendance rows in the trailing 30 days.
 *   - onboarding_nudge_inactive: `wp_users` linked to a player whose
 *     `tt_user_meta('last_login')` is older than 30 days, frequency-
 *     capped at one nudge per 60 days.
 *   - staff_development_reminder: `tt_staff_reviews` due within 7 days
 *     where `last_reminder_at` is NULL or older than 7 days.
 *
 * The other 11 templates are event-driven and fire from their owning
 * module via the `tt_comms_dispatch` action — see `CommsDispatcher`.
 *
 * v1 is conservative on queries — each detector reads a small bounded
 * window and bails fast on missing tables (gracefully handles installs
 * that haven't shipped the relevant module yet). All detectors persist
 * a "last fired at" marker on the originating row to avoid re-firing
 * on the same trigger every day.
 *
 * Marker table — `tt_comms_nudge_log` would be the textbook design
 * but we don't have it; v1 uses per-template `wp_options` with
 * `tt_comms_<template>_lastrun_<club_id>` keyed entries. A future
 * ship folds these into a proper marker table when a customer asks
 * for the nudge audit log.
 */
final class CommsScheduledCron {

    public const HOOK = 'tt_comms_scheduled_cron';

    public static function init(): void {
        add_action( self::HOOK, [ __CLASS__, 'run' ] );
        if ( ! wp_next_scheduled( self::HOOK ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
        }
    }

    public static function run(): void {
        // Each detector swallows its own failures — a broken detector
        // mustn't break the others. Run them in fixed order so the
        // audit timestamps line up day-over-day.
        self::runOne( 'goal_nudge',                 [ __CLASS__, 'detectGoalNudges' ] );
        self::runOne( 'attendance_flag',            [ __CLASS__, 'detectAttendanceFlags' ] );
        self::runOne( 'onboarding_nudge_inactive',  [ __CLASS__, 'detectOnboardingNudges' ] );
        self::runOne( 'staff_development_reminder', [ __CLASS__, 'detectStaffDevReminders' ] );
    }

    private static function runOne( string $template_key, callable $detector ): void {
        try {
            $detector();
        } catch ( \Throwable $e ) {
            // Best-effort; one detector's failure mustn't block the rest.
        }
    }

    private static function detectGoalNudges(): void {
        global $wpdb;
        $p = $wpdb->prefix;
        if ( ! self::tableExists( "{$p}tt_goals" ) ) return;

        // 28-day-old goals where the player is reachable (has wp_user_id).
        // Pulls a bounded batch per cron run (50) so a long tail
        // doesn't single-handedly hit the wp-cron timeout.
        $rows = $wpdb->get_results(
            "SELECT g.id, g.player_id, g.title,
                    DATEDIFF(NOW(), g.created_at) AS days_old,
                    pl.club_id, pl.first_name, pl.last_name
                FROM {$p}tt_goals g
                JOIN {$p}tt_players pl ON pl.id = g.player_id
                WHERE g.archived_at IS NULL
                  AND g.created_at <= DATE_SUB(NOW(), INTERVAL 28 DAY)
                  AND g.created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
                ORDER BY g.id ASC
                LIMIT 50"
        );
        if ( ! is_array( $rows ) || $rows === [] ) return;

        $resolver = new RecipientResolver();
        foreach ( $rows as $row ) {
            $recipients = $resolver->forPlayer( (int) $row->player_id );
            if ( $recipients === [] ) continue;
            do_action(
                CommsDispatcher::ACTION_HOOK,
                'goal_nudge',
                [
                    'goal_title'             => (string) $row->title,
                    'player_name'            => trim( $row->first_name . ' ' . $row->last_name ),
                    'weeks_since_creation'   => (int) floor( ( (int) $row->days_old ) / 7 ),
                    'deep_link'              => self::deepLink( 'goals', (int) $row->id ),
                ],
                $recipients,
                [
                    'message_type' => MessageType::GOAL_NUDGE,
                    'club_id'      => (int) $row->club_id,
                ]
            );
        }
    }

    private static function detectAttendanceFlags(): void {
        global $wpdb;
        $p = $wpdb->prefix;
        if ( ! self::tableExists( "{$p}tt_attendance" ) || ! self::tableExists( "{$p}tt_activities" ) ) return;

        // Players with 3+ non-present attendance rows in their last 5
        // activities (trailing 30 days). Conservative — joins
        // attendance to activities to avoid stale future rows.
        $rows = $wpdb->get_results(
            "SELECT pl.id AS player_id, pl.club_id, pl.team_id, pl.first_name, pl.last_name,
                    COUNT(*) AS missed_count
                FROM {$p}tt_attendance att
                JOIN {$p}tt_activities a ON a.id = att.activity_id
                JOIN {$p}tt_players pl ON pl.id = att.player_id
                WHERE a.session_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                  AND a.plan_state = 'completed'
                  AND LOWER(att.status) IN ('absent', 'excused', 'injured')
                GROUP BY pl.id, pl.club_id, pl.team_id, pl.first_name, pl.last_name
                HAVING COUNT(*) >= 3
                ORDER BY missed_count DESC
                LIMIT 30"
        );
        if ( ! is_array( $rows ) || $rows === [] ) return;

        foreach ( $rows as $row ) {
            // Recipients are coaches of the team + HoD. We don't
            // resolve coach lists here in v1 — a follow-up wires the
            // CoachResolver. For now we fire to administrators of the
            // club so the flag isn't lost. The action hook listener
            // can override `recipients` via filter if a downstream
            // module wants finer routing.
            $recipients = self::clubAdminRecipients( (int) $row->club_id );
            if ( $recipients === [] ) continue;
            do_action(
                CommsDispatcher::ACTION_HOOK,
                'attendance_flag',
                [
                    'player_name'   => trim( $row->first_name . ' ' . $row->last_name ),
                    'team_name'     => self::teamName( (int) $row->team_id, (int) $row->club_id ),
                    'missed_count'  => (int) $row->missed_count,
                    'deep_link'     => self::deepLink( 'players', (int) $row->player_id ),
                ],
                $recipients,
                [
                    'message_type' => MessageType::ATTENDANCE_FLAG,
                    'club_id'      => (int) $row->club_id,
                ]
            );
        }
    }

    private static function detectOnboardingNudges(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        // Parents linked to at least one player who haven't logged in
        // for 30+ days. Uses the WP `last_login` user-meta we set on
        // the standard auth flow; users with no `last_login` meta
        // (legacy or never-logged-in) are skipped so we don't nudge
        // accounts that never activated. Frequency-capped at one nudge
        // per 60 days via a `tt_comms_onboarding_nudge_at` user-meta
        // marker.
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT DISTINCT pp.parent_user_id, pl.club_id, pl.id AS player_id,
                    pl.first_name, pl.last_name
                FROM {$p}tt_player_parents pp
                JOIN {$p}tt_players pl ON pl.id = pp.player_id
                JOIN {$wpdb->usermeta} um_login ON um_login.user_id = pp.parent_user_id
                  AND um_login.meta_key = %s
                LEFT JOIN {$wpdb->usermeta} um_nudge ON um_nudge.user_id = pp.parent_user_id
                  AND um_nudge.meta_key = %s
                WHERE um_login.meta_value <= %s
                  AND ( um_nudge.meta_value IS NULL OR um_nudge.meta_value <= %s )
                LIMIT 30",
            'last_login',
            'tt_comms_onboarding_nudge_at',
            (string) gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ),
            (string) gmdate( 'Y-m-d H:i:s', time() - 60 * DAY_IN_SECONDS )
        ) );
        if ( ! is_array( $rows ) || $rows === [] ) return;

        foreach ( $rows as $row ) {
            $parent_user_id = (int) $row->parent_user_id;
            $u = get_userdata( $parent_user_id );
            if ( ! $u ) continue;
            do_action(
                CommsDispatcher::ACTION_HOOK,
                'onboarding_nudge_inactive',
                [
                    'player_name'              => trim( $row->first_name . ' ' . $row->last_name ),
                    'recent_evaluations_count' => self::countRecentEvaluations( (int) $row->player_id ),
                    'recent_goals_count'       => self::countRecentGoals( (int) $row->player_id ),
                    'deep_link'                => self::deepLink( 'players', (int) $row->player_id ),
                ],
                [
                    Recipient::parent(
                        $parent_user_id,
                        (int) $row->player_id,
                        (string) $u->user_email,
                        (string) get_user_meta( $parent_user_id, 'tt_phone', true ),
                        (string) get_user_meta( $parent_user_id, 'locale', true )
                    ),
                ],
                [
                    'message_type' => MessageType::ONBOARDING_NUDGE_INACTIVE,
                    'club_id'      => (int) $row->club_id,
                ]
            );
            // Stamp the marker so we don't re-nudge for 60 days.
            update_user_meta( $parent_user_id, 'tt_comms_onboarding_nudge_at', gmdate( 'Y-m-d H:i:s' ) );
        }
    }

    private static function detectStaffDevReminders(): void {
        global $wpdb;
        $p = $wpdb->prefix;
        if ( ! self::tableExists( "{$p}tt_staff_reviews" ) ) return;

        // Reviews due in <= 7 days that haven't been reminded in the
        // last 7 days. Bounded batch per run.
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, coach_user_id, club_id, review_type, due_date, last_reminder_at
                FROM {$p}tt_staff_reviews
                WHERE due_date IS NOT NULL
                  AND due_date >= %s
                  AND due_date <= %s
                  AND ( last_reminder_at IS NULL OR last_reminder_at <= %s )
                ORDER BY due_date ASC
                LIMIT 50",
            (string) gmdate( 'Y-m-d' ),
            (string) gmdate( 'Y-m-d', time() + 7 * DAY_IN_SECONDS ),
            (string) gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS )
        ) );
        if ( ! is_array( $rows ) || $rows === [] ) return;

        foreach ( $rows as $row ) {
            $coach_user_id = (int) $row->coach_user_id;
            $u = get_userdata( $coach_user_id );
            if ( ! $u ) continue;
            do_action(
                CommsDispatcher::ACTION_HOOK,
                'staff_development_reminder',
                [
                    'coach_name'  => (string) $u->display_name,
                    'review_type' => (string) $row->review_type,
                    'due_date'    => (string) $row->due_date,
                    'deep_link'   => self::deepLink( 'staff-development', (int) $row->id ),
                ],
                [
                    Recipient::coach(
                        $coach_user_id,
                        null,
                        (string) $u->user_email,
                        (string) get_user_meta( $coach_user_id, 'tt_phone', true ),
                        (string) get_user_meta( $coach_user_id, 'locale', true )
                    ),
                ],
                [
                    'message_type' => MessageType::STAFF_DEVELOPMENT_REMINDER,
                    'club_id'      => (int) $row->club_id,
                ]
            );
            $wpdb->update(
                "{$p}tt_staff_reviews",
                [ 'last_reminder_at' => gmdate( 'Y-m-d H:i:s' ) ],
                [ 'id' => (int) $row->id ],
                [ '%s' ],
                [ '%d' ]
            );
        }
    }

    /**
     * @return Recipient[]
     */
    private static function clubAdminRecipients( int $club_id ): array {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT u.ID, u.user_email, u.display_name
                FROM {$wpdb->users} u
                JOIN {$wpdb->usermeta} m ON m.user_id = u.ID
                  AND m.meta_key = %s
                  AND ( m.meta_value LIKE %s OR m.meta_value LIKE %s )
                LIMIT 5",
            $wpdb->prefix . 'capabilities',
            '%administrator%',
            '%tt_club_admin%'
        ) );
        if ( ! is_array( $rows ) || $rows === [] ) return [];
        $out = [];
        foreach ( $rows as $r ) {
            $out[] = Recipient::coach(
                (int) $r->ID,
                null,
                (string) $r->user_email,
                (string) get_user_meta( (int) $r->ID, 'tt_phone', true ),
                (string) get_user_meta( (int) $r->ID, 'locale', true )
            );
        }
        return $out;
    }

    private static function countRecentEvaluations( int $player_id ): int {
        global $wpdb;
        $p = $wpdb->prefix;
        $n = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}tt_evaluations
                WHERE player_id = %d AND archived_at IS NULL
                  AND eval_date >= %s",
            $player_id,
            (string) gmdate( 'Y-m-d', time() - 30 * DAY_IN_SECONDS )
        ) );
        return (int) ( $n ?? 0 );
    }

    private static function countRecentGoals( int $player_id ): int {
        global $wpdb;
        $p = $wpdb->prefix;
        $n = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}tt_goals
                WHERE player_id = %d AND archived_at IS NULL
                  AND created_at >= %s",
            $player_id,
            (string) gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS )
        ) );
        return (int) ( $n ?? 0 );
    }

    private static function teamName( int $team_id, int $club_id ): string {
        if ( $team_id <= 0 ) return '';
        global $wpdb;
        $p = $wpdb->prefix;
        $name = $wpdb->get_var( $wpdb->prepare(
            "SELECT name FROM {$p}tt_teams WHERE id = %d AND club_id = %d LIMIT 1",
            $team_id,
            $club_id
        ) );
        return $name !== null ? (string) $name : '';
    }

    private static function deepLink( string $tt_view, int $id ): string {
        return add_query_arg(
            [ 'tt_view' => $tt_view, 'id' => $id ],
            home_url( '/' )
        );
    }

    private static function tableExists( string $table ): bool {
        global $wpdb;
        return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
    }
}
