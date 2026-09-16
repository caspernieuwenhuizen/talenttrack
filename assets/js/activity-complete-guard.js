/*
 * activity-complete-guard.js (#3446, epic #3442)
 *
 * One deliberate tap between "this activity has no register" and
 * "this activity is completed".
 *
 * Five paths reach `activity_status_key = 'completed'`. The two that
 * commit through a form submit are handled here; the one that commits
 * through the REST action plumbing rides the dialog in
 * frontend-archive-button.js, and the non-interactive ones (Excel and
 * tournament import) raise an alert instead, because there is nobody
 * there to answer a dialog.
 *
 * Whether the register is empty is decided SERVER-side — the predicate is
 * `ActivityRegisterProgress`, and the markup only carries its answer. A
 * button guards its form when it declares:
 *
 *   data-tt-empty-register-guard             — arms the guard
 *   data-tt-empty-register-title="…"         — dialog title
 *   data-tt-empty-register-message="…"       — dialog body
 *   data-tt-empty-register-confirm="…"       — the "do it anyway" label
 *   data-tt-empty-register-cancel="…"        — the "don't" label
 *   data-tt-empty-register-alt-label="…"     — optional remedy button…
 *   data-tt-empty-register-alt-href="…"      — …needs both to render
 *
 * Escape, the backdrop and Cancel all mean "don't complete" — nothing is
 * submitted and the coach stays where they were.
 */
(function () {
    'use strict';

    function attr( el, name ) {
        return el.getAttribute( 'data-tt-empty-register-' + name ) || '';
    }

    /**
     * Submit the form the way the browser would have, crediting the
     * button that was clicked so its name/value (the wizard's
     * `_rate_choice`, wp-admin's submit) still reaches the server and its
     * `formnovalidate` is still honoured.
     */
    function submitVia( form, trigger ) {
        if ( typeof form.requestSubmit === 'function' ) {
            form.requestSubmit( trigger );
            return;
        }
        var name = trigger.getAttribute( 'name' );
        if ( name ) {
            var hidden = document.createElement( 'input' );
            hidden.type  = 'hidden';
            hidden.name  = name;
            hidden.value = trigger.getAttribute( 'value' ) || '';
            form.appendChild( hidden );
        }
        form.submit();
    }

    document.addEventListener( 'click', function ( e ) {
        var trig = e.target && e.target.closest
            ? e.target.closest( '[data-tt-empty-register-guard]' )
            : null;
        if ( ! trig ) return;

        var form = trig.closest( 'form' );
        if ( ! form ) return;

        e.preventDefault();

        var opts = {
            title:        attr( trig, 'title' ),
            message:      attr( trig, 'message' ),
            confirmLabel: attr( trig, 'confirm' ),
            cancelLabel:  attr( trig, 'cancel' ),
            altLabel:     attr( trig, 'alt-label' ),
            altHref:      attr( trig, 'alt-href' ),
            // Open on Cancel: this dialog warns, and the override should
            // take a deliberate tap rather than a stray Enter.
            focus:        'cancel'
        };

        if ( typeof window.ttConfirm !== 'function' ) {
            // No dialog available: still ask, rather than silently
            // completing the thing the guard exists to question.
            if ( window.confirm( opts.message ) ) submitVia( form, trig );
            return;
        }

        window.ttConfirm( opts ).then( function ( ok ) {
            if ( ok ) submitVia( form, trig );
        } );
    } );
})();
