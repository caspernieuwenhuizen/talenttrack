<?php
namespace TT\Shared\Frontend\Components;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Filters\SavedViewsRegistry;
use TT\Infrastructure\Filters\SavedViewsRepository;
use TT\Shared\Icons\IconRenderer;

/**
 * SavedViews (#2448, #3296) — personal saved views, rendered INSIDE the
 * filter bar's utility cluster.
 *
 * #3296 deleted the separate `tt-saved-views` band that used to sit above the
 * bar. Three things were wrong with it: it was a second row of chrome
 * competing with the bar for the scarce vertical space on a phone, where a
 * list opened with a saved-views band, then a filter bar, then finally rows;
 * its "Save filters" button rendered permanently, including on an unfiltered
 * list where saving is meaningless; and it never said which view you were
 * looking at — only the starred default was marked, so applying any other one
 * left nothing on screen to say so.
 *
 * What replaces it, per the decision locked on the issue:
 *
 *   - **View chips stay in the bar**, so applying a view is still one tap.
 *     They are apply links with no ✕ — deliberately NOT `.tt-chip`, which
 *     #3292 made removable. A chip the reader cannot remove that looks like
 *     one they can is worse than no chip.
 *   - **A bookmark icon owns save / rename / overwrite / delete / default.**
 *     It is absent entirely on an unfiltered list for a user with no views —
 *     the chips, not the icon, are the route to a saved view, so hiding it
 *     strands nothing.
 *   - **Chips are capped with a `+N` overflow** that opens the same dropdown
 *     as the icon, because several views would crowd the bar.
 *
 * The `saved-views.js` contract is unchanged: the root still carries
 * `data-tt-saved-views` / `data-view-key` / `data-keys`, each view still
 * carries `.tt-saved-views__item` with its id, name and default flag, and the
 * save form still uses the same four hooks. Only the presentation moved, so
 * the existing `<dialog>`-backed manage flow (#2451) keeps working with a new
 * trigger.
 */
final class SavedViews {

    /** @var bool Assets are shared across every bar on a request. */
    private static bool $assets_enqueued = false;

    /**
     * How many chips show before the rest collapse into `+N`.
     *
     * Enforced in CSS, not here: the server cannot know the viewport, and the
     * two counts differ. The markup renders every chip and both `+N` labels;
     * `frontend-filter-bar.css` reveals the right ones per breakpoint. These
     * constants exist so the two counts are computed from one place.
     */
    private const CHIP_CAP_DESKTOP = 3;
    private const CHIP_CAP_MOBILE  = 1;

    /**
     * @param string               $view_key    stable surface key, registered in SavedViewsRegistry.
     * @param array<int,string>    $param_names the filter params this surface owns (from FilterBar::paramNames()).
     * @param string               $base_url    the surface's own URL, without filter params.
     * @param array<string,string> $base_params params every apply link keeps (e.g. tt_view).
     */
    public static function html( string $view_key, array $param_names, string $base_url, array $base_params ): string {
        if ( $view_key === '' || ! is_user_logged_in() ) return '';
        // Fail closed on an unregistered surface: rendering a control whose
        // writes REST would then refuse is worse than rendering nothing.
        if ( ! SavedViewsRegistry::currentUserCan( $view_key ) ) return '';

        // #2808 — on a `read_only` surface reached from a phone, the chips
        // stay and everything that writes goes. Applying a saved view is a GET
        // and is the reason to have chips on a phone at all; naming,
        // overwriting and deleting one are desk work.
        //
        // Removed from the DOM rather than disabled: the class means "this
        // surface reads on a phone", and a disabled control still tells the
        // user the surface half-works.
        $read_only = self::readOnlyHere( $base_params );

        $views = ( new SavedViewsRepository() )->listForUser( get_current_user_id(), $view_key );

        // The default first, then the repository's own `name ASC, id ASC`.
        // Sorted once, here, so the chips and the dropdown agree: a default
        // that auto-applies on arrival should be the first thing read in
        // both, and it is the one chip guaranteed visible under the mobile
        // cap of one.
        usort( $views, static function ( $a, $b ): int {
            $ad = ! empty( $a->is_default ) ? 0 : 1;
            $bd = ! empty( $b->is_default ) ? 0 : 1;
            return $ad <=> $bd;
        } );

        $current = self::currentFilters( $param_names );
        $active  = self::matchingViewId( $views, $current );
        $has_filters = $current !== [];

        // The icon earns its place only when there is something for it to do:
        // filters worth saving, or views worth managing. On an untouched list
        // for a user who has never saved one it is not rendered at all.
        $show_icon = ! $read_only && ( $has_filters || $views !== [] );

        if ( $views === [] && ! $show_icon ) return '';

        self::enqueueAssets( $param_names, $read_only );

        // The capture keys ride on the element, not on a localised global:
        // two bars on one page would otherwise overwrite each other's list.
        $out = '<div class="tt-savedviews" data-tt-saved-views'
            . ' data-view-key="' . esc_attr( $view_key ) . '"'
            . ' data-keys="' . esc_attr( self::keysAttr( $param_names ) ) . '">';

        $out .= self::chipsHtml( $views, $base_url, $base_params, $active );

        if ( $show_icon ) {
            $out .= self::dropdownHtml( $views, $base_url, $base_params, $active, $view_key, count( $views ) );
        }

        $out .= '</div>';
        return $out;
    }

    /**
     * The view chips. Every view is rendered; CSS caps how many are visible.
     *
     * @param array<int,object>    $views
     * @param array<string,string> $base_params
     */
    private static function chipsHtml( array $views, string $base_url, array $base_params, int $active_id ): string {
        if ( $views === [] ) return '';

        $out = '<ul class="tt-viewchips">';
        foreach ( $views as $view ) {
            $filters = json_decode( (string) ( $view->filters_json ?? '' ), true );
            $filters = is_array( $filters ) ? $filters : [];
            $apply   = add_query_arg( array_map( 'strval', $filters + $base_params ), $base_url );
            $name    = (string) $view->name;
            $id      = (int) $view->id;

            $is_default = ! empty( $view->is_default );
            $is_active  = $id === $active_id;

            // `tt-saved-views__item` is kept as the JS hook: saved-views.js
            // reads the name and default flag off `closest()` of this class.
            $out .= '<li class="tt-viewchip tt-saved-views__item'
                . ( $is_active ? ' is-active' : '' ) . '"'
                . ' data-tt-view-id="' . $id . '"'
                . ' data-tt-view-name="' . esc_attr( $name ) . '"'
                . ' data-tt-view-default="' . ( $is_default ? '1' : '0' ) . '">';
            $out .= '<a class="tt-viewchip__apply" href="' . esc_url( $apply ) . '"'
                . ( $is_active ? ' aria-current="true"' : '' ) . '>';
            if ( $is_default ) {
                // #2450 — a marker, not colour alone: the default is applied
                // automatically, so the reader has to be able to see which
                // view they are looking at.
                $out .= '<span class="tt-viewchip__star" aria-hidden="true">&#9733;</span> ';
                $out .= '<span class="tt-screen-reader-text">'
                    . esc_html__( 'Default view:', 'talenttrack' ) . ' </span>';
            }
            $out .= esc_html( $name ) . '</a>';
            $out .= '</li>';
        }
        $out .= '</ul>';

        return $out;
    }

    /**
     * The bookmark trigger and its dropdown.
     *
     * One `<details>`, with the `+N` overflow chip and the icon both inside
     * its `<summary>` — which is how "either opens the same dropdown" is
     * satisfied without two triggers fighting over one panel. Built on the
     * `tt-perdrop` pattern the `⋯` menu already uses, so it stays
     * keyboard-operable and the apply links work with JS off.
     *
     * @param array<int,object>    $views
     * @param array<string,string> $base_params
     */
    private static function dropdownHtml(
        array $views,
        string $base_url,
        array $base_params,
        int $active_id,
        string $view_key,
        int $total
    ): string {
        $over_desktop = max( 0, $total - self::CHIP_CAP_DESKTOP );
        $over_mobile  = max( 0, $total - self::CHIP_CAP_MOBILE );

        $saved = $active_id > 0;
        $label = $saved
            ? __( 'Saved view options', 'talenttrack' )
            : __( 'Save these filters', 'talenttrack' );

        $out  = '<details class="tt-perdrop-wrap tt-savedviews__drop" data-tt-perdrop>';
        $out .= '<summary class="tt-savedviews__trigger' . ( $saved ? ' is-saved' : '' ) . '"'
            . ' aria-label="' . esc_attr( $label ) . '">';

        // The overflow counts. Both are rendered and CSS picks; a server that
        // guessed the viewport would be wrong half the time.
        if ( $over_desktop > 0 ) {
            $out .= '<span class="tt-viewchip tt-viewchip--more tt-viewchip--more-desktop" aria-hidden="true">'
                . esc_html( sprintf(
                    /* translators: %d: how many further saved views are collapsed behind the menu. */
                    _x( '+%d', 'saved views overflow count', 'talenttrack' ),
                    $over_desktop
                ) ) . '</span>';
        }
        if ( $over_mobile > 0 ) {
            $out .= '<span class="tt-viewchip tt-viewchip--more tt-viewchip--more-mobile" aria-hidden="true">'
                . esc_html( sprintf(
                    /* translators: %d: how many further saved views are collapsed behind the menu. */
                    _x( '+%d', 'saved views overflow count', 'talenttrack' ),
                    $over_mobile
                ) ) . '</span>';
        }

        $out .= '<span class="tt-savedviews__icon" aria-hidden="true">'
            . IconRenderer::render( 'bookmark' ) . '</span>';
        $out .= '</summary>';

        $out .= '<div class="tt-perdrop__menu tt-savedviews__menu" role="menu">';

        // 1 — every view as an apply link. The chips already show some of
        // them, but WHICH some depends on the viewport, and a menu whose
        // contents changed under a media query would be a menu nobody could
        // describe. Listing all of them is the honest version.
        if ( $views !== [] ) {
            $out .= '<p class="tt-savedviews__heading">'
                . esc_html_x( 'Your views', 'saved views menu section', 'talenttrack' ) . '</p>';
            foreach ( $views as $view ) {
                $filters = json_decode( (string) ( $view->filters_json ?? '' ), true );
                $filters = is_array( $filters ) ? $filters : [];
                $apply   = add_query_arg( array_map( 'strval', $filters + $base_params ), $base_url );
                $is_on   = (int) $view->id === $active_id;

                $out .= '<a class="tt-perdrop__opt' . ( $is_on ? ' tt-perdrop__opt--on' : '' ) . '"'
                    . ' role="menuitem" href="' . esc_url( $apply ) . '"'
                    . ( $is_on ? ' aria-current="true"' : '' ) . '>'
                    . ( ! empty( $view->is_default )
                        ? '<span aria-hidden="true">&#9733;</span> '
                        : '' )
                    . esc_html( (string) $view->name ) . '</a>';
            }
        }

        // 2 — actions. The manage button reuses saved-views.js's existing
        // `data-tt-view-manage` hook and its <dialog>; rename / overwrite /
        // set-default / delete all live behind it.
        $out .= '<p class="tt-savedviews__heading">'
            . esc_html_x( 'Actions', 'saved views menu section', 'talenttrack' ) . '</p>';

        if ( $active_id > 0 ) {
            $out .= '<button type="button" class="tt-perdrop__opt tt-savedviews__manage"'
                . ' data-tt-view-manage="' . $active_id . '"'
                . ' aria-haspopup="dialog">'
                . esc_html__( 'Rename, replace or delete this view', 'talenttrack' )
                . '</button>';
        }

        // Model B (CLAUDE.md §6): an explicit Save, so it takes a Cancel.
        // The exemption for an inline lookup editor does not apply — this is
        // a dropdown, not a row in a list you can click away from.
        $out .= '<div class="tt-savedviews__save">';
        $out .= '<button type="button" class="tt-perdrop__opt" data-tt-view-save-toggle'
            . ' aria-expanded="false">'
            . esc_html__( 'Save current filters', 'talenttrack' ) . '</button>';
        $out .= '<div class="tt-savedviews__save-form" data-tt-view-save-form hidden>';
        $out .= '<label class="tt-screen-reader-text" for="tt-view-name-' . esc_attr( $view_key ) . '">'
            . esc_html__( 'Name for this saved view', 'talenttrack' ) . '</label>';
        $out .= '<input type="text" id="tt-view-name-' . esc_attr( $view_key ) . '"'
            . ' class="tt-savedviews__name" data-tt-view-name'
            . ' maxlength="120" autocomplete="off"'
            . ' placeholder="' . esc_attr__( 'e.g. U17 league games', 'talenttrack' ) . '" />';
        $out .= '<div class="tt-savedviews__save-actions">';
        $out .= '<button type="button" class="tt-btn tt-btn-secondary" data-tt-view-save-cancel>'
            . esc_html__( 'Cancel', 'talenttrack' ) . '</button>';
        $out .= '<button type="button" class="tt-btn tt-btn-primary" data-tt-view-save-confirm>'
            . esc_html__( 'Save', 'talenttrack' ) . '</button>';
        $out .= '</div>';
        $out .= '</div>';
        $out .= '</div>';

        $out .= '</div>'; // .tt-perdrop__menu
        $out .= '</details>';

        return $out;
    }

    /**
     * The current request's values for this surface's params, normalised.
     *
     * Empty string and absent are the same thing — a select on its
     * placeholder is not a filter — so an empty value is dropped rather than
     * stored as ''. `paged` / `page` never appear because they are not in
     * `paramNames()`, and `SavedViewsDefaults::OFF_PARAM` is stripped: it is a
     * routing marker saying "do not auto-apply the default", not a filter.
     *
     * @param array<int,string> $param_names
     * @return array<string,string>
     */
    private static function currentFilters( array $param_names ): array {
        $out = [];
        foreach ( $param_names as $name ) {
            $name = is_string( $name ) ? trim( $name ) : '';
            if ( $name === '' ) continue;
            if ( $name === \TT\Infrastructure\Filters\SavedViewsDefaults::OFF_PARAM ) continue;
            if ( ! isset( $_GET[ $name ] ) ) continue; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

            $value = sanitize_text_field( wp_unslash( (string) $_GET[ $name ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            if ( $value === '' ) continue;
            $out[ $name ] = $value;
        }
        ksort( $out );
        return $out;
    }

    /**
     * The id of the view whose stored filters are exactly what is applied
     * now, or 0.
     *
     * Costs no extra query — the views are already loaded for the chips. The
     * comparison is on the normalised arrays, so a view saved before a param
     * was added still matches when that param is unset today.
     *
     * @param array<int,object>    $views
     * @param array<string,string> $current
     */
    private static function matchingViewId( array $views, array $current ): int {
        if ( $current === [] ) return 0;

        foreach ( $views as $view ) {
            $stored = json_decode( (string) ( $view->filters_json ?? '' ), true );
            if ( ! is_array( $stored ) ) continue;

            $normalised = [];
            foreach ( $stored as $k => $v ) {
                $k = (string) $k;
                $v = is_scalar( $v ) ? (string) $v : '';
                if ( $v === '' ) continue;
                if ( $k === \TT\Infrastructure\Filters\SavedViewsDefaults::OFF_PARAM ) continue;
                $normalised[ $k ] = $v;
            }
            ksort( $normalised );

            if ( $normalised === $current ) return (int) $view->id;
        }

        return 0;
    }

    /**
     * Is this render a read-only one — a `read_only` surface on a phone?
     *
     * The surface slug comes from `$base_params['tt_view']`, which every
     * caller already supplies so its apply links land back on the right
     * view. Reading it here keeps the classification check inside the
     * component instead of threading a flag through `FilterBar::render()`
     * and every call site.
     *
     * @param array<string,string> $base_params
     */
    private static function readOnlyHere( array $base_params ): bool {
        $slug = (string) ( $base_params['tt_view'] ?? '' );
        if ( $slug === '' ) return false;

        return \TT\Shared\MobileDetector::phoneGateApplies()
            && \TT\Shared\MobileSurfaceRegistry::isReadOnly( $slug );
    }

    /** @param array<int,string> $param_names */
    private static function keysAttr( array $param_names ): string {
        $clean = [];
        foreach ( $param_names as $name ) {
            $name = is_string( $name ) ? trim( $name ) : '';
            if ( $name !== '' ) $clean[] = $name;
        }
        return implode( ',', array_unique( $clean ) );
    }

    /**
     * The script is what writes; a read-only phone surface renders no control
     * that needs it, so it is not enqueued there (#2808).
     *
     * #3296 — the dedicated stylesheet is gone: the chips, the trigger and
     * the dropdown are part of the bar now, so their rules live in
     * `frontend-filter-bar.css` beside the cluster they sit in. The
     * `<dialog>` rules `saved-views.js` builds at runtime moved with them.
     *
     * @param array<int,string> $param_names
     */
    private static function enqueueAssets( array $param_names, bool $read_only = false ): void {
        // #2808 — the script exists to save, rename, overwrite and delete.
        // A read-only render emits none of the controls it binds to, so it
        // would sit inert on the one device with the tightest budget for it
        // (CLAUDE.md §2). The apply links are plain hrefs and need no JS.
        if ( $read_only ) return;

        wp_enqueue_script(
            'tt-saved-views',
            TT_PLUGIN_URL . 'assets/js/saved-views.js',
            [],
            TT_VERSION,
            true
        );

        if ( self::$assets_enqueued ) return;
        self::$assets_enqueued = true;

        wp_localize_script( 'tt-saved-views', 'TT_SavedViews', [
            'i18n' => [
                'name_required'     => __( 'Give this view a name first.', 'talenttrack' ),
                'saved'             => __( 'Saved.', 'talenttrack' ),
                'error'             => __( 'Something went wrong. Please try again.', 'talenttrack' ),
                // #2451 — <dialog>-backed confirms, replacing window.confirm /
                // window.alert (the pattern frontend-archive-button.js moved
                // to in v3.110.104 so the prompt is localised and readable to
                // a screen reader).
                'manage_title'      => __( 'Edit saved view', 'talenttrack' ),
                'name_label'        => __( 'Name', 'talenttrack' ),
                'overwrite_label'   => __( 'Also replace its filters with the ones set now', 'talenttrack' ),
                // #2450 — default view.
                'default_label'     => __( 'Open this view by default on this screen', 'talenttrack' ),
                'default_hint'      => __( 'Applied when you open the screen without filters of your own. Use Clear to see everything.', 'talenttrack' ),
                'delete_confirm'    => __( 'Delete this saved view? This cannot be undone.', 'talenttrack' ),
                'notice_title'      => __( 'Saved views', 'talenttrack' ),
                'delete'            => __( 'Delete', 'talenttrack' ),
                'cancel'            => __( 'Cancel', 'talenttrack' ),
                'save'              => __( 'Save', 'talenttrack' ),
                'ok'                => __( 'OK', 'talenttrack' ),
            ],
        ] );
    }
}
