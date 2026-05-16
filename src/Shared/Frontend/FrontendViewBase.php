<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * FrontendViewBase — shared render utilities for v3.0.0 focused
 * frontend views.
 *
 * Each sub-view class (FrontendOverviewView, FrontendMyTeamView, …)
 * extends this to get:
 *
 *   - A page header with optional title + breadcrumb chain
 *   - Asset enqueueing (player-card CSS + mobile CSS, once per request)
 *   - Consistent wrap styling
 *
 * Sub-views don't render a tab bar — they're each one focused page.
 * Navigation is the breadcrumb chain (FrontendBreadcrumbs::fromDashboard)
 * plus the tt_back-borne pill the breadcrumb component auto-renders
 * above it. See docs/back-navigation.md.
 */
abstract class FrontendViewBase {

    private static bool $assets_enqueued = false;

    /**
     * Enqueue shared frontend assets. Idempotent across the request.
     */
    protected static function enqueueAssets(): void {
        if ( self::$assets_enqueued ) return;

        \TT\Modules\Stats\Admin\PlayerCardView::enqueueStyles();

        wp_enqueue_style(
            'tt-frontend-mobile',
            TT_PLUGIN_URL . 'assets/css/frontend-mobile.css',
            [],
            TT_VERSION
        );

        // Client-side table sort + search. Safe no-op on views without
        // a .tt-table-sortable element; cheap to load once per request.
        wp_enqueue_script(
            'tt-table-tools',
            TT_PLUGIN_URL . 'assets/js/tt-table-tools.js',
            [],
            TT_VERSION,
            true
        );
        wp_localize_script(
            'tt-table-tools',
            'ttTableToolsStrings',
            [
                'search'            => __( 'Search:', 'talenttrack' ),
                'searchPlaceholder' => __( 'Filter rows…', 'talenttrack' ),
                'rowsTotal'         => __( '{n} row(s)', 'talenttrack' ),
                'rowsFiltered'      => __( '{v} of {n}', 'talenttrack' ),
            ]
        );

        // v3.110.53 — Archive-button handler for detail pages. Listens
        // for clicks on [data-tt-archive-rest-path], shows the modal,
        // POSTs DELETE /wp-json/talenttrack/v1/<path>, redirects on
        // success. No-op on pages without the data attribute, cheap
        // to load once per request alongside table-tools.
        // v3.110.104 — confirm path moved from `window.confirm()` to a
        // <dialog>-backed app modal; localised strings get passed via
        // `wp_localize_script` below so screen readers + non-English
        // installs read the modal in the coach's language.
        wp_enqueue_script(
            'tt-frontend-archive-button',
            TT_PLUGIN_URL . 'assets/js/frontend-archive-button.js',
            [ 'tt-public' ],
            TT_VERSION,
            true
        );
        wp_localize_script(
            'tt-frontend-archive-button',
            'TT_ArchiveI18n',
            [
                'title'   => __( 'Archive record', 'talenttrack' ),
                'cancel'  => __( 'Cancel', 'talenttrack' ),
                'confirm' => __( 'Archive', 'talenttrack' ),
            ]
        );

        self::$assets_enqueued = true;
    }

    /**
     * Render the view header: breadcrumbs (when declared via the static
     * override) and the page title.
     *
     * v3.110.41 — removed the FrontendBackButton fallback. Per
     * docs/back-navigation.md the two canonical nav affordances are the
     * tt_back-borne pill (auto-rendered above the breadcrumb chain by
     * FrontendBreadcrumbs::render()) and the breadcrumb itself. Views
     * that need a dynamic chain call FrontendBreadcrumbs::fromDashboard()
     * directly before renderHeader(); views with a static chain override
     * static::breadcrumbs() and let renderHeader emit it.
     */
    protected static function renderHeader( string $title, string $actions_html = '' ): void {
        $crumbs = static::breadcrumbs();
        if ( ! empty( $crumbs ) ) {
            \TT\Shared\Frontend\Components\FrontendBreadcrumbs::render( $crumbs );
        }
        if ( $actions_html !== '' ) {
            echo '<header class="tt-page-head">';
            echo '<h1 class="tt-fview-title" style="font-size:22px; color:#1a1d21;">'
                . esc_html( $title )
                . '</h1>';
            echo '<div class="tt-page-actions">' . $actions_html . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — pageActionsHtml escapes per-action.
            echo '</header>';
            return;
        }
        echo '<h1 class="tt-fview-title" style="margin:6px 0 18px; font-size:22px; color:#1a1d21;">'
            . esc_html( $title )
            . '</h1>';
    }

    /**
     * v3.110.53 — Build the HTML for a page-actions slot from a structured
     * array. Pair with `renderHeader( $title, self::pageActionsHtml( $actions ) )`
     * on list / detail views, or compose into a manual `<header class="tt-page-head">`
     * on views that bypass renderHeader (e.g. FrontendPlayerDetailView).
     *
     * Each action accepts:
     *   - 'label'      (required): visible button text.
     *   - 'href'       (optional): target URL for `<a>` actions. Omit
     *                   to render `<button type="button">` driven by
     *                   `data_attrs` (e.g. archive REST DELETE).
     *   - 'primary'    (optional, default false): true → main CTA,
     *                   styled with the primary variant by default.
     *                   (Pre-v3.110.74 this also turned the action into
     *                   a floating bottom-right FAB on mobile; the FAB
     *                   was dropped because it overlapped inline
     *                   content and hid the secondary action entirely.)
     *   - 'icon'       (optional): glyph (e.g. '+') prefixed before
     *                   the label on primary actions, both desktop
     *                   and mobile.
     *   - 'variant'    (optional): 'primary' | 'secondary' | 'danger'.
     *                   Defaults to primary when 'primary' is true,
     *                   else secondary. Use 'danger' for destructive.
     *   - 'cap'        (optional): capability gate. Action is skipped
     *                   if the current user lacks it.
     *   - 'confirm'    (optional): native confirm() text. Cancels the
     *                   navigation / submit if the user dismisses.
     *   - 'data_attrs' (optional): map of `data-*` → value pairs for
     *                   client-side hooks (e.g. archive button).
     *
     * @param array<int,array<string,mixed>> $actions
     */
    public static function pageActionsHtml( array $actions ): string {
        if ( empty( $actions ) ) return '';
        $html = '';
        foreach ( $actions as $a ) {
            if ( ! is_array( $a ) || empty( $a['label'] ) ) continue;
            if ( ! empty( $a['cap'] ) && ! current_user_can( (string) $a['cap'] ) ) continue;
            $is_primary = ! empty( $a['primary'] );
            $href       = (string) ( $a['href'] ?? '' );
            $label      = (string) $a['label'];
            $icon       = (string) ( $a['icon'] ?? '' );
            $confirm    = (string) ( $a['confirm'] ?? '' );
            $variant    = ! empty( $a['variant'] ) ? (string) $a['variant'] : ( $is_primary ? 'primary' : 'secondary' );

            // v3.110.122 — default a bin SVG icon on danger-variant
            // actions when the caller didn't supply one. Archive is
            // the canonical danger action across every detail surface;
            // adding the icon centrally avoids 8 callsite edits and
            // gives the icon-only mobile rendering a glyph to show.
            if ( $icon === '' && $variant === 'danger' ) {
                $icon = self::BIN_ICON_SVG;
            }

            $cls = 'tt-btn tt-btn-' . sanitize_html_class( $variant );
            $cls .= $is_primary ? ' tt-page-actions__primary' : ' tt-page-actions__secondary';
            // v3.110.122 — `is-icon` flag on buttons that carry an icon
            // (any variant, not just primary). Drives the mobile
            // icon-only rendering — see persona-dashboard.css's
            // `.tt-page-actions__primary.is-icon`, etc.
            if ( $icon !== '' ) $cls .= ' is-icon';

            $attr_html = '';
            if ( ! empty( $a['data_attrs'] ) && is_array( $a['data_attrs'] ) ) {
                foreach ( $a['data_attrs'] as $key => $value ) {
                    $attr_html .= ' data-' . esc_attr( (string) $key ) . '="' . esc_attr( (string) $value ) . '"';
                }
            }
            if ( $confirm !== '' ) {
                $attr_html .= ' onclick="return confirm(' . esc_attr( wp_json_encode( $confirm ) ) . ')"';
            }
            // v3.110.122 — `aria-label` mirrors the visible label so the
            // mobile icon-only rendering stays accessible (the label
            // span hides at ≤767px; screen readers fall through to
            // aria-label).
            $attr_html .= ' aria-label="' . esc_attr( $label ) . '"';

            $inner = '';
            if ( $icon !== '' ) {
                // SVG icons (start with `<`) pass through as-is; single
                // characters / short text get escaped. Icon span is now
                // emitted on EVERY variant (was: primary only) so
                // danger buttons render with their bin glyph too.
                $is_svg = ( $icon !== '' && $icon[0] === '<' );
                $inner .= '<span class="tt-page-actions__icon" aria-hidden="true">'
                       . ( $is_svg ? $icon : esc_html( $icon ) )
                       . '</span>';
            }
            $inner .= '<span class="tt-page-actions__label">' . esc_html( $label ) . '</span>';

            if ( $href !== '' ) {
                $html .= '<a href="' . esc_url( $href ) . '" class="' . esc_attr( $cls ) . '"' . $attr_html . '>' . $inner . '</a>';
            } else {
                $html .= '<button type="button" class="' . esc_attr( $cls ) . '"' . $attr_html . '>' . $inner . '</button>';
            }
        }
        return $html;
    }

    /**
     * v3.110.122 — inline SVG bin icon for danger-variant
     * page-actions. Sized at 16×16 with `currentColor` so it picks
     * up the button's text colour at every state (rest red, hover
     * white-on-red).
     */
    private const BIN_ICON_SVG = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path fill="currentColor" d="M6.5 1.75a.75.75 0 0 1 .75-.75h1.5a.75.75 0 0 1 .75.75V3h3.25a.75.75 0 0 1 0 1.5h-.628l-.55 8.252A2.25 2.25 0 0 1 9.327 14.75H6.673a2.25 2.25 0 0 1-2.246-2.998L3.878 4.5H3.25a.75.75 0 0 1 0-1.5H6.5V1.75zM5.382 4.5l.544 8.156a.75.75 0 0 0 .748.594h2.654a.75.75 0 0 0 .748-.594L10.618 4.5H5.382z"/></svg>';

    /**
     * Sub-views override this to declare a static breadcrumb chain
     * (#0077 F2). Each item: `[ 'label' => string, 'url' => ?string ]`.
     * The last item is the current page and gets no link. Empty array
     * (default) means renderHeader() emits no breadcrumbs — the view
     * is expected to call FrontendBreadcrumbs::fromDashboard() itself
     * before calling renderHeader() (the dynamic-chain pattern).
     *
     * @return array<int,array{label:string,url?:?string}>
     */
    protected static function breadcrumbs(): array {
        return [];
    }
}
