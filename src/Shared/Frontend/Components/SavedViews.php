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

        self::enqueueAssets( $param_names );

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

                $out .= '<li class="tt-saved-views__item">';
                $out .= '<a class="tt-saved-views__apply" href="' . esc_url( $apply ) . '">'
                    . esc_html( $name ) . '</a>';
                $out .= '<button type="button" class="tt-saved-views__del"'
                    . ' data-tt-view-delete="' . (int) $view->id . '"'
                    . ' aria-label="' . esc_attr( sprintf(
                        /* translators: %s: saved view name */
                        __( 'Delete saved view %s', 'talenttrack' ),
                        $name
                    ) ) . '">&times;</button>';
                $out .= '</li>';
            }
            $out .= '</ul>';
        }

        // #2448 — with no saved views the strip collapses to just the save
        // action. The empty-state line #2385 always printed was tolerable on
        // five reports; across every FilterBar surface it is permanent noise
        // for users who never save one.
        $out .= '<div class="tt-saved-views__save">';
        $out .= '<button type="button" class="tt-btn tt-btn-secondary" data-tt-view-save-toggle>'
            . esc_html__( 'Save current filters…', 'talenttrack' ) . '</button>';
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

    /** @param array<int,string> $param_names */
    private static function enqueueAssets( array $param_names ): void {
        wp_enqueue_style(
            'tt-saved-views',
            TT_PLUGIN_URL . 'assets/css/frontend-saved-views.css',
            [ 'tt-public' ],
            TT_VERSION
        );
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
                'name_required'  => __( 'Give this view a name first.', 'talenttrack' ),
                'saved'          => __( 'Saved.', 'talenttrack' ),
                'delete_confirm' => __( 'Delete this saved view?', 'talenttrack' ),
                'error'          => __( 'Something went wrong. Please try again.', 'talenttrack' ),
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
