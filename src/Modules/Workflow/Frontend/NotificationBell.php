<?php
namespace TT\Modules\Workflow\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * NotificationBell — open-task pill rendered inside the dashboard
 * actions row, alongside the DEMO pill / help icon / user menu.
 *
 * Two injection points:
 *
 *  1. `tt_dashboard_actions_html` — the filter DashboardShortcode
 *     exposes inside `.tt-dash-actions`. DashboardShortcode itself
 *     stays unaware of the workflow module.
 *
 *  2. `admin_bar_menu` (v3.110.144) — appends a node to the WP admin
 *     bar so the bell is visible on every WP-served page (wp-admin
 *     and any front-end page where the toolbar is on). Pilot ask:
 *     "should be shown on all pages and it should be clickable and
 *     lead to the my task pane filtered on open and active tasks
 *     only." The toolbar is the cheapest site-wide chrome.
 *
 * Hidden when the user has zero open tasks AND isn't on the inbox view
 * (no chrome unless there's something to surface).
 */
class NotificationBell {

    public static function init(): void {
        add_filter( 'tt_dashboard_actions_html', [ self::class, 'inject' ], 10, 2 );
        // v3.110.144 — site-wide admin-bar injection. Priority 100
        // pushes the node toward the right side of the bar (after
        // most plugin / theme nodes but before the user menu at 9999).
        add_action( 'admin_bar_menu', [ self::class, 'injectAdminBar' ], 100 );
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

    /**
     * v3.110.144 — append a notification-bell node to the WP admin
     * bar. Visible on every page where the toolbar renders (all
     * wp-admin pages + any front-end page with `show_admin_bar=true`).
     *
     * Self-hides when the user has zero open tasks — no node added.
     * No "(0)" pill cluttering the bar on a clean inbox.
     */
    public static function injectAdminBar( \WP_Admin_Bar $wp_admin_bar ): void {
        $user_id = get_current_user_id();
        if ( $user_id <= 0 ) return;
        if ( ! user_can( $user_id, 'tt_view_own_tasks' ) ) return;
        $count = FrontendMyTasksView::openCountForUser( $user_id );
        if ( $count <= 0 ) return;

        $aria_label = sprintf(
            /* translators: %d: number of open tasks */
            _n( '%d open task', '%d open tasks', $count, 'talenttrack' ),
            $count
        );

        // The bell glyph + count, wrapped in spans so we can style
        // the count badge red. The title string contains HTML —
        // WP admin bar honours it as-is when `meta.html` is true.
        $title  = '<span class="ab-icon" style="margin-right:4px;" aria-hidden="true">🔔</span>';
        $title .= '<span class="ab-label" style="background:#b32d2e;color:#fff;border-radius:999px;padding:1px 8px;font-weight:600;">(' . (int) $count . ')</span>';

        $wp_admin_bar->add_node( [
            'id'    => 'tt-notification-bell',
            'title' => $title,
            'href'  => self::inboxUrl(),
            'meta'  => [
                'title' => $aria_label,
                'class' => 'tt-admin-bar-bell',
            ],
        ] );
    }

    /**
     * Inbox URL. Resolves the front-end dashboard page (where
     * `[talenttrack_dashboard]` lives) and appends `?tt_view=my-tasks`.
     *
     * v3.110.144 — was REQUEST_URI-relative, which broke when the
     * admin-bar variant of the bell rendered on a wp-admin page
     * (the link bounced back to wp-admin instead of the front-end
     * dashboard). Routing through `WizardEntryPoint::dashboardBaseUrl()`
     * gives a canonical URL that works from any page context. The
     * `WizardEntryPoint` helper is the existing canonical resolver
     * for the dashboard page — used by every wizard for the same
     * reason.
     */
    private static function inboxUrl(): string {
        if ( class_exists( '\\TT\\Shared\\Wizards\\WizardEntryPoint' ) ) {
            $base = \TT\Shared\Wizards\WizardEntryPoint::dashboardBaseUrl();
        } else {
            $base = home_url( '/' );
        }
        return add_query_arg( 'tt_view', 'my-tasks', $base );
    }
}
