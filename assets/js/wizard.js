/**
 * Wizard chrome runtime: the forward-button loading guard and the mobile
 * step-rail toggle. Enqueued with the wizard view; strings come from
 * `TT.i18n` (CLAUDE.md §4).
 *
 * The guard: when the step is submitted forward (the visible Next, or the
 * hidden default button Enter goes through), Next shows its loading label
 * and both forward buttons are disabled, so a second Enter on the last step
 * cannot post Create twice. It runs a tick after the submit event, so a
 * required-field check that cancels the post (wizard-validation.js) leaves
 * the step usable, and the post's entries are already built by then.
 */
( function () {
    'use strict';

    var i18n = ( window.TT && window.TT.i18n ) || {};

    var form = document.querySelector( '.tt-wizard-form' );
    if ( form ) {
        var next = form.querySelector( '[data-tt-wizard-next]' );
        var fallback = form.querySelector( '[data-tt-wizard-default]' );
        if ( next ) {
            form.addEventListener( 'submit', function ( e ) {
                var submitter = e.submitter || document.activeElement;
                if ( ! submitter ) return;
                if ( submitter !== next && ! submitter.hasAttribute( 'data-tt-wizard-default' ) ) return;
                setTimeout( function () {
                    if ( e.defaultPrevented ) return;
                    var label = next.getAttribute( 'data-loading-label' ) || i18n.wizard_loading || '';
                    next.disabled = true;
                    if ( label ) next.textContent = label;
                    if ( fallback ) fallback.disabled = true;
                }, 0 );
            } );
        }
    }

    var layout = document.querySelector( '.tt-wizard-layout' );
    var toggle = layout && layout.querySelector( '[data-tt-wizard-rail-toggle]' );
    if ( layout && toggle ) {
        toggle.addEventListener( 'click', function () {
            var open = layout.getAttribute( 'data-rail-open' ) === 'true';
            layout.setAttribute( 'data-rail-open', open ? 'false' : 'true' );
            toggle.setAttribute( 'aria-expanded', open ? 'false' : 'true' );
        } );
    }
}() );
