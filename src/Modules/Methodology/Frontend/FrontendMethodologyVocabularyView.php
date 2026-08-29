<?php
namespace TT\Modules\Methodology\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Methodology\VocabularyCatalog;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;
use TT\Shared\Frontend\Components\RecordSpine;
use TT\Shared\Frontend\FrontendViewBase;

/**
 * FrontendMethodologyVocabularyView (#2976, child of #2874) — maintaining
 * the academy's own methodology without leaving the product.
 *
 * Nine wp-admin pages maintained the nine vocabularies an academy expresses
 * its football in: the vision, the principles, the phases of play, the
 * influence factors, the positions, the learning goals, the set pieces, the
 * primer and the football actions. `?tt_view=methodology` shows all of it
 * and lets nobody change any of it, so the one thing this product is most
 * opinionated about was the one thing an academy could not edit without
 * being dropped into WordPress — one click from the plugin and user screens
 * the capability model does not describe.
 *
 * ## One surface, one slug, one picker
 *
 * Nine sibling editors must not become nine frontend routes. That would copy
 * the wp-admin shape onto the frontend and re-create exactly the navigation
 * sprawl CLAUDE.md §5b exists to prevent. There is one route, and a picker
 * that switches which vocabulary is open.
 *
 * The picker is **content, not navigation**: it never leaves this surface's
 * subject — the academy's methodology — so §5c applies and it is not a third
 * nav affordance. It is rendered by the shared `RecordSpine` rather than a
 * hand-rolled strip, which is what §5c requires of a new surface.
 *
 * ## The browser talks to the REST API, not to this class
 *
 * Every vocabulary already has a REST controller extending
 * `AbstractMethodologyRestController`, all gated on `tt_edit_methodology`
 * and club-scoped. This view renders a shell and hands the browser the
 * shape of the open vocabulary out of `VocabularyCatalog`; the list, the
 * form and the four verbs are the existing endpoints. So the answers a
 * future SaaS front end gets from those endpoints are the answers rendered
 * here, and nothing about an entity is decided twice (§4).
 *
 * ## Shipped rows
 *
 * Reference content ships read-only and the REST layer answers 409 to a
 * write against it. The client marks those rows and offers no edit, so the
 * refusal is visible before the click rather than after it.
 *
 * ## §6 Save + Cancel
 *
 * Exemption (b): the editor is inline in the list. Cancel is the list itself
 * — the form's own Cancel closes it and returns to the rows, which is what
 * the exemption describes.
 *
 * Wizard plan: exemption (a) — lookup / vocabulary edits.
 */
class FrontendMethodologyVocabularyView extends FrontendViewBase {

    public const SLUG = 'methodology-vocabulary';
    public const CAP  = 'tt_edit_methodology';

    public static function render( int $user_id, bool $is_admin ): void {
        if ( ! current_user_can( self::CAP ) ) {
            FrontendBreadcrumbs::fromDashboard( __( 'Not authorized', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'You do not have permission to edit the methodology.', 'talenttrack' ) . '</p>';
            return;
        }

        $requested = isset( $_GET[ VocabularyCatalog::PARAM ] )
            ? sanitize_key( (string) wp_unslash( $_GET[ VocabularyCatalog::PARAM ] ) )
            : '';
        $slug  = VocabularyCatalog::resolveSlug( $requested );
        $all   = VocabularyCatalog::all();
        $vocab = $all[ $slug ];

        FrontendBreadcrumbs::fromDashboard(
            __( 'Methodology vocabulary', 'talenttrack' ),
            [ FrontendBreadcrumbs::viewCrumb( 'configuration', __( 'Configuration', 'talenttrack' ) ) ]
        );

        self::enqueueAssets();
        self::renderHeader( __( 'Methodology vocabulary', 'talenttrack' ) );

        echo '<p class="tt-muted tt-mv-intro">'
            . esc_html__( 'Your academy describes its football in its own words. Everything here is that vocabulary — the words every learning goal, exercise and evaluation is written in.', 'talenttrack' )
            . '</p>';

        self::renderPicker( $all, $slug );

        echo '<section class="tt-mv" data-tt-mv aria-live="polite">';
        echo '<h2 class="tt-mv-title">' . esc_html( (string) $vocab['label'] ) . '</h2>';
        echo '<p class="tt-mv-blurb">' . esc_html( (string) $vocab['blurb'] ) . '</p>';
        echo '<div class="tt-mv-status" data-tt-mv-status>' . esc_html__( 'Loading…', 'talenttrack' ) . '</div>';
        echo '<div class="tt-mv-parent" data-tt-mv-parent hidden></div>';
        echo '<div class="tt-mv-list" data-tt-mv-list></div>';
        echo '<div class="tt-mv-editor" data-tt-mv-editor hidden></div>';
        echo '</section>';

        self::localise( $slug, $vocab );
    }

    /**
     * The vocabulary picker.
     *
     * Navigating tabs rather than in-page panels: nine vocabularies over
     * differently-shaped REST resources would mean nine hydrated lists on
     * one page load, most of which the operator will not open. A page load
     * per vocabulary is the cheaper honest answer, and `RecordSpine`
     * supports both kinds.
     *
     * @param array<string,array<string,mixed>> $all
     */
    private static function renderPicker( array $all, string $active ): void {
        $base = remove_query_arg( [ VocabularyCatalog::PARAM, 'id' ] );

        $tabs = [];
        foreach ( $all as $slug => $vocab ) {
            $tabs[] = [
                'label'  => (string) $vocab['label'],
                'url'    => (string) add_query_arg( [ VocabularyCatalog::PARAM => $slug ], $base ), /* tt-xview-ok — same view, the picker switching which vocabulary is open */
                'active' => $slug === $active,
            ];
        }

        RecordSpine::render( [
            'name' => __( 'Methodology vocabulary', 'talenttrack' ),
            'meta' => __( 'The academy\'s own words', 'talenttrack' ),
            'tabs' => $tabs,
        ] );

        // Navigating tabs are app-shell chrome and `RecordSpine` emits
        // nothing for them under `classic` (#2456's rollback contract). Here
        // that would leave eight of nine vocabularies with no way in, which
        // is broken rather than degraded — so `classic` gets the same
        // destinations as a plain list of links. Not a second affordance:
        // exactly one of the two ever renders.
        if ( \TT\Shared\Frontend\ShellPreference::isApp() ) {
            return;
        }

        echo '<ul class="tt-mv-picker">';
        foreach ( $tabs as $tab ) {
            $class = 'tt-mv-picker-item' . ( ! empty( $tab['active'] ) ? ' is-active' : '' );
            echo '<li class="' . esc_attr( $class ) . '">';
            echo '<a href="' . esc_url( (string) $tab['url'] ) . '"';
            if ( ! empty( $tab['active'] ) ) echo ' aria-current="page"';
            echo '>' . esc_html( (string) $tab['label'] ) . '</a>';
            echo '</li>';
        }
        echo '</ul>';
    }

    protected static function enqueueAssets(): void {
        wp_enqueue_style(
            'tt-frontend-methodology-vocabulary',
            TT_PLUGIN_URL . 'assets/css/frontend-methodology-vocabulary.css',
            [ 'tt-public' ],
            TT_VERSION
        );
        wp_enqueue_script(
            'tt-methodology-vocabulary',
            TT_PLUGIN_URL . 'assets/js/frontend-methodology-vocabulary.js',
            [],
            TT_VERSION,
            true
        );
    }

    /**
     * Hand the browser the open vocabulary's shape and the REST details.
     *
     * The client reads nothing but `window.TT_METHODOLOGY_VOCAB` (§4:
     * no stateful data smuggled through rendered HTML), and every string it
     * paints comes through `i18n` rather than being written in English in
     * the JS.
     *
     * @param array<string,mixed> $vocab
     */
    private static function localise( string $slug, array $vocab ): void {
        wp_localize_script( 'tt-methodology-vocabulary', 'TT_METHODOLOGY_VOCAB', [
            'rest_url'   => esc_url_raw( rest_url( 'talenttrack/v1/' ) ),
            'rest_nonce' => wp_create_nonce( 'wp_rest' ),
            'slug'       => $slug,
            'vocabulary' => $vocab,
            'locales'    => [
                'nl' => __( 'Dutch', 'talenttrack' ),
                'en' => __( 'English', 'talenttrack' ),
            ],
            'i18n' => [
                'loading'        => __( 'Loading…', 'talenttrack' ),
                'load_failed'    => __( 'Could not load this vocabulary. Reload the page to try again.', 'talenttrack' ),
                'save_failed'    => __( 'Could not save. Check the fields and try again.', 'talenttrack' ),
                'delete_failed'  => __( 'Could not delete this entry.', 'talenttrack' ),
                'empty'          => __( 'Nothing here yet.', 'talenttrack' ),
                'empty_singleton'=> __( 'This academy has no record here yet. It appears once the methodology is set up.', 'talenttrack' ),
                'add'            => __( 'Add entry', 'talenttrack' ),
                'edit'           => __( 'Edit', 'talenttrack' ),
                'delete'         => __( 'Delete', 'talenttrack' ),
                'save'           => __( 'Save', 'talenttrack' ),
                'cancel'         => __( 'Cancel', 'talenttrack' ),
                'saved'          => __( 'Saved.', 'talenttrack' ),
                'deleted'        => __( 'Deleted.', 'talenttrack' ),
                'confirm_delete' => __( 'Delete this entry? This cannot be undone.', 'talenttrack' ),
                'shipped'        => __( 'Shipped', 'talenttrack' ),
                'shipped_note'   => __( 'Shipped reference content is read-only. Clone it to edit.', 'talenttrack' ),
                'untitled'       => __( 'Untitled', 'talenttrack' ),
                'choose'         => __( '— choose —', 'talenttrack' ),
                'none'           => __( '— none —', 'talenttrack' ),
                'new_entry'      => __( 'New entry', 'talenttrack' ),
            ],
        ] );
    }
}
