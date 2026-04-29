<?php
namespace TT\Modules\Stats\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Infrastructure\Usage\UsageTracker;
use TT\Shared\Admin\BackButton;

/**
 * UsageStatsDetailsPage — drill-down views for Usage Statistics KPIs.
 *
 * Sprint v2.19.0. Every card / chart / row on the Usage Statistics
 * dashboard links here with ?metric=X&days=Y (and occasionally
 * additional filters like &role=coach or &page_slug=tt-players).
 *
 * Admin-only. Rendered inside the admin shell — the back button
 * returns to the Usage Statistics dashboard.
 *
 * Supported metrics:
 *   - logins                  → list of login events (user, timestamp)
 *   - active_users            → list of active users (name, role, login count, last seen)
 *   - dau_day (&date=YYYY-MM-DD) → users active on a specific day
 *   - evals_day (&date=...)   → evaluations created on a specific day
 *   - active_by_role (&role=) → active users of a specific role
 *   - top_page (&slug=)       → visits for a single admin page
 *   - user_timeline (&uid=)   → event history for one user
 */
class UsageStatsDetailsPage {

    private const CAP = 'tt_view_settings';

    public static function render(): void {
        if ( ! current_user_can( self::CAP ) ) {
            wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        }

        $metric = isset( $_GET['metric'] ) ? sanitize_key( (string) $_GET['metric'] ) : '';
        $days   = isset( $_GET['days'] ) ? max( 1, min( 90, absint( $_GET['days'] ) ) ) : 30;

        ?>
        <div class="wrap">
            <?php BackButton::render( admin_url( 'admin.php?page=tt-usage-stats' ) ); ?>

            <?php
            switch ( $metric ) {
                case 'logins':         self::renderLogins( $days );       break;
                case 'active_users':   self::renderActiveUsers( $days );  break;
                case 'dau_day':        self::renderDauDay();              break;
                case 'evals_day':      self::renderEvalsDay();            break;
                case 'active_by_role': self::renderActiveByRole( $days ); break;
                case 'top_page':       self::renderTopPage( $days );      break;
                case 'user_timeline':  self::renderUserTimeline();        break;
                default:
                    echo '<h1>' . esc_html__( 'Usage Detail', 'talenttrack' ) . '</h1>';
                    echo '<p>' . esc_html__( 'Unknown metric.', 'talenttrack' ) . '</p>';
            }
            ?>
        </div>
        <?php
    }

    // Renderers

    private static function renderLogins( int $days ): void {
        global $wpdb;
        $cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT e.user_id, e.created_at
             FROM {$wpdb->prefix}tt_usage_events e
             WHERE e.event_type = 'login' AND e.created_at >= %s AND e.club_id = %d
             ORDER BY e.created_at DESC
             LIMIT 500",
            $cutoff, CurrentClub::id()
        ) );
        ?>
        <h1><?php
            printf(
                /* translators: %d is number of days in the window */
                esc_html__( 'Logins — last %d days', 'talenttrack' ),
                $days
            );
        ?></h1>
        <p style="color:#666;"><?php
            printf(
                /* translators: %d is number of rows shown */
                esc_html( _n( '%d login event.', '%d login events.', count( (array) $rows ), 'talenttrack' ) ),
                count( (array) $rows )
            );
        ?></p>
        <?php self::renderUserTimeTable( (array) $rows ); ?>
        <?php
    }

    private static function renderActiveUsers( int $days ): void {
        global $wpdb;
        $cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT user_id,
                    SUM(CASE WHEN event_type='login' THEN 1 ELSE 0 END) AS login_count,
                    COUNT(*) AS event_count,
                    MAX(created_at) AS last_seen
             FROM {$wpdb->prefix}tt_usage_events
             WHERE created_at >= %s AND club_id = %d
             GROUP BY user_id
             ORDER BY last_seen DESC",
            $cutoff, CurrentClub::id()
        ) );
        ?>
        <h1><?php
            printf(
                esc_html__( 'Active users — last %d days', 'talenttrack' ),
                $days
            );
        ?></h1>
        <table class="widefat striped" style="max-width:900px;">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'User', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'Role', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'Logins', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'Events', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'Last seen', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'Actions', 'talenttrack' ); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( (array) $rows as $r ) :
                $user = get_userdata( (int) $r->user_id );
                $name = $user ? $user->display_name : sprintf( '(user %d)', (int) $r->user_id );
                $role = self::userRole( (int) $r->user_id );
                $timeline_url = admin_url( 'admin.php?page=tt-usage-stats-details&metric=user_timeline&uid=' . (int) $r->user_id );
                ?>
                <tr>
                    <td><strong><?php echo esc_html( $name ); ?></strong></td>
                    <td><?php echo esc_html( $role ); ?></td>
                    <td style="font-variant-numeric:tabular-nums;"><?php echo (int) $r->login_count; ?></td>
                    <td style="font-variant-numeric:tabular-nums;"><?php echo (int) $r->event_count; ?></td>
                    <td><?php echo esc_html( (string) $r->last_seen ); ?></td>
                    <td><a href="<?php echo esc_url( $timeline_url ); ?>"><?php esc_html_e( 'Timeline', 'talenttrack' ); ?></a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * Resolve the `?date=YYYY-MM-DD` query param. If missing or malformed
     * we fall back to today — this is the key difference from the
     * pre-v3.6.0 behaviour where an absent / bad date bailed with an
     * 'Invalid date' dead-end and no way to recover except editing the
     * URL. Now the page always renders with a date picker so the admin
     * can scrub days without touching the chart.
     */
    private static function resolveDayParam(): string {
        $date = isset( $_GET['date'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['date'] ) ) : '';
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
            $date = current_time( 'Y-m-d' );
        }
        return $date;
    }

    /**
     * Render the date picker + nav buttons shared by dau_day / evals_day.
     * Posts via a GET form so the URL stays bookmarkable; prev/next
     * buttons step one day at a time and anchor to the same metric.
     */
    private static function renderDayPicker( string $metric, string $date, string $heading ): void {
        $prev = (string) gmdate( 'Y-m-d', (int) strtotime( $date . ' -1 day' ) );
        $next = (string) gmdate( 'Y-m-d', (int) strtotime( $date . ' +1 day' ) );
        $base = admin_url( 'admin.php' );
        $prev_url = add_query_arg( [ 'page' => 'tt-usage-stats-details', 'metric' => $metric, 'date' => $prev ], $base );
        $next_url = add_query_arg( [ 'page' => 'tt-usage-stats-details', 'metric' => $metric, 'date' => $next ], $base );
        ?>
        <h1><?php echo esc_html( $heading ); ?></h1>
        <form method="get" action="<?php echo esc_url( $base ); ?>" style="display:flex; gap:8px; align-items:center; background:#fff; border:1px solid #dcdcde; padding:10px 14px; border-radius:4px; max-width:520px;">
            <input type="hidden" name="page" value="tt-usage-stats-details" />
            <input type="hidden" name="metric" value="<?php echo esc_attr( $metric ); ?>" />
            <a href="<?php echo esc_url( $prev_url ); ?>" class="button" title="<?php esc_attr_e( 'Previous day', 'talenttrack' ); ?>">←</a>
            <label for="tt-usage-date" style="font-size:13px;">
                <?php esc_html_e( 'Pick a day:', 'talenttrack' ); ?>
            </label>
            <input type="date" id="tt-usage-date" name="date" value="<?php echo esc_attr( $date ); ?>" style="font-size:13px;" />
            <button type="submit" class="button button-primary"><?php esc_html_e( 'Go', 'talenttrack' ); ?></button>
            <a href="<?php echo esc_url( $next_url ); ?>" class="button" title="<?php esc_attr_e( 'Next day', 'talenttrack' ); ?>">→</a>
        </form>
        <?php
    }

    private static function renderDauDay(): void {
        $date = self::resolveDayParam();
        self::renderDayPicker( 'dau_day', $date, __( 'Daily active users', 'talenttrack' ) );

        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT user_id, COUNT(*) AS event_count, MIN(created_at) AS first_event, MAX(created_at) AS last_event
             FROM {$wpdb->prefix}tt_usage_events
             WHERE DATE(created_at) = %s AND club_id = %d
             GROUP BY user_id
             ORDER BY last_event DESC",
            $date, CurrentClub::id()
        ) );
        ?>
        <h2 style="margin-top:16px;"><?php
            printf(
                /* translators: %s is a date string */
                esc_html__( 'Active users on %s', 'talenttrack' ),
                esc_html( $date )
            );
        ?></h2>
        <table class="widefat striped" style="max-width:900px;">
            <thead><tr>
                <th><?php esc_html_e( 'User', 'talenttrack' ); ?></th>
                <th><?php esc_html_e( 'Events', 'talenttrack' ); ?></th>
                <th><?php esc_html_e( 'First', 'talenttrack' ); ?></th>
                <th><?php esc_html_e( 'Last', 'talenttrack' ); ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ( (array) $rows as $r ) :
                $user = get_userdata( (int) $r->user_id );
                $name = $user ? $user->display_name : sprintf( '(user %d)', (int) $r->user_id );
                ?>
                <tr>
                    <td><?php echo esc_html( $name ); ?></td>
                    <td style="font-variant-numeric:tabular-nums;"><?php echo (int) $r->event_count; ?></td>
                    <td><?php echo esc_html( (string) $r->first_event ); ?></td>
                    <td><?php echo esc_html( (string) $r->last_event ); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    private static function renderEvalsDay(): void {
        $date = self::resolveDayParam();
        self::renderDayPicker( 'evals_day', $date, __( 'Evaluations created per day', 'talenttrack' ) );

        global $wpdb;
        $p = $wpdb->prefix;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT e.id, e.player_id, e.coach_id, e.eval_date, e.created_at,
                    CONCAT(pl.first_name, ' ', pl.last_name) AS player_name,
                    lt.name AS type_name,
                    u.display_name AS coach_name
             FROM {$p}tt_evaluations e
             LEFT JOIN {$p}tt_players pl ON e.player_id = pl.id    AND pl.club_id = e.club_id
             LEFT JOIN {$p}tt_lookups lt ON e.eval_type_id = lt.id AND lt.club_id = e.club_id
             LEFT JOIN {$wpdb->users} u ON e.coach_id = u.ID
             WHERE DATE(e.created_at) = %s AND e.club_id = %d
             ORDER BY e.created_at DESC",
            $date, CurrentClub::id()
        ) );
        ?>
        <h2 style="margin-top:16px;"><?php
            printf(
                /* translators: %s is a date */
                esc_html__( 'Evaluations created on %s', 'talenttrack' ),
                esc_html( $date )
            );
        ?></h2>
        <?php if ( empty( $rows ) ) : ?>
            <p><em><?php esc_html_e( 'None that day.', 'talenttrack' ); ?></em></p>
        <?php else : ?>
            <table class="widefat striped">
                <thead><tr>
                    <th><?php esc_html_e( 'Created', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'Player', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'Type', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'Coach', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'Actions', 'talenttrack' ); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ( (array) $rows as $r ) : ?>
                    <tr>
                        <td><?php echo esc_html( (string) $r->created_at ); ?></td>
                        <td><?php echo esc_html( (string) $r->player_name ); ?></td>
                        <td><?php echo esc_html( (string) ( $r->type_name ?: '—' ) ); ?></td>
                        <td><?php echo esc_html( (string) $r->coach_name ); ?></td>
                        <td><a href="<?php echo esc_url( admin_url( 'admin.php?page=tt-evaluations&action=view&id=' . (int) $r->id ) ); ?>"><?php esc_html_e( 'View', 'talenttrack' ); ?></a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <?php
    }

    private static function renderActiveByRole( int $days ): void {
        $role = isset( $_GET['role'] ) ? sanitize_key( (string) $_GET['role'] ) : '';
        if ( ! in_array( $role, [ 'admin', 'coach', 'player', 'other' ], true ) ) {
            echo '<p>' . esc_html__( 'Invalid role.', 'talenttrack' ) . '</p>';
            return;
        }
        global $wpdb;
        $cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
        $user_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT user_id FROM {$wpdb->prefix}tt_usage_events WHERE created_at >= %s AND club_id = %d",
            $cutoff, CurrentClub::id()
        ) );
        // Filter by matching role.
        $matching = [];
        foreach ( $user_ids as $uid ) {
            if ( self::userRoleKey( (int) $uid ) === $role ) $matching[] = (int) $uid;
        }

        $role_label = [
            'admin'  => __( 'Admins', 'talenttrack' ),
            'coach'  => __( 'Coaches', 'talenttrack' ),
            'player' => __( 'Players', 'talenttrack' ),
            'other'  => __( 'Other', 'talenttrack' ),
        ][ $role ];
        ?>
        <h1><?php
            printf(
                /* translators: 1: role label, 2: days */
                esc_html__( 'Active %1$s — last %2$d days', 'talenttrack' ),
                esc_html( $role_label ),
                $days
            );
        ?></h1>
        <?php if ( empty( $matching ) ) : ?>
            <p><em><?php esc_html_e( 'No active users in this role.', 'talenttrack' ); ?></em></p>
        <?php else :
            // Fetch last_seen per user.
            $ph = implode( ',', array_fill( 0, count( $matching ), '%d' ) );
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT user_id, MAX(created_at) AS last_seen, COUNT(*) AS event_count
                 FROM {$wpdb->prefix}tt_usage_events
                 WHERE user_id IN ({$ph}) AND created_at >= %s AND club_id = %d
                 GROUP BY user_id
                 ORDER BY last_seen DESC",
                ...array_merge( $matching, [ $cutoff, CurrentClub::id() ] )
            ) );
            ?>
            <table class="widefat striped" style="max-width:800px;">
                <thead><tr>
                    <th><?php esc_html_e( 'User', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'Events', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'Last seen', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'Actions', 'talenttrack' ); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ( (array) $rows as $r ) :
                    $user = get_userdata( (int) $r->user_id );
                    $name = $user ? $user->display_name : sprintf( '(user %d)', (int) $r->user_id );
                    $timeline_url = admin_url( 'admin.php?page=tt-usage-stats-details&metric=user_timeline&uid=' . (int) $r->user_id );
                    ?>
                    <tr>
                        <td><strong><?php echo esc_html( $name ); ?></strong></td>
                        <td style="font-variant-numeric:tabular-nums;"><?php echo (int) $r->event_count; ?></td>
                        <td><?php echo esc_html( (string) $r->last_seen ); ?></td>
                        <td><a href="<?php echo esc_url( $timeline_url ); ?>"><?php esc_html_e( 'Timeline', 'talenttrack' ); ?></a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <?php
    }

    private static function renderTopPage( int $days ): void {
        $slug = isset( $_GET['slug'] ) ? sanitize_key( (string) $_GET['slug'] ) : '';
        if ( $slug === '' ) {
            echo '<p>' . esc_html__( 'Missing page slug.', 'talenttrack' ) . '</p>';
            return;
        }
        global $wpdb;
        $cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT user_id, COUNT(*) AS visit_count, MAX(created_at) AS last_visit
             FROM {$wpdb->prefix}tt_usage_events
             WHERE event_type = 'admin_page_view' AND event_target = %s AND created_at >= %s AND club_id = %d
             GROUP BY user_id
             ORDER BY visit_count DESC, last_visit DESC",
            $slug, $cutoff, CurrentClub::id()
        ) );
        ?>
        <h1><?php
            printf(
                /* translators: 1: page label, 2: days */
                esc_html__( 'Visits to %1$s — last %2$d days', 'talenttrack' ),
                esc_html( self::pageLabel( $slug ) ),
                $days
            );
        ?></h1>
        <?php if ( empty( $rows ) ) : ?>
            <p><em><?php esc_html_e( 'No visits in this window.', 'talenttrack' ); ?></em></p>
        <?php else : ?>
            <table class="widefat striped" style="max-width:800px;">
                <thead><tr>
                    <th><?php esc_html_e( 'User', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'Visits', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'Last visit', 'talenttrack' ); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ( (array) $rows as $r ) :
                    $user = get_userdata( (int) $r->user_id );
                    $name = $user ? $user->display_name : sprintf( '(user %d)', (int) $r->user_id );
                    ?>
                    <tr>
                        <td><strong><?php echo esc_html( $name ); ?></strong></td>
                        <td style="font-variant-numeric:tabular-nums;"><?php echo (int) $r->visit_count; ?></td>
                        <td><?php echo esc_html( (string) $r->last_visit ); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <?php
    }

    private static function renderUserTimeline(): void {
        $uid = isset( $_GET['uid'] ) ? absint( $_GET['uid'] ) : 0;
        if ( $uid <= 0 ) {
            echo '<p>' . esc_html__( 'Missing user id.', 'talenttrack' ) . '</p>';
            return;
        }
        $user = get_userdata( $uid );
        $name = $user ? $user->display_name : sprintf( '(user %d)', $uid );

        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tt_usage_events
             WHERE user_id = %d AND club_id = %d
             ORDER BY created_at DESC
             LIMIT 500",
            $uid, CurrentClub::id()
        ) );
        ?>
        <h1><?php
            printf(
                /* translators: %s is user display name */
                esc_html__( 'Timeline — %s', 'talenttrack' ),
                esc_html( $name )
            );
        ?></h1>
        <p style="color:#666;"><?php
            printf(
                esc_html( _n( '%d event in the retention window (last 90 days).', '%d events in the retention window (last 90 days).', count( (array) $rows ), 'talenttrack' ) ),
                count( (array) $rows )
            );
        ?></p>
        <?php if ( empty( $rows ) ) : ?>
            <p><em><?php esc_html_e( 'No events recorded for this user in the retention window.', 'talenttrack' ); ?></em></p>
        <?php else : ?>
            <table class="widefat striped" style="max-width:900px;">
                <thead><tr>
                    <th><?php esc_html_e( 'Timestamp', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'Event', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'Target', 'talenttrack' ); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ( (array) $rows as $r ) : ?>
                    <tr>
                        <td style="white-space:nowrap;"><?php echo esc_html( (string) $r->created_at ); ?></td>
                        <td><code><?php echo esc_html( (string) $r->event_type ); ?></code></td>
                        <td><?php echo $r->event_target ? esc_html( self::pageLabel( (string) $r->event_target ) ) : '<span style="color:#888;">—</span>'; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <?php
    }

    // Helpers

    /** Render a user+timestamp table — shared shape for login lists. */
    private static function renderUserTimeTable( array $rows ): void {
        ?>
        <table class="widefat striped" style="max-width:700px;">
            <thead><tr>
                <th><?php esc_html_e( 'User', 'talenttrack' ); ?></th>
                <th><?php esc_html_e( 'Role', 'talenttrack' ); ?></th>
                <th><?php esc_html_e( 'Timestamp', 'talenttrack' ); ?></th>
            </tr></thead>
            <tbody>
            <?php if ( empty( $rows ) ) : ?>
                <tr><td colspan="3"><em><?php esc_html_e( 'No events.', 'talenttrack' ); ?></em></td></tr>
            <?php else : foreach ( $rows as $r ) :
                $user = get_userdata( (int) $r->user_id );
                $name = $user ? $user->display_name : sprintf( '(user %d)', (int) $r->user_id );
                ?>
                <tr>
                    <td><strong><?php echo esc_html( $name ); ?></strong></td>
                    <td><?php echo esc_html( self::userRole( (int) $r->user_id ) ); ?></td>
                    <td><?php echo esc_html( (string) $r->created_at ); ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
        <?php
    }

    private static function userRole( int $user_id ): string {
        $key = self::userRoleKey( $user_id );
        return [
            'admin'  => __( 'Admin', 'talenttrack' ),
            'coach'  => __( 'Coach', 'talenttrack' ),
            'player' => __( 'Player', 'talenttrack' ),
            'other'  => __( 'Other', 'talenttrack' ),
        ][ $key ];
    }

    private static function userRoleKey( int $user_id ): string {
        $user = get_userdata( $user_id );
        if ( ! $user ) return 'other';
        if ( user_can( $user, 'tt_edit_settings' ) ) return 'admin';
        if ( user_can( $user, 'tt_edit_evaluations' ) ) return 'coach';
        global $wpdb;
        $has_player = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}tt_players WHERE wp_user_id = %d AND club_id = %d LIMIT 1",
            $user_id, CurrentClub::id()
        ) );
        return $has_player ? 'player' : 'other';
    }

    private static function pageLabel( string $slug ): string {
        $map = [
            'talenttrack'          => __( 'Dashboard', 'talenttrack' ),
            'tt-teams'             => __( 'Teams', 'talenttrack' ),
            'tt-players'           => __( 'Players', 'talenttrack' ),
            'tt-people'            => __( 'People', 'talenttrack' ),
            'tt-evaluations'       => __( 'Evaluations', 'talenttrack' ),
            'tt-activities'          => __( 'Activities', 'talenttrack' ),
            'tt-goals'             => __( 'Goals', 'talenttrack' ),
            'tt-reports'           => __( 'Reports', 'talenttrack' ),
            'tt-rate-cards'        => __( 'Player Rate Cards', 'talenttrack' ),
            'tt-config'            => __( 'Configuration', 'talenttrack' ),
            'tt-custom-fields'     => __( 'Custom Fields', 'talenttrack' ),
            'tt-eval-categories'   => __( 'Evaluation Categories', 'talenttrack' ),
            'tt-category-weights'  => __( 'Category Weights', 'talenttrack' ),
            'tt-usage-stats'       => __( 'Usage Statistics', 'talenttrack' ),
            'tt-docs'              => __( 'Help & Docs', 'talenttrack' ),
        ];
        return $map[ $slug ] ?? $slug;
    }
}
