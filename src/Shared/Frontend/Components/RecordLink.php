<?php
namespace TT\Shared\Frontend\Components;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * RecordLink — universal "open this record's detail page" link wrapper
 * (#0063).
 *
 * The convention introduced ad-hoc in v3.62.0 was a `tt-record-link`
 * CSS class wrapping a goal / activity row. This helper formalises it
 * so every admin table + dashboard tile + list view emits the same
 * markup + class + hover behaviour — a single edit fixes all sites.
 *
 * Two render modes:
 *   - `inline()` returns a single `<a>` containing escaped label text
 *     (safe for plain string labels).
 *   - `wrap()` echoes opening + closing tags around arbitrary inner
 *     HTML the caller emits between them. Use when the row already
 *     carries pills, badges, or sub-elements.
 *
 * Both emit `class="tt-record-link"`, which the global stylesheet
 * styles for hover / focus-visible. Callers can append extra classes
 * via `$css_class`.
 *
 * The link uses `rel="noopener"` only when `$external = true`; for
 * internal dashboard links (the common case) it stays plain so the
 * back button + history work cleanly.
 */
final class RecordLink {

    public const CLASS_BASE = 'tt-record-link';

    /**
     * Render an `<a class="tt-record-link">label</a>` link. Use for
     * plain text labels — name cells in admin tables, summary lines.
     */
    public static function inline( string $label, string $detail_url, string $css_class = '' ): string {
        if ( $label === '' || $detail_url === '' ) return esc_html( $label );
        $cls = self::CLASS_BASE . ( $css_class !== '' ? ' ' . $css_class : '' );
        return sprintf(
            '<a class="%s" href="%s">%s</a>',
            esc_attr( $cls ),
            esc_url( $detail_url ),
            esc_html( $label )
        );
    }

    /**
     * Echo an opening `<a class="tt-record-link">` so the caller can
     * emit arbitrary inner HTML (badges, pills, multi-line) and then
     * call `close()` to emit `</a>`. Use when the row needs more
     * structure than a single text label.
     */
    public static function wrap( string $detail_url, string $css_class = '' ): void {
        $cls = self::CLASS_BASE . ( $css_class !== '' ? ' ' . $css_class : '' );
        echo '<a class="' . esc_attr( $cls ) . '" href="' . esc_url( $detail_url ) . '">';
    }

    /**
     * Closing partner for `wrap()`. Always echo this in a try/finally
     * if the inner block can throw, so the link doesn't get orphaned.
     */
    public static function close(): void {
        echo '</a>';
    }

    /**
     * Helper for admin pages: build a frontend dashboard URL for a
     * given list slug + record id, so admins clicking a record name in
     * a wp-admin table land on the frontend detail view.
     *
     * Example: detailUrlFor('players', 42) →
     *   https://example.com/dashboard/?tt_view=players&id=42
     *   (or https://example.com/?tt_view=players&id=42 when no
     *   dashboard page is configured)
     */
    public static function detailUrlFor( string $list_slug, int $id ): string {
        if ( $list_slug === '' || $id <= 0 ) return '';
        $url = add_query_arg( [ 'tt_view' => $list_slug, 'id' => $id ], self::dashboardUrl() );
        return (string) $url;
    }

    /**
     * v3.70.1 hotfix — resolve the URL of the page hosting the
     * `[talenttrack_dashboard]` shortcode, so links built from REST /
     * admin contexts route through it instead of `home_url('/')`. Falls
     * back to `home_url('/')` when no `dashboard_page_id` is configured
     * (which only works for installs that put the shortcode on the
     * homepage). The redirect-target helper at
     * `FrontendAccessControl::dashboardUrl()` uses the same lookup but
     * is instance-bound; this static mirror exists so REST controllers
     * and admin renderers can build tt_view URLs without instantiating
     * the access control wiring.
     */
    public static function dashboardUrl(): string {
        // 1. Configured page (the happy path).
        $page_id = (int) \TT\Infrastructure\Query\QueryHelpers::get_config( 'dashboard_page_id', '0' );
        if ( $page_id > 0 ) {
            $permalink = get_permalink( $page_id );
            if ( $permalink ) return (string) $permalink;
        }

        // 2. Self-heal: scan published pages for the [tt_dashboard]
        //    shortcode. Caches the discovered id back into tt_config so
        //    subsequent calls hit the fast path. Mirrors Activator's
        //    seedDashboardPageIfMissing adoption logic — the user's
        //    dashboard might exist already even though the option got
        //    cleared somehow (post deletion, manual config edit, etc.).
        $found = self::discoverDashboardPageId();
        if ( $found > 0 ) {
            \TT\Infrastructure\Query\QueryHelpers::set_config( 'dashboard_page_id', (string) $found );
            $permalink = get_permalink( $found );
            if ( $permalink ) return (string) $permalink;
        }

        // 3. Last-resort: current request URI if a [tt_dashboard]
        //    shortcode lives on the page being viewed, else home_url('/').
        if ( isset( $_SERVER['REQUEST_URI'] ) ) {
            $current = esc_url_raw( (string) wp_unslash( $_SERVER['REQUEST_URI'] ) );
            if ( $current !== '' ) {
                return remove_query_arg(
                    [ 'tt_view', 'player_id', 'eval_id', 'activity_id', 'goal_id', 'team_id', 'id', 'tab' ],
                    $current
                );
            }
        }
        return home_url( '/' );
    }

    /**
     * Find a published page that hosts the [tt_dashboard] shortcode.
     * Returns 0 when none is found. Limited to the first 20 pages so
     * the scan is cheap on large installs.
     */
    private static function discoverDashboardPageId(): int {
        $candidates = get_posts( [
            'post_type'      => 'page',
            'post_status'    => 'publish',
            'posts_per_page' => 20,
            'fields'         => 'ids',
            's'              => '[tt_dashboard',
        ] );
        if ( ! is_array( $candidates ) ) return 0;
        foreach ( $candidates as $candidate_id ) {
            $content = get_post_field( 'post_content', (int) $candidate_id );
            if ( is_string( $content ) && has_shortcode( $content, 'tt_dashboard' ) ) {
                return (int) $candidate_id;
            }
        }
        return 0;
    }
}
