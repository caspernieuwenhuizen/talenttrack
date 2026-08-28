<?php
namespace TT\Shared\Frontend\Components;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * SaveState (#3004, epic #2881) — the "Saving… / All changes saved" line an
 * autosaving surface shows, and the loader for the component behind it.
 *
 * Whatever the save model, a surface has to say which one it is. A coach
 * should never have to guess whether their work is safe, and before this
 * they had to guess differently on each of the four autosaving surfaces:
 * the words differed, the placement differed, and two of them said nothing
 * at all until something went wrong.
 *
 * Rendering the markup here rather than in each view is what keeps the
 * vocabulary fixed. `TT.Autosave` owns the states; this owns where they
 * appear and what carries them.
 *
 * ## Use
 *
 *     SaveState::enqueue();
 *     SaveState::render();                 // in the surface's header
 *
 * then in the surface's JS:
 *
 *     var saver = TT.Autosave.create({
 *         stateEl: document.querySelector('[data-tt-save-state]'),
 *         nonce:   cfg.rest_nonce,
 *         serialise: …
 *     });
 *
 * The words come from `TT_Autosave.i18n`, localised once here, so a surface
 * does not pass its own copy of them and cannot drift.
 */
final class SaveState {

    /** @var bool */
    private static $enqueued = false;

    /**
     * Load the component. Idempotent — several surfaces on one page (a grid
     * inside a record, say) call it without coordinating.
     */
    public static function enqueue(): void {
        wp_enqueue_style(
            'tt-autosave',
            TT_PLUGIN_URL . 'assets/css/tt-autosave.css',
            [],
            TT_VERSION
        );

        wp_enqueue_script(
            'tt-autosave',
            TT_PLUGIN_URL . 'assets/js/tt-autosave.js',
            [],
            TT_VERSION,
            true
        );

        if ( self::$enqueued ) return;
        self::$enqueued = true;

        wp_localize_script( 'tt-autosave', 'TT_Autosave', [
            'i18n' => [
                // The four states, in one place. A surface that wants
                // different words is a surface arguing with the rule; the
                // answer is to change them here, for everyone.
                'dirty'  => __( 'Unsaved changes…', 'talenttrack' ),
                'saving' => __( 'Saving…', 'talenttrack' ),
                'saved'  => __( 'All changes saved', 'talenttrack' ),
                'error'  => __( 'Save failed — retry', 'talenttrack' ),
            ],
        ] );
    }

    /**
     * The status line itself.
     *
     * Renders in the saved state, because that is true of a surface nobody
     * has touched yet. `TT.Autosave` takes it over on first change.
     *
     * @param string $extra_class Optional surface-specific hook. Positioning
     *                            belongs to the surface; appearance does not.
     */
    public static function render( string $extra_class = '' ): void {
        $classes = 'tt-save-state is-saved';
        if ( $extra_class !== '' ) $classes .= ' ' . $extra_class;
        ?>
        <span class="<?php echo esc_attr( $classes ); ?>"
              data-tt-save-state
              role="status"
              aria-live="polite"><?php esc_html_e( 'All changes saved', 'talenttrack' ); ?></span>
        <?php
    }
}
