<?php
namespace TT\Modules\Workflow\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * NotificationBell — open-task pill rendered inside the dashboard
 * actions row, alongside the DEMO pill / help icon / user menu.
 *
 * Hooks `tt_dashboard_actions_html` (the filter DashboardShortcode
 * exposes inside `.tt-dash-actions`). DashboardShortcode itself stays
 * unaware of the workflow module.
 *
 * Hidden when the user has zero open tasks AND isn't on the inbox view
 * (no chrome unless there's something to surface).
 */
class NotificationBell {

    public static function init(): void {
        add_filter( 'tt_dashboard_actions_html', [ self::class, 'inject' ], 10, 2 );
    }

    public static function inject( string $html, int $user_id ): string {
        if ( $user_id <= 0 ) return $html;
        if ( ! user_can( $user_id, 'tt_view_own_tasks' ) ) return $html;

        $count = FrontendMyTasksView::openCountForUser( $user_id );
        $on_inbox = isset( $_GET['tt_view'] ) && $_GET['tt_view'] === 'my-tasks';
        if ( $count <= 0 && ! $on_inbox ) return $html;

        $url = self::inboxUrl();
        $label = $count > 0
            ? sprintf(
                /* translators: %d: number of open tasks */
                _n( '%d open task', '%d open tasks', $count, 'talenttrack' ),
                $count
            )
            : __( 'No open tasks', 'talenttrack' );

        $bell = sprintf(
            '<a href="%s" class="tt-dash-bell-pill" style="display:inline-flex; align-items:center; gap:6px; padding:4px 10px; background:%s; color:#fff; border-radius:999px; text-decoration:none; font-size:12px; font-weight:600; line-height:1.6;">'
                . '<span aria-hidden="true">🔔</span>'
                . '<span>%s</span>'
            . '</a>',
            esc_url( $url ),
            $count > 0 ? '#b32d2e' : '#5b6e75',
            esc_html( $label )
        );

        return $html . $bell;
    }

    private static function inboxUrl(): string {
        $current = '';
        if ( isset( $_SERVER['REQUEST_URI'] ) ) {
            $current = esc_url_raw( (string) wp_unslash( $_SERVER['REQUEST_URI'] ) );
        }
        $base = remove_query_arg( [ 'tt_view', 'task_id' ], $current ?: home_url( '/' ) );
        return add_query_arg( 'tt_view', 'my-tasks', $base );
    }
}
