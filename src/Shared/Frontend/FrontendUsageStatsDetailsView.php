<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * FrontendUsageStatsDetailsView — frontend mirror of the wp-admin
 * UsageStatsDetailsPage drill-downs.
 *
 * Reachable via ?tt_view=usage-stats-details&metric=<key> with the
 * same query parameters as the admin equivalent (days, date, role,
 * slug, uid). Reuses the tile-based admin shell and frontend table
 * styles — no admin chrome, no full-page wp-admin wrap.
 *
 * Gated by tt_access_frontend_admin (matches FrontendUsageStatsView).
 *
 * Supported metrics:
 *   - logins
 *   - active_users
 *   - dau_day (&date=YYYY-MM-DD)
 *   - evals_day (&date=YYYY-MM-DD)
 *   - active_by_role (&role=)
 *   - top_page (&slug=)
 *   - user_timeline (&uid=)
 */
class FrontendUsageStatsDetailsView extends FrontendViewBase {

    public static function render( int $user_id, bool $is_admin ): void {
        if ( ! current_user_can( 'tt_access_frontend_admin' ) ) {
            \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard( __( 'Not authorized', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'You do not have permission to view this section.', 'talenttrack' ) . '</p>';
            return;
        }

        self::enqueueAssets();

        $metric = isset( $_GET['metric'] ) ? sanitize_key( (string) $_GET['metric'] ) : '';
        $days   = isset( $_GET['days'] ) ? max( 1, min( 90, absint( $_GET['days'] ) ) ) : 30;

        \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard(
            __( 'Usage detail', 'talenttrack' ),
            [ \TT\Shared\Frontend\Components\FrontendBreadcrumbs::viewCrumb( 'usage-stats', __( 'Application KPIs', 'talenttrack' ) ) ]
        );

        switch ( $metric ) {
            case 'logins':         self::renderLogins( $days );       break;
            case 'active_users':   self::renderActiveUsers( $days );  break;
            case 'dau_day':        self::renderDauDay();              break;
            case 'evals_day':      self::renderEvalsDay();            break;
            case 'active_by_role': self::renderActiveByRole( $days ); break;
            case 'top_page':       self::renderTopPage( $days );      break;
            case 'user_timeline':  self::renderUserTimeline();        break;
            default:
                echo '<h1 class="tt-fview-title">' . esc_html__( 'Usage detail', 'talenttrack' ) . '</h1>';
                echo '<p>' . esc_html__( 'Unknown metric.', 'talenttrack' ) . '</p>';
        }
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
        <h1 class="tt-fview-title"><?php
            printf(
                /* translators: %d is number of days in the window */
                esc_html__( 'Logins — last %d days', 'talenttrack' ),
                $days
            );
        ?></h1>
        <p style="color:var(--tt-muted);"><?php
            printf(
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
        <h1 class="tt-fview-title"><?php
            printf(
                esc_html__( 'Active users — last %d days', 'talenttrack' ),
                $days
            );
        ?></h1>
        <table class="tt-table">
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
                $timeline_url = self::detailsUrl( [ 'metric' => 'user_timeline', 'uid' => (int) $r->user_id ] );
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

    private static function resolveDayParam(): string {
        $date = isset( $_GET['date'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['date'] ) ) : '';
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
            $date = current_time( 'Y-m-d' );
        }
        return $date;
    }

    private static function renderDayPicker( string $metric, string $date, string $heading ): void {
        $prev = (string) gmdate( 'Y-m-d', (int) strtotime( $date . ' -1 day' ) );
        $next = (string) gmdate( 'Y-m-d', (int) strtotime( $date . ' +1 day' ) );
        $prev_url = self::detailsUrl( [ 'metric' => $metric, 'date' => $prev ] );
        $next_url = self::detailsUrl( [ 'metric' => $metric, 'date' => $next ] );
        $action   = self::pageBaseUrl();
        ?>
        <h1 class="tt-fview-title"><?php echo esc_html( $heading ); ?></h1>
        <form method="get" action="<?php echo esc_url( $action ); ?>" class="tt-panel" style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
            <?php self::echoPreservedHiddenFields( [ 'tt_view', 'metric' ] ); ?>
            <input type="hidden" name="tt_view" value="usage-stats-details" />
            <input type="hidden" name="metric" value="<?php echo esc_attr( $metric ); ?>" />
            <a href="<?php echo esc_url( $prev_url ); ?>" class="tt-btn tt-btn-secondary" title="<?php esc_attr_e( 'Previous day', 'talenttrack' ); ?>">←</a>
            <label for="tt-fe-usage-date" style="font-size:13px;">
                <?php esc_html_e( 'Pick a day:', 'talenttrack' ); ?>
            </label>
            <input type="date" id="tt-fe-usage-date" name="date" value="<?php echo esc_attr( $date ); ?>" style="font-size:13px;" />
            <button type="submit" class="tt-btn tt-btn-primary"><?php esc_html_e( 'Go', 'talenttrack' ); ?></button>
            <a href="<?php echo esc_url( $next_url ); ?>" class="tt-btn tt-btn-secondary" title="<?php esc_attr_e( 'Next day', 'talenttrack' ); ?>">→</a>
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
        <?php if ( empty( $rows ) ) : ?>
            <p><em><?php esc_html_e( 'No activity on this day.', 'talenttrack' ); ?></em></p>
        <?php else : ?>
            <table class="tt-table">
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
        <?php endif; ?>
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
            <table class="tt-table">
                <thead><tr>
                    <th><?php esc_html_e( 'Created', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'Player', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'Type', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'Coach', 'talenttrack' ); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ( (array) $rows as $r ) : ?>
                    <tr>
                        <td><?php echo esc_html( (string) $r->created_at ); ?></td>
                        <td><?php echo esc_html( (string) $r->player_name ); ?></td>
                        <td><?php echo esc_html( (string) ( $r->type_name ?: '—' ) ); ?></td>
                        <td><?php echo esc_html( (string) $r->coach_name ); ?></td>
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
        <h1 class="tt-fview-title"><?php
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
            <table class="tt-table">
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
                    $timeline_url = self::detailsUrl( [ 'metric' => 'user_timeline', 'uid' => (int) $r->user_id ] );
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
        <h1 class="tt-fview-title"><?php
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
            <table class="tt-table">
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
        <h1 class="tt-fview-title"><?php
            printf(
                /* translators: %s is user display name */
                esc_html__( 'Timeline — %s', 'talenttrack' ),
                esc_html( $name )
            );
        ?></h1>
        <p style="color:var(--tt-muted);"><?php
            printf(
                esc_html( _n( '%d event in the retention window (last 90 days).', '%d events in the retention window (last 90 days).', count( (array) $rows ), 'talenttrack' ) ),
                count( (array) $rows )
            );
        ?></p>
        <?php if ( empty( $rows ) ) : ?>
            <p><em><?php esc_html_e( 'No events recorded for this user in the retention window.', 'talenttrack' ); ?></em></p>
        <?php else : ?>
            <table class="tt-table">
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

    private static function renderUserTimeTable( array $rows ): void {
        ?>
        <table class="tt-table">
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

    /**
     * Build a frontend drill-down URL on the same shortcode page,
     * preserving the dashboard pageʼs path. Caller supplies the
     * tt_view + drill-down params; everything else is dropped.
     *
     * @param array<string,scalar> $params
     */
    public static function detailsUrl( array $params ): string {
        $params = array_merge( [ 'tt_view' => 'usage-stats-details' ], $params );
        return add_query_arg( $params, self::pageBaseUrl() );
    }

    private static function pageBaseUrl(): string {
        $base = '';
        if ( isset( $_SERVER['REQUEST_URI'] ) ) {
            $base = esc_url_raw( (string) wp_unslash( $_SERVER['REQUEST_URI'] ) );
        }
        if ( $base === '' ) $base = home_url( '/' );
        // Strip every drill-down param we know about so we get a clean
        // shortcode-page URL to rebuild from.
        return remove_query_arg(
            [ 'tt_view', 'metric', 'days', 'date', 'role', 'slug', 'uid' ],
            $base
        );
    }

    /**
     * Echo <input type="hidden"> fields for the current GET params,
     * minus the keys named in $exclude. Lets a GET form preserve
     * other context (e.g. page slug) without re-listing it.
     *
     * @param string[] $exclude
     */
    private static function echoPreservedHiddenFields( array $exclude ): void {
        foreach ( $_GET as $k => $v ) {
            if ( ! is_string( $k ) ) continue;
            if ( in_array( $k, $exclude, true ) ) continue;
            if ( ! is_string( $v ) && ! is_numeric( $v ) ) continue;
            printf(
                '<input type="hidden" name="%s" value="%s" />',
                esc_attr( $k ),
                esc_attr( (string) $v )
            );
        }
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
