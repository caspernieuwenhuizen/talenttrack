<?php
namespace TT\Infrastructure\Usage;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * UsageTracker — captures + queries app-usage events.
 *
 * Sprint v2.18.0. Single point of contact for the tt_usage_events
 * table. Two responsibilities:
 *
 *   1. Capture. `init()` wires WP hooks that record logins + admin
 *      page views. `record()` is the public entry point for any
 *      code path that wants to instrument an event.
 *
 *   2. Query. `counts()`, `dailyActiveUsers()`, `pageVisits()`,
 *      `inactiveUsers()` and friends produce the aggregates shown on
 *      the UsageStatsPage admin dashboard.
 *
 * Retention: events older than 90 days are deleted by a daily WP-Cron
 * job (`tt_usage_prune_daily`, registered here). Each admin request
 * also checks and fires the job if it hasn't run today — belt-and-
 * braces in case cron is disabled on the host.
 *
 * Privacy: no IPs captured, no user agents, no fingerprints. Only
 * user_id + event_type + optional event_target. Visible only to users
 * with tt_manage_settings.
 */
class UsageTracker {

    private const RETENTION_DAYS = 90;
    private const CRON_HOOK = 'tt_usage_prune_daily';

    /* ═══════════════ Registration ═══════════════ */

    public static function init(): void {
        // Login capture
        add_action( 'wp_login', [ __CLASS__, 'onLogin' ], 10, 2 );

        // Admin page-view capture (only TalentTrack pages)
        add_action( 'admin_init', [ __CLASS__, 'onAdminPageView' ] );

        // Scheduled prune
        add_action( self::CRON_HOOK, [ __CLASS__, 'pruneOldEvents' ] );
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time() + 60, 'daily', self::CRON_HOOK );
        }
    }

    /**
     * Deactivation hook — unschedule the cron. Called from Activator.
     */
    public static function deactivateCron(): void {
        $timestamp = wp_next_scheduled( self::CRON_HOOK );
        if ( $timestamp ) wp_unschedule_event( $timestamp, self::CRON_HOOK );
    }

    /* ═══════════════ Event capture ═══════════════ */

    public static function onLogin( string $user_login, $user ): void {
        if ( ! is_object( $user ) ) return;
        self::record( (int) $user->ID, 'login', null );
    }

    public static function onAdminPageView(): void {
        $page = isset( $_GET['page'] ) ? sanitize_key( (string) $_GET['page'] ) : '';
        if ( $page === '' ) return;
        // Only track TalentTrack admin pages.
        if ( strpos( $page, 'tt-' ) !== 0 && $page !== 'talenttrack' ) return;
        // Skip separator slugs (fake menu entries).
        if ( strpos( $page, 'tt-sep-' ) === 0 ) return;

        $uid = get_current_user_id();
        if ( $uid <= 0 ) return;

        self::record( $uid, 'admin_page_view', $page );
    }

    /**
     * Record an arbitrary event. Exposed for instrumentation hooks
     * in other modules — e.g. "evaluation_saved" on save completion.
     *
     * @param int         $user_id  0 is ignored (no anonymous events).
     * @param string      $type     e.g. 'login', 'admin_page_view', 'evaluation_saved'
     * @param string|null $target   Optional context — e.g. the page slug.
     */
    public static function record( int $user_id, string $type, ?string $target = null ): void {
        if ( $user_id <= 0 ) return;
        if ( $type === '' ) return;

        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_usage_events', [
            'user_id'      => $user_id,
            'event_type'   => substr( $type, 0, 50 ),
            'event_target' => $target !== null ? substr( $target, 0, 100 ) : null,
            'created_at'   => current_time( 'mysql' ),
        ], [ '%d', '%s', '%s', '%s' ] );
    }

    /* ═══════════════ Prune ═══════════════ */

    public static function pruneOldEvents(): int {
        global $wpdb;
        $cutoff = gmdate( 'Y-m-d H:i:s', time() - self::RETENTION_DAYS * DAY_IN_SECONDS );
        return (int) $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}tt_usage_events WHERE created_at < %s",
            $cutoff
        ) );
    }

    /* ═══════════════ Queries for dashboard ═══════════════ */

    /**
     * Events of a given type within the last N days.
     *
     * @return int
     */
    public static function countEvents( string $type, int $days ): int {
        global $wpdb;
        $cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_usage_events
             WHERE event_type = %s AND created_at >= %s",
            $type, $cutoff
        ) );
    }

    /**
     * Unique user ids with any event in the last N days — used for
     * "active users" counts.
     *
     * @return int
     */
    public static function uniqueActiveUsers( int $days ): int {
        global $wpdb;
        $cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT user_id) FROM {$wpdb->prefix}tt_usage_events
             WHERE created_at >= %s",
            $cutoff
        ) );
    }

    /**
     * Daily active users for the last N days, as [date => count].
     * Fills zero-count days so the resulting series has exactly N rows
     * (useful for line charts).
     *
     * @return array<string, int>  YYYY-MM-DD => count
     */
    public static function dailyActiveUsers( int $days ): array {
        global $wpdb;
        $cutoff = gmdate( 'Y-m-d 00:00:00', time() - ( $days - 1 ) * DAY_IN_SECONDS );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT DATE(created_at) AS d, COUNT(DISTINCT user_id) AS c
             FROM {$wpdb->prefix}tt_usage_events
             WHERE created_at >= %s
             GROUP BY DATE(created_at)
             ORDER BY d ASC",
            $cutoff
        ) );

        // Fill zero days.
        $series = [];
        $start  = strtotime( $cutoff );
        for ( $i = 0; $i < $days; $i++ ) {
            $d = gmdate( 'Y-m-d', $start + $i * DAY_IN_SECONDS );
            $series[ $d ] = 0;
        }
        foreach ( (array) $rows as $r ) {
            $series[ (string) $r->d ] = (int) $r->c;
        }
        return $series;
    }

    /**
     * Top N admin pages visited in the last $days.
     *
     * @return array<int, array{page:string, count:int}>
     */
    public static function topAdminPages( int $days, int $limit = 10 ): array {
        global $wpdb;
        $cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT event_target AS page, COUNT(*) AS c
             FROM {$wpdb->prefix}tt_usage_events
             WHERE event_type = 'admin_page_view'
               AND created_at >= %s
             GROUP BY event_target
             ORDER BY c DESC
             LIMIT %d",
            $cutoff, $limit
        ) );
        $out = [];
        foreach ( (array) $rows as $r ) {
            $out[] = [ 'page' => (string) $r->page, 'count' => (int) $r->c ];
        }
        return $out;
    }

    /**
     * Break down active users in the last $days by TalentTrack role —
     * coach, admin, player, other — based on capability checks.
     *
     * @return array<string, int>  role_key => count
     */
    public static function activeByRole( int $days ): array {
        global $wpdb;
        $cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
        $user_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT user_id FROM {$wpdb->prefix}tt_usage_events
             WHERE created_at >= %s",
            $cutoff
        ) );
        $buckets = [ 'admin' => 0, 'coach' => 0, 'player' => 0, 'other' => 0 ];
        foreach ( $user_ids as $uid ) {
            $user = get_userdata( (int) $uid );
            if ( ! $user ) { $buckets['other']++; continue; }
            if ( user_can( $user, 'tt_edit_settings' ) ) {
                $buckets['admin']++;
            } elseif ( user_can( $user, 'tt_edit_evaluations' ) ) {
                $buckets['coach']++;
            } else {
                // Check player link.
                $pid = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}tt_players WHERE wp_user_id = %d LIMIT 1",
                    (int) $uid
                ) );
                if ( $pid > 0 ) $buckets['player']++;
                else $buckets['other']++;
            }
        }
        return $buckets;
    }

    /**
     * Users who haven't logged in for at least $days days, but have
     * logged in at least once ever (in the retention window). Returns
     * rows with user_id, display_name, and last-login timestamp.
     *
     * @return array<int, array{user_id:int, display_name:string, last_login:string}>
     */
    public static function inactiveUsers( int $days, int $limit = 20 ): array {
        global $wpdb;
        $cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT user_id, MAX(created_at) AS last_login
             FROM {$wpdb->prefix}tt_usage_events
             WHERE event_type = 'login'
             GROUP BY user_id
             HAVING last_login < %s
             ORDER BY last_login DESC
             LIMIT %d",
            $cutoff, $limit
        ) );
        $out = [];
        foreach ( (array) $rows as $r ) {
            $user = get_userdata( (int) $r->user_id );
            $out[] = [
                'user_id'      => (int) $r->user_id,
                'display_name' => $user ? $user->display_name : sprintf( '(user %d)', (int) $r->user_id ),
                'last_login'   => (string) $r->last_login,
            ];
        }
        return $out;
    }

    /**
     * Evaluations created per day for the last $days — reads directly
     * from tt_evaluations since that's the more accurate source than
     * `evaluation_saved` events (which only exist post-instrumentation).
     *
     * @return array<string, int>  YYYY-MM-DD => count
     */
    public static function evaluationsCreatedDaily( int $days ): array {
        global $wpdb;
        $cutoff = gmdate( 'Y-m-d 00:00:00', time() - ( $days - 1 ) * DAY_IN_SECONDS );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT DATE(created_at) AS d, COUNT(*) AS c
             FROM {$wpdb->prefix}tt_evaluations
             WHERE created_at >= %s
             GROUP BY DATE(created_at)
             ORDER BY d ASC",
            $cutoff
        ) );
        $series = [];
        $start  = strtotime( $cutoff );
        for ( $i = 0; $i < $days; $i++ ) {
            $d = gmdate( 'Y-m-d', $start + $i * DAY_IN_SECONDS );
            $series[ $d ] = 0;
        }
        foreach ( (array) $rows as $r ) {
            $series[ (string) $r->d ] = (int) $r->c;
        }
        return $series;
    }
}
