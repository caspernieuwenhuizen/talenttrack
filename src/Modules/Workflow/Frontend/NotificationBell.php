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

    /**
     * #2635 — this class no longer renders the bell.
     *
     * `Alerts\Frontend\AlertBell` does, because the count now includes
     * alerts and the destination has to follow it: a coach whose bell read
     * "3" because of three unmarked activities used to land on an empty task
     * list and conclude the bell was broken.
     *
     * What stays is `countFor()` and its `tt_notification_bell_count`
     * filter — Workflow contributes the task half of the number through
     * exactly the same seam every other source uses. Registering nothing
     * here is the whole migration; the class is now a counter, not chrome.
     *
     * Deliberately not deleted: `countFor()` is the API the new bell calls,
     * and removing the class would mean touching every workflow surface that
     * references it for no user-visible gain.
     */
    public static function init(): void {
    }

    public static function inject( string $html, int $user_id ): string {
        if ( $user_id <= 0 ) return $html;

        // #2631 — the cap gate now lets a user through when something is
        // actually waiting for them. `tt_view_own_tasks` is the right gate
        // for the tasks half, but the count also carries alerts, and a coach
        // who can edit activities without holding the tasks cap would
        // otherwise have alerts raised about them and no bell to show it.
        // Each contributing source gates its own occurrences on its own
        // capability, so a non-zero count here is already authorised.
        $count = self::countFor( $user_id );
        if ( $count <= 0 && ! user_can( $user_id, 'tt_view_own_tasks' ) ) return $html;

        $on_inbox = isset( $_GET['tt_view'] ) && $_GET['tt_view'] === 'my-tasks';
        if ( $count <= 0 && ! $on_inbox ) return $html;

        $url = self::inboxUrl();

        // v3.110.124 — pilot: "text for open tasks is too big, should
        // probably be an Icon with number in brackets behind it (3)".
        // Visible chrome is now a bell icon + `(3)` instead of the
        // full `3 open tasks` text; the full label moves to
        // `aria-label` so screen readers still announce "3 open
        // tasks, link". On the inbox itself with zero tasks, the
        // visible chrome is just the bell (no parenthesised 0).
        // #1365 — the bell is an inline SVG (was a bell emoji).
        $count_visual = $count > 0 ? '(' . (int) $count . ')' : '';
        // #2631 — the count is no longer tasks alone (see countFor), so the
        // announced label can no longer say "open tasks". A screen reader
        // hearing "3 open tasks" and finding one task and two alerts is
        // worse served than by the neutral wording.
        $aria_label   = $count > 0
            ? sprintf(
                /* translators: %d: number of items waiting for the user */
                _n( '%d item needs your attention', '%d items need your attention', $count, 'talenttrack' ),
                $count
            )
            : __( 'Nothing needs your attention', 'talenttrack' );

        $count_html = $count_visual !== ''
            ? '<span class="tt-dash-bell-count">' . esc_html( $count_visual ) . '</span>'
            : '';

        $bell = sprintf(
            '<a href="%1$s" class="tt-dash-bell-pill" aria-label="%2$s" title="%2$s" style="display:inline-flex; align-items:center; gap:4px; padding:4px 10px; background:%3$s; color:#fff; border-radius:999px; text-decoration:none; font-size:12px; font-weight:600; line-height:1.6;">'
                . '<span aria-hidden="true">' . \TT\Shared\Icons\IconRenderer::render( 'bell', [ 'width' => 13, 'height' => 13, 'style' => 'vertical-align:-2px;' ] ) . '</span>'
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
        // #2631 — see inject(): a non-zero count is already capability-gated
        // by whichever source contributed it, so the bell shows for anyone
        // who has something waiting.
        $count = self::countFor( $user_id );
        if ( $count <= 0 ) return;

        $aria_label = sprintf(
            /* translators: %d: number of items waiting for the user */
            _n( '%d item needs your attention', '%d items need your attention', $count, 'talenttrack' ),
            $count
        );

        // The bell icon + count, wrapped in spans so we can style
        // the count badge red. The title string contains HTML —
        // WP admin bar honours it as-is when `meta.html` is true.
        // #1365 — inline SVG (was a bell emoji).
        $title  = '<span class="ab-icon" style="margin-right:4px;" aria-hidden="true">' . \TT\Shared\Icons\IconRenderer::render( 'bell', [ 'width' => 15, 'height' => 15, 'style' => 'vertical-align:-3px;' ] ) . '</span>';
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
     * The bell's number.
     *
     * #2631 — was `FrontendMyTasksView::openCountForUser()` read directly at
     * both injection points. It now routes through a filter so other sources
     * of "things waiting for you" can contribute: the alerts engine is the
     * first, and a bell that counted only workflow tasks while the dashboard
     * showed three alert banners would be lying about what is waiting.
     *
     * Wave 5 (#2635) inverts this — the bell becomes an alerts surface and
     * tasks become one definition feeding it. This filter is the seam that
     * makes that a move rather than a rewrite.
     */
    public static function countFor( int $user_id ): int {
        $count = FrontendMyTasksView::openCountForUser( $user_id );
        return max( 0, (int) apply_filters( 'tt_notification_bell_count', $count, $user_id ) );
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
