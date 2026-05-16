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

        // v3.110.124 — pilot: "text for open tasks is too big, should
        // probably be an Icon with number in brackets behind it (3)".
        // Visible chrome is now `🔔 (3)` instead of `🔔 3 open tasks`;
        // full label moves to `aria-label` so screen readers still
        // announce "3 open tasks, link". On the inbox itself with zero
        // tasks, the visible chrome is just `🔔` (no parenthesised 0).
        $count_visual = $count > 0 ? '(' . (int) $count . ')' : '';
        $aria_label   = $count > 0
            ? sprintf(
                /* translators: %d: number of open tasks */
                _n( '%d open task', '%d open tasks', $count, 'talenttrack' ),
                $count
            )
            : __( 'No open tasks', 'talenttrack' );

        $count_html = $count_visual !== ''
            ? '<span class="tt-dash-bell-count">' . esc_html( $count_visual ) . '</span>'
            : '';

        $bell = sprintf(
            '<a href="%1$s" class="tt-dash-bell-pill" aria-label="%2$s" title="%2$s" style="display:inline-flex; align-items:center; gap:4px; padding:4px 10px; background:%3$s; color:#fff; border-radius:999px; text-decoration:none; font-size:12px; font-weight:600; line-height:1.6;">'
                . '<span aria-hidden="true">🔔</span>'
                . '%4$s'
            . '</a>',
            esc_url( $url ),
            esc_attr( $aria_label ),
            $count > 0 ? '#b32d2e' : '#5b6e75',
            $count_html
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
