<?php
namespace TT\Modules\Alerts\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Alerts\Domain\Surface;
use TT\Modules\Alerts\Services\AlertCounter;
use TT\Shared\Icons\IconRenderer;

/**
 * AlertBell (#2635, epic #2629) — the notification bell, now alerts-first.
 *
 * ## What changed and why
 *
 * The bell was `Workflow\Frontend\NotificationBell`: it counted open tasks,
 * and #2631 added a filter so alerts could contribute a number to it. That
 * was a deliberate half-step — a bell that counted only tasks while the
 * dashboard showed three alert banners was lying about what was waiting.
 *
 * The half-step's remaining problem is where it goes. Clicking it lands on
 * the task inbox, so a coach whose bell reads "3" because of three unmarked
 * activities arrives at an empty task list and concludes the bell is broken.
 * This class fixes that: the bell counts both, and routes to whichever
 * inbox the count actually came from — the alerts inbox when alerts
 * dominate, tasks when tasks do, and the alerts inbox on a tie because that
 * is the surface with a filter that can show you everything.
 *
 * Tasks are no longer a hard-wired special case; they are one contributor
 * to a count that other sources can join through the same filter.
 *
 * ## Why the old class stays
 *
 * `NotificationBell` keeps its `tt_notification_bell_count` filter and
 * remains the thing Workflow contributes through. Deleting it would mean
 * touching every workflow surface that references it, for no user-visible
 * gain. It stops rendering; it keeps counting.
 *
 * Styling moved to `assets/css/frontend-alerts.css` — the old bell inlined
 * its colours in both injection points, which the §2 inline-style rule
 * forbids and which made the count badge impossible to theme.
 */
final class AlertBell {

    public static function init(): void {
        add_filter( 'tt_dashboard_actions_html', [ self::class, 'inject' ], 10, 2 );
        add_action( 'admin_bar_menu', [ self::class, 'injectAdminBar' ], 100 );
    }

    /**
     * Total waiting for this user, and where it came from.
     *
     * `capped` (#4222) is true when the alert half hit `AlertCounter::CEILING`,
     * so the total is a floor and the bell reads "50+", not "50".
     *
     * @return array{total:int,alerts:int,tasks:int,capped:bool}
     */
    public static function tally( int $user_id ): array {
        $counted = AlertCounter::forUser( $user_id, Surface::BADGE );
        $alerts  = $counted['count'];
        $total   = \TT\Modules\Workflow\Frontend\NotificationBell::countFor( $user_id );

        // The workflow class's filter already added the alert count, so the
        // task share is the remainder. Deriving it rather than querying
        // tasks again keeps one source of truth for the total — if the two
        // were counted independently they could disagree, and the bell would
        // show a number that matches neither inbox.
        return [
            'total'  => $total,
            'alerts' => $alerts,
            'tasks'  => max( 0, $total - $alerts ),
            'capped' => $counted['capped'],
        ];
    }

    public static function inject( string $html, int $user_id ): string {
        if ( $user_id <= 0 ) return $html;

        $tally = self::tally( $user_id );
        if ( $tally['total'] <= 0 && ! self::onAnInbox() ) return $html;

        $count = $tally['total'];
        $label = self::ariaLabel( $count, $tally['capped'] );

        $bell = sprintf(
            '<a href="%1$s" class="tt-bell%2$s" aria-label="%3$s" title="%3$s">'
                . '<span class="tt-bell__icon" aria-hidden="true">%4$s</span>'
                . '%5$s'
            . '</a>',
            esc_url( self::destinationFor( $tally ) ),
            $count > 0 ? ' tt-bell--active' : '',
            esc_attr( $label ),
            IconRenderer::render( 'bell', [ 'width' => 13, 'height' => 13 ] ),
            $count > 0
                ? '<span class="tt-bell__count">' . esc_html( AlertCounter::display( $count, $tally['capped'] ) ) . '</span>'
                : ''
        );

        return $html . $bell;
    }

    public static function injectAdminBar( \WP_Admin_Bar $wp_admin_bar ): void {
        $user_id = get_current_user_id();
        if ( $user_id <= 0 ) return;

        $tally = self::tally( $user_id );
        if ( $tally['total'] <= 0 ) return;

        $title  = '<span class="ab-icon tt-bell__icon" aria-hidden="true">'
            . IconRenderer::render( 'bell', [ 'width' => 15, 'height' => 15 ] )
            . '</span>';
        $title .= '<span class="ab-label tt-bell__count">' . esc_html( AlertCounter::display( $tally['total'], $tally['capped'] ) ) . '</span>';

        $wp_admin_bar->add_node( [
            'id'    => 'tt-notification-bell',
            'title' => $title,
            'href'  => self::destinationFor( $tally ),
            'meta'  => [
                'title' => self::ariaLabel( $tally['total'], $tally['capped'] ),
                'class' => 'tt-admin-bar-bell',
            ],
        ] );
    }

    /**
     * Where the bell goes.
     *
     * Whichever inbox holds more of what is waiting; alerts on a tie, and
     * alerts when both are zero (which only happens while already on an
     * inbox). The alerts inbox is the better tie-break because it has
     * filters that can show the whole picture, whereas the task list cannot
     * show an alert at all.
     *
     * @param array{total:int,alerts:int,tasks:int,capped:bool} $tally
     */
    private static function destinationFor( array $tally ): string {
        $slug = $tally['tasks'] > $tally['alerts'] ? 'my-tasks' : 'alerts';
        return (string) add_query_arg( 'tt_view', $slug, self::dashboardBase() ); /* tt-xview-ok */
    }

    private static function ariaLabel( int $count, bool $capped = false ): string {
        if ( $count <= 0 ) return __( 'Nothing needs your attention', 'talenttrack' );
        if ( $capped ) {
            return sprintf(
                /* translators: %d: lower bound of the number of items waiting for the user, e.g. 50 */
                __( '%d or more items need your attention', 'talenttrack' ),
                $count
            );
        }
        return sprintf(
            /* translators: %d: number of items waiting for the user */
            _n( '%d item needs your attention', '%d items need your attention', $count, 'talenttrack' ),
            $count
        );
    }

    /** True while the user is looking at either inbox, so the bell persists at zero. */
    private static function onAnInbox(): bool {
        $view = isset( $_GET['tt_view'] ) ? sanitize_key( (string) $_GET['tt_view'] ) : '';
        return $view === 'my-tasks' || $view === 'alerts';
    }

    private static function dashboardBase(): string {
        if ( class_exists( '\\TT\\Shared\\Wizards\\WizardEntryPoint' ) ) {
            return \TT\Shared\Wizards\WizardEntryPoint::dashboardBaseUrl();
        }
        return home_url( '/' );
    }
}
