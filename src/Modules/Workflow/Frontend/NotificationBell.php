<?php
namespace TT\Modules\Workflow\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * NotificationBell — small chrome inserted at the top of the dashboard
 * shortcode showing the open-task count for the current user. Clicking
 * jumps to `?tt_view=my-tasks`.
 *
 * Hooks `tt_dashboard_data` (the same filter `DashboardShortcode`
 * passes the rendered HTML through) and prepends the bell. This keeps
 * the bell entirely opt-in to the Workflow module — DashboardShortcode
 * does not need to know about it.
 *
 * Hidden when the user has zero open tasks AND isn't on the inbox view
 * (no chrome unless there's something to surface).
 */
class NotificationBell {

    public static function init(): void {
        add_filter( 'tt_dashboard_data', [ self::class, 'inject' ], 10, 2 );
    }

    public static function inject( string $html, int $user_id ): string {
        if ( $user_id <= 0 ) return $html;
        if ( ! user_can( $user_id, 'tt_view_own_tasks' ) ) return $html;

        $count = FrontendMyTasksView::openCountForUser( $user_id );
        $on_inbox = isset( $_GET['tt_view'] ) && $_GET['tt_view'] === 'my-tasks';
        if ( $count <= 0 && ! $on_inbox ) return $html;

        $url = self::inboxUrl();
        $bell = sprintf(
            '<div class="tt-bell-wrap" style="display:flex; justify-content:flex-end; margin: 4px 0 -4px;">'
                . '<a href="%s" class="tt-bell" style="display:inline-flex; align-items:center; gap:6px; padding:5px 10px; background:%s; color:#fff; border-radius:999px; text-decoration:none; font-size:12px; font-weight:600;">'
                    . '<span aria-hidden="true">🔔</span>'
                    . '<span>%s</span>'
                . '</a>'
            . '</div>',
            esc_url( $url ),
            $count > 0 ? '#b32d2e' : '#5b6e75',
            $count > 0
                ? esc_html( sprintf(
                    /* translators: %d: number of open tasks */
                    _n( '%d open task', '%d open tasks', $count, 'talenttrack' ),
                    $count
                ) )
                : esc_html__( 'No open tasks', 'talenttrack' )
        );

        // Prepend the bell so it sits above the header. Doesn't break
        // the existing wrapping div produced by DashboardShortcode.
        return $bell . $html;
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
