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

    /**
     * #3990 — the query parameter naming the saved view the reader opened.
     * Forms that rebuild the URL carry it as a hidden field (`openedField()`).
     */
    public const OPENED_PARAM = 'sv';

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

        // #3990 — the view the reader opened, from the `sv` its apply link
        // carries. Only one of this reader's views on this surface counts:
        // `$views` is already scoped to both, so an id belonging to someone
        // else, or to a deleted view, finds nothing and is ignored. When the
        // filters no longer match it, the menu offers to update it.
        //
        // The action is rendered whenever a view was opened, and hidden while
        // there is nothing to update: the filters still match it, or none is
        // set (Clear leaves the view). A list that filters in place changes
        // the URL without a reload, so `saved-views.js` re-checks against the
        // stored filters each time the menu opens.
        $opened = self::openedView( $views );
        $update = null;
        if ( $opened !== null ) {
            $update = [
                'id'      => (int) ( $opened->id ?? 0 ),
                'name'    => (string) ( $opened->name ?? '' ),
                'filters' => self::normalisedFilters( (string) ( $opened->filters_json ?? '' ) ) ?? [],
                'pending' => $has_filters && (int) ( $opened->id ?? 0 ) !== $active,
            ];
        }

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

        // Decoded once, here, and handed to both renderers as plain arrays.
        // The chips and the dropdown each need a name, an id, the default
        // flag and an apply URL; reading them off the row twice meant
        // decoding the same JSON twice and touching the same untyped
        // properties in two places.
        $prepared = self::prepare( $views, $base_url, $base_params, $active );

        $out .= self::chipsHtml( $prepared );

        if ( $show_icon ) {
            $out .= self::dropdownHtml(
                $prepared,
                $active,
                $view_key,
                count( $prepared ),
                $update
            );
        }

        $out .= '</div>';
        return $out;
    }

    /**
     * Flatten the repository rows into what the two renderers need.
     *
     * This is the ONE place a saved-view row's properties are read. The rows
     * come back untyped from `$wpdb`, so every extra `$view->name` is another
     * unchecked property access; decoding and normalising once keeps that to
     * a single spot and stops the two renderers drifting on what an apply URL
     * is.
     *
     * @param array<int,object>    $views
     * @param array<string,string> $base_params
     * @return list<array{id:int, name:string, is_default:bool, is_active:bool, apply:string}>
     */
    private static function prepare( array $views, string $base_url, array $base_params, int $active_id ): array {
        $out = [];
        foreach ( $views as $view ) {
            $filters = json_decode( (string) ( $view->filters_json ?? '' ), true );
            $filters = is_array( $filters ) ? $filters : [];
            $id      = (int) $view->id;

            // #3990 — `sv` says which view was opened, so a reader who changes
            // a filter afterwards can update this view rather than only save a
            // new one. It is not one of the surface's filters, so it never
            // takes part in matching.
            $out[] = [
                'id'         => $id,
                'name'       => (string) $view->name,
                'is_default' => ! empty( $view->is_default ),
                'is_active'  => $id === $active_id,
                'apply'      => add_query_arg( array_map( 'strval', [ self::OPENED_PARAM => (string) $id ] + $filters + $base_params ), $base_url ),
            ];
        }
        return $out;
    }

    /**
     * The view chips. Every view is rendered; CSS caps how many are visible.
     *
     * @param list<array{id:int, name:string, is_default:bool, is_active:bool, apply:string}> $views
     */
    private static function chipsHtml( array $views ): string {
        if ( $views === [] ) return '';

        $out = '<ul class="tt-viewchips">';
        foreach ( $views as $view ) {
            // `tt-saved-views__item` is kept as the JS hook: saved-views.js
            // reads the name and default flag off `closest()` of this class.
            $out .= '<li class="tt-viewchip tt-saved-views__item'
                . ( $view['is_active'] ? ' is-active' : '' ) . '"'
                . ' data-tt-view-id="' . $view['id'] . '"'
                . ' data-tt-view-name="' . esc_attr( $view['name'] ) . '"'
                . ' data-tt-view-default="' . ( $view['is_default'] ? '1' : '0' ) . '">';
            $out .= '<a class="tt-viewchip__apply" href="' . esc_url( $view['apply'] ) . '"'
                . ( $view['is_active'] ? ' aria-current="true"' : '' ) . '>';
            if ( $view['is_default'] ) {
                // #2450 — a marker, not colour alone: the default is applied
                // automatically, so the reader has to be able to see which
                // view they are looking at.
                $out .= '<span class="tt-viewchip__star" aria-hidden="true">&#9733;</span> ';
                $out .= '<span class="tt-screen-reader-text">'
                    . esc_html__( 'Default view:', 'talenttrack' ) . ' </span>';
            }
            $out .= esc_html( $view['name'] ) . '</a>';
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
     * @param list<array{id:int, name:string, is_default:bool, is_active:bool, apply:string}> $views
     * @param array{id:int, name:string, filters:array<string,string>, pending:bool}|null   $update #3990 the opened view; `pending` when the filters no longer match it
     */
    private static function dropdownHtml(
        array $views,
        int $active_id,
        string $view_key,
        int $total,
        ?array $update = null
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
                $is_on = $view['is_active'];

                $out .= '<a class="tt-perdrop__opt' . ( $is_on ? ' tt-perdrop__opt--on' : '' ) . '"'
                    . ' role="menuitem" href="' . esc_url( $view['apply'] ) . '"'
                    . ( $is_on ? ' aria-current="true"' : '' ) . '>'
                    . ( $view['is_default']
                        ? '<span aria-hidden="true">&#9733;</span> '
                        : '' )
                    . esc_html( $view['name'] ) . '</a>';
            }
        }

        // 2 — actions. The manage button reuses saved-views.js's existing
        // `data-tt-view-manage` hook and its <dialog>; rename, set-default and
        // delete live behind it. Replacing the filters is the Update action
        // above (#3990), so the dialog no longer carries it.
        $out .= '<p class="tt-savedviews__heading">'
            . esc_html_x( 'Actions', 'saved views menu section', 'talenttrack' ) . '</p>';

        // #3990 — first, because it is what a reader who opened a view and
        // changed a filter came here for. Writes the filters only.
        if ( $update !== null ) {
            $out .= '<button type="button" class="tt-perdrop__opt tt-savedviews__update"'
                . ' data-tt-view-update="' . (int) $update['id'] . '"'
                . ' data-tt-view-filters="' . esc_attr( (string) wp_json_encode( (object) $update['filters'] ) ) . '"'
                . ( $update['pending'] ? '' : ' hidden' ) . '>'
                . esc_html( sprintf(
                    /* translators: %s: the name of the reader's saved view */
                    __( 'Update “%s”', 'talenttrack' ),
                    $update['name']
                ) )
                . '</button>';
        }

        if ( $active_id > 0 ) {
            $out .= '<button type="button" class="tt-perdrop__opt tt-savedviews__manage"'
                . ' data-tt-view-manage="' . $active_id . '"'
                . ' aria-haspopup="dialog">'
                . esc_html__( 'Rename, set as default or delete this view', 'talenttrack' )
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
            $name = trim( (string) $name );
            if ( $name === '' ) continue;
            if ( $name === \TT\Infrastructure\Filters\SavedViewsDefaults::OFF_PARAM ) continue;

            $value = self::requestValue( $name );
            if ( $value === null ) continue;

            $out[ $name ] = $value;
        }
        ksort( $out );
        return $out;
    }

    /**
     * The current request's value for one of `paramNames()`'s names, or null.
     *
     * `paramNames()` yields the **form field name**, and every
     * `FrontendListTable` surface names its filters `filter[<key>]`. PHP never
     * creates a `$_GET['filter[team_id]']` key — a query string of
     * `filter[team_id]=2` arrives as `$_GET['filter']['team_id']` — so a flat
     * lookup dropped every nested filter and kept only the flat ones
     * (`search`, `orderby`, `order`).
     *
     * That is what left a reader who narrowed a list with its dropdowns with
     * no way to save the view: `$has_filters` was false, so the bookmark
     * control was never rendered for anyone who had not already saved one.
     * It also meant a stored view containing `filter[team_id]` could never
     * equal the live URL, so `matchingViewId()` never fired on a list.
     *
     * The value is returned under the caller's **bracketed** name, because
     * that is the shape `saved-views.js` writes: it reads the raw query
     * string through `URLSearchParams`, where `filter[team_id]` survives
     * intact. Both sides must speak the same key or the comparison is
     * meaningless.
     *
     * @return string|null the sanitised scalar value, or null when absent,
     *                     empty, or an array (`filter[x][]=a&filter[x][]=b`) —
     *                     a repeated param is not a saved-view filter, and
     *                     casting it would print "Array" plus a notice.
     */
    private static function requestValue( string $name ): ?string {
        // #3333 — lifted into `FilterParam` so the bar's chip-clearing side
        // reads the same shape. Same defect, two layers; one implementation.
        return \TT\Infrastructure\Filters\FilterParam::requestValue( $name );
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
            $normalised = self::normalisedFilters( (string) ( $view->filters_json ?? '' ) );
            if ( $normalised === null ) continue;

            if ( $normalised === $current ) return (int) $view->id;
        }

        return 0;
    }

    /**
     * A view's stored filters in the shape `currentFilters()` produces, so
     * the two compare: scalar values as strings, empties and the no-default
     * marker dropped, keys sorted. Null when the JSON is not a filter set.
     *
     * @return array<string,string>|null
     */
    private static function normalisedFilters( string $json ): ?array {
        $stored = json_decode( $json, true );
        if ( ! is_array( $stored ) ) return null;

        $normalised = [];
        foreach ( $stored as $k => $v ) {
            $k = (string) $k;
            $v = is_scalar( $v ) ? (string) $v : '';
            if ( $v === '' ) continue;
            if ( $k === \TT\Infrastructure\Filters\SavedViewsDefaults::OFF_PARAM ) continue;
            $normalised[ $k ] = $v;
        }
        ksort( $normalised );
        return $normalised;
    }

    /**
     * #3990 — the view named by `sv`, among this reader's views on this
     * surface, or null. Anything else — another reader's id, a deleted view,
     * junk — is ignored.
     *
     * @param array<int,object> $views
     */
    private static function openedView( array $views ): ?object {
        $id = isset( $_GET[ self::OPENED_PARAM ] ) && is_scalar( $_GET[ self::OPENED_PARAM ] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view state.
            ? absint( $_GET[ self::OPENED_PARAM ] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            : 0;
        if ( $id <= 0 ) return null;

        foreach ( $views as $view ) {
            if ( (int) ( $view->id ?? 0 ) === $id ) return $view;
        }
        return null;
    }

    /**
     * #3990 — the opened view's id as a hidden field, for a form that rebuilds
     * the URL (the filter bar, the report composition panels), so changing a
     * filter keeps knowing which view it started from. Empty when there is
     * none. Unvalidated beyond being a positive integer: `openedView()` checks
     * ownership when it is read.
     *
     * @return array<string,string>
     */
    public static function openedField(): array {
        $id = isset( $_GET[ self::OPENED_PARAM ] ) && is_scalar( $_GET[ self::OPENED_PARAM ] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view state.
            ? absint( $_GET[ self::OPENED_PARAM ] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            : 0;
        return $id > 0 ? [ self::OPENED_PARAM => (string) $id ] : [];
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
    public static function keysAttr( array $param_names ): string {
        return implode( ',', array_map( 'strval', $param_names ) );
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
                // #3990 — saving under a name already taken offers to update
                // that view instead of refusing.
                'replace_confirm'   => __( 'A view called “%s” exists. Replace it with these filters?', 'talenttrack' ),
                'replace'           => __( 'Replace', 'talenttrack' ),
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
