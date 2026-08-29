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
 *
 * ## What travels with the line
 *
 * Two controls, both hidden until they apply, both rendered here rather than
 * per surface so a coach finds the way out of a mistake in the same place on
 * every screen that saves as they work:
 *
 *   - **Undo** (#3005) — the last committed change.
 *   - **Revert changes** (#3006) — back to how the record was when this
 *     sitting opened. Needs `storageKey` on the saver; without it the
 *     component has nowhere to keep the snapshot and never offers it.
 */
final class SaveState {

    /** @var bool */
    private static $enqueued = false;

    /**
     * Load the component. Idempotent — several surfaces on one page (a grid
     * inside a record, say) call it without coordinating.
     */
    public static function enqueue(): void {
        // Depends on `tt-public` for the `.tt-modal` rules the revert
        // confirm (#3006) reuses. Every autosaving surface is a dashboard
        // view, so this is already loaded — declaring it means the component
        // stops relying on that being true.
        wp_enqueue_style(
            'tt-autosave',
            TT_PLUGIN_URL . 'assets/css/tt-autosave.css',
            [ 'tt-public' ],
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
            // #3006 — the sitting snapshot is keyed per user as well as per
            // record. Academy laptops get shared; a coach must never be
            // offered "how it was when you opened this" for a sitting that
            // was somebody else's.
            'user' => (string) get_current_user_id(),

            'i18n' => [
                // The four states, in one place. A surface that wants
                // different words is a surface arguing with the rule; the
                // answer is to change them here, for everyone.
                'dirty'  => __( 'Unsaved changes…', 'talenttrack' ),
                'saving' => __( 'Saving…', 'talenttrack' ),
                'saved'  => __( 'All changes saved', 'talenttrack' ),
                'error'  => __( 'Save failed — retry', 'talenttrack' ),

                // #3005 — a failed undo is worth its own sentence. The
                // record is unchanged, so telling a coach to retry a save
                // would point them at the wrong thing.
                'undoError' => __( 'Undo failed — nothing changed', 'talenttrack' ),

                // #3006 — the revert confirm. Two count strings rather than
                // one plural form because the count is decided in the
                // browser: WordPress cannot pick the form for a number it
                // never sees, and a single "%d field(s)" reads as a bug.
                'revert'       => __( 'Revert changes', 'talenttrack' ),
                'revertBody'   => __( 'Restore this record to how it was when you opened it? This cannot be undone.', 'talenttrack' ),
                'revertOne'    => __( '1 field will be restored.', 'talenttrack' ),
                'revertMany'   => __( '%d fields will be restored.', 'talenttrack' ),
                'revertCancel' => __( 'Cancel', 'talenttrack' ),
                'revertError'  => __( 'Revert failed — nothing changed', 'talenttrack' ),
            ],
        ] );
    }

    /**
     * The status line itself, and the undo control beside it.
     *
     * Renders in the saved state, because that is true of a surface nobody
     * has touched yet. `TT.Autosave` takes it over on first change.
     *
     * The Undo button ships hidden and stays hidden until there is a
     * committed change to take back — see `TT.Autosave.canUndo()`. It is
     * rendered here rather than by each surface for the same reason the
     * words are: a coach should find the way out of a mis-tap in the same
     * place on every autosaving screen.
     *
     * @param string $extra_class Optional surface-specific hook. Positioning
     *                            belongs to the surface; appearance does not.
     */
    public static function render( string $extra_class = '' ): void {
        $classes = 'tt-save-state is-saved';
        if ( $extra_class !== '' ) $classes .= ' ' . $extra_class;
        ?>
        <span class="tt-save-state-group">
            <span class="<?php echo esc_attr( $classes ); ?>"
                  data-tt-save-state
                  role="status"
                  aria-live="polite"><?php esc_html_e( 'All changes saved', 'talenttrack' ); ?></span>
            <button type="button"
                    class="tt-save-undo"
                    data-tt-save-undo
                    hidden><?php
                    // Plain `__()` on purpose: "Undo" is already a msgid in
                    // this plugin, already correct in Dutch, and already on
                    // five other buttons. A context here would fork it into a
                    // second string that can only drift from those.
                    esc_html_e( 'Undo', 'talenttrack' );
            ?></button>
            <?php
            // #3006 — the second range of undo, beside the first. Also
            // hidden until it applies: it appears only when the record is
            // settled, the sitting snapshot exists, and something actually
            // differs from it. "Revert changes" rather than a bare "Revert"
            // because it stands next to "Undo" and the pair has to be
            // tellable apart at a glance.
            ?>
            <button type="button"
                    class="tt-save-revert"
                    data-tt-save-revert
                    hidden><?php esc_html_e( 'Revert changes', 'talenttrack' ); ?></button>
        </span>
        <?php
    }
}
