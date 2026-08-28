<?php
namespace TT\Shared\Frontend\Components;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Filters\SavedViewsRegistry;
use TT\Infrastructure\Filters\SavedViewsRepository;

/**
 * SavedViews (#2448) — the personal "saved views" strip rendered above the
 * shared FilterBar.
 *
 * Promoted from `Modules\Analytics\Frontend\SavedFiltersBar` (#2385), which
 * lived inside the Analytics module and had to be hand-wired as a second
 * render call before `FilterBar::render()`. It is now part of the bar: a
 * surface opts in with a `saved_views` argument and FilterBar renders this.
 *
 * Personal only — the repository scopes every row to the current club AND
 * the calling user. Apply links are built server-side from each view's stored
 * params merged onto the surface's base params, so the strip works with JS
 * off; the script only adds save/delete.
 */
final class SavedViews {

    /** @var bool Assets are shared across every strip on a request. */
    private static bool $assets_enqueued = false;

    /**
     * @param string             $view_key    stable surface key, registered in SavedViewsRegistry.
     * @param array<int,string>  $param_names the filter params this surface owns (from FilterBar::paramNames()).
     * @param string             $base_url    the surface's own URL, without filter params.
     * @param array<string,string> $base_params params every apply link keeps (e.g. tt_view).
     */
    public static function html( string $view_key, array $param_names, string $base_url, array $base_params ): string {
        if ( $view_key === '' || ! is_user_logged_in() ) return '';
        // Fail closed on an unregistered surface: rendering a control whose
        // writes REST would then refuse is worse than rendering nothing.
        if ( ! SavedViewsRegistry::currentUserCan( $view_key ) ) return '';

        // #2808 — on a `read_only` surface reached from a phone, the strip
        // keeps its apply links and loses everything that writes. Applying a
        // saved view is a GET and is the reason to have the strip on a phone
        // at all; naming, overwriting and deleting one are desk work, and
        // they are also the controls that cannot hold the 48px floor once a
        // chip carries a manage button beside its label.
        //
        // Removed from the DOM rather than disabled: the class means "this
        // surface reads on a phone", and a disabled control still tells the
        // user the surface half-works. There is no banner either — see the
        // accepted risk on #2808.
        $read_only = self::readOnlyHere( $base_params );

        self::enqueueAssets( $param_names, $read_only );

        $views = ( new SavedViewsRepository() )->listForUser( get_current_user_id(), $view_key );

        // The capture keys ride on the element, not on a localised global:
        // two bars on one page would otherwise overwrite each other's list.
        $out  = '<div class="tt-saved-views" data-tt-saved-views'
            . ' data-view-key="' . esc_attr( $view_key ) . '"'
            . ' data-keys="' . esc_attr( self::keysAttr( $param_names ) ) . '">';

        if ( $views !== [] ) {
            $out .= '<span class="tt-saved-views__label">'
                . esc_html__( 'Saved views', 'talenttrack' ) . '</span>';
            $out .= '<ul class="tt-saved-views__list">';
            foreach ( $views as $view ) {
                $filters = json_decode( (string) ( $view->filters_json ?? '' ), true );
                $filters = is_array( $filters ) ? $filters : [];
                $apply   = add_query_arg( array_map( 'strval', $filters + $base_params ), $base_url );
                $name    = (string) $view->name;

                $is_default = ! empty( $view->is_default );

                $out .= '<li class="tt-saved-views__item'
                    . ( $is_default ? ' tt-saved-views__item--default' : '' ) . '"'
                    . ' data-tt-view-id="' . (int) $view->id . '"'
                    . ' data-tt-view-name="' . esc_attr( $name ) . '"'
                    . ' data-tt-view-default="' . ( $is_default ? '1' : '0' ) . '">';
                $out .= '<a class="tt-saved-views__apply" href="' . esc_url( $apply ) . '">';
                if ( $is_default ) {
                    // #2450 — a marker, not colour alone: the default is
                    // applied automatically, so the user has to be able to see
                    // which view they are looking at.
                    $out .= '<span class="tt-saved-views__star" aria-hidden="true">&#9733;</span> ';
                    $out .= '<span class="tt-screen-reader-text">'
                        . esc_html__( 'Default view:', 'talenttrack' ) . ' </span>';
                }
                $out .= esc_html( $name ) . '</a>';
                // #2451 — one manage control per chip rather than separate
                // rename / overwrite / delete buttons. Three icon buttons per
                // chip could not meet the 48px touch floor side by side at
                // 360px, and a strip of five views would carry fifteen of
                // them. The dialog behind this covers all three actions.
                if ( ! $read_only ) {
                    $out .= '<button type="button" class="tt-saved-views__manage"'
                        . ' data-tt-view-manage="' . (int) $view->id . '"'
                        . ' aria-haspopup="dialog"'
                        . ' aria-label="' . esc_attr( sprintf(
                            /* translators: %s: saved view name */
                            __( 'Edit or delete saved view %s', 'talenttrack' ),
                            $name
                        ) ) . '">&hellip;</button>';
                }
                $out .= '</li>';
            }
            $out .= '</ul>';
        }

        // #2448 — with no saved views the strip collapses to just the save
        // action. The empty-state line #2385 always printed was tolerable on
        // five reports; across every FilterBar surface it is permanent noise
        // for users who never save one.
        //
        // #2808 — which means that on a read-only phone surface with no
        // saved views there is nothing left to render, and the wrapper would
        // be an empty box. Return nothing instead.
        if ( $read_only ) {
            $out .= '</div>';
            return $views !== [] ? $out : '';
        }

        $out .= '<div class="tt-saved-views__save">';
        $out .= '<button type="button" class="tt-btn tt-btn-secondary" data-tt-view-save-toggle>'
            . esc_html__( 'Save filters', 'talenttrack' ) . '</button>';
        $out .= '<div class="tt-saved-views__save-form" data-tt-view-save-form hidden>';
        $out .= '<label class="tt-screen-reader-text" for="tt-view-name-' . esc_attr( $view_key ) . '">'
            . esc_html__( 'Name for this saved view', 'talenttrack' ) . '</label>';
        $out .= '<input type="text" id="tt-view-name-' . esc_attr( $view_key ) . '"'
            . ' class="tt-saved-views__name" data-tt-view-name'
            . ' maxlength="120" autocomplete="off"'
            . ' placeholder="' . esc_attr__( 'e.g. U17 league games', 'talenttrack' ) . '" />';
        $out .= '<button type="button" class="tt-btn tt-btn-primary" data-tt-view-save-confirm>'
            . esc_html__( 'Save', 'talenttrack' ) . '</button>';
        $out .= '</div>';
        $out .= '</div>';

        $out .= '</div>';
        return $out;
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
    private static function enqueueAssets( array $param_names, bool $read_only = false ): void {
        wp_enqueue_style(
            'tt-saved-views',
            TT_PLUGIN_URL . 'assets/css/frontend-saved-views.css',
            [ 'tt-public' ],
            TT_VERSION
        );

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

    /**
     * Per-surface capture keys, emitted as a data attribute rather than a
     * global so two bars on one page can't overwrite each other's list.
     *
     * @param array<int,string> $param_names
     */
    public static function keysAttr( array $param_names ): string {
        return implode( ',', array_map( 'strval', $param_names ) );
    }
}
