<?php
namespace TT\Shared\Frontend\Components;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * FormAutosave (#3008, epic #2881) — turn an ordinary REST-backed form into
 * an autosaving one.
 *
 * The epic's instruction was one shared behaviour rather than five
 * implementations. `TT.Autosave` is that behaviour; this is the two lines a
 * view needs to reach it, so that four surfaces do not each grow their own
 * bootstrap script.
 *
 * ## Use
 *
 *     FormAutosave::enqueue();
 *     printf( '<form class="tt-autosave-form" %s>', FormAutosave::formAttrs( 'evaluations/12', 'PUT', 'evaluation:12' ) );
 *         …fields…
 *         <div class="tt-form-actions">
 *             <?php SaveState::render(); ?>
 *         </div>
 *     </form>
 *
 * `assets/js/tt-form-autosave.js` finds every `[data-tt-autosave-form]` on
 * the page and wires it. There is no per-surface JS.
 *
 * ## What this is not for
 *
 * **Creating a record.** Every surface wired to this edits something that
 * already exists. Autosaving a create would spawn a row on the first
 * keystroke and leave empty evaluations behind every coach who opened the
 * form and thought better of it. Creation stays a wizard or a Save button.
 *
 * **A deliberate commit.** A sign-off, a publish, a submit: those belong
 * outside the autosaving form, in their own confirmed action. A checkbox
 * that locks a player's record forever must not fire because a debounce
 * elapsed.
 *
 * **A short record form.** Player, team, person, configuration, lookups —
 * a small set of known fields where Save is a useful pause, not an
 * obstacle. Those keep their Save button.
 */
final class FormAutosave {

    /**
     * Load the component. Idempotent — `wp_enqueue_*` deduplicates, and
     * several autosaving forms on one page is a normal case.
     */
    public static function enqueue(): void {
        SaveState::enqueue();

        wp_enqueue_script(
            'tt-form-autosave',
            TT_PLUGIN_URL . 'assets/js/tt-form-autosave.js',
            // `tt-public` for `TT.formToJSON` — the same bracket expansion
            // the submit handler uses, so an autosave and a submit cannot
            // reach one endpoint with two different shapes.
            [ 'tt-public', 'tt-autosave' ],
            TT_VERSION,
            true
        );
    }

    /**
     * The attributes that mark a form as autosaving, escaped.
     *
     * Emitted from here rather than typed into each view: a view that
     * misspells `data-tt-autosave-form` gets a form that silently does not
     * save, which is the one failure mode this whole epic exists to
     * prevent.
     *
     * @param string $rest_path REST path relative to the namespace root,
     *                          e.g. `evaluations/12`.
     * @param string $method    `PUT` or `PATCH`. Never `POST` — see the
     *                          class docblock on creation.
     * @param string $key       Surface + record, for the revert snapshot
     *                          (#3006). Empty means no revert is offered.
     */
    public static function formAttrs( string $rest_path, string $method = 'PUT', string $key = '' ): string {
        $attrs = sprintf(
            'data-tt-autosave-form data-rest-path="%s" data-rest-method="%s"',
            esc_attr( $rest_path ),
            esc_attr( strtoupper( $method ) )
        );

        if ( $key !== '' ) {
            $attrs .= sprintf( ' data-autosave-key="%s"', esc_attr( $key ) );
        }

        return $attrs;
    }
}
