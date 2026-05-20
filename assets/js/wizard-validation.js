/**
 * Wizard live validation runtime (#796).
 *
 * Drops in alongside the existing wizard form. For every `[required]`
 * input/select/textarea inside `.tt-wizard-form`, it watches input + blur
 * events, marks the field invalid (visual + aria) when empty, surfaces an
 * inline error message under the field after the user has touched it, and
 * disables the Next button until every required field on the current step
 * is filled.
 *
 * The wizard form already has `novalidate` (v3.110.137 fix to stop the
 * browser-native popup from jumping focus into hidden inputs), so this
 * JS is the user-facing layer — but the existing server-side validate()
 * in every step's class still runs on Next-click as the authoritative
 * check.
 *
 * Surfaces error strings via `TT_WizardValidation.i18n.*` (localised at
 * enqueue time). No fetch / no REST — purely client-side.
 */
( function () {
    'use strict';

    var i18n = ( window.TT_WizardValidation && window.TT_WizardValidation.i18n ) || {};
    var I_REQUIRED = i18n.required || 'This field is required.';

    var form = document.querySelector( '.tt-wizard-form' );
    if ( ! form ) return;

    var nextBtn = form.querySelector( 'button[data-tt-wizard-next]' );

    // Required inputs at form scope. Note: the wizard renders one step
    // at a time, so the "current step" is whatever's in the DOM right now.
    function requiredInputs() {
        return Array.prototype.slice.call(
            form.querySelectorAll( '[required]' )
        ).filter( function ( el ) {
            // Skip required inputs that are inside a step the framework
            // has marked hidden (e.g. notApplicableFor skipped steps).
            return el.offsetParent !== null || el.type === 'hidden';
        } );
    }

    function isEmpty( el ) {
        if ( el.type === 'checkbox' || el.type === 'radio' ) {
            // For radios: check if any in the group is checked.
            if ( el.type === 'radio' && el.name ) {
                var checked = form.querySelector(
                    'input[type="radio"][name="' + el.name + '"]:checked'
                );
                return ! checked;
            }
            return ! el.checked;
        }
        return ! el.value || el.value.trim() === '' || el.value === '0';
    }

    function setFieldError( el, hasError ) {
        var fieldWrap = el.closest( 'label' ) || el.parentNode;
        if ( ! fieldWrap ) return;
        var msg = fieldWrap.querySelector( '.tt-wizard-error-msg' );
        if ( hasError ) {
            el.classList.add( 'tt-input-invalid' );
            el.setAttribute( 'aria-invalid', 'true' );
            if ( ! msg ) {
                msg = document.createElement( 'span' );
                msg.className = 'tt-wizard-error-msg';
                msg.setAttribute( 'role', 'alert' );
                msg.textContent = I_REQUIRED;
                fieldWrap.appendChild( msg );
            }
        } else {
            el.classList.remove( 'tt-input-invalid' );
            el.removeAttribute( 'aria-invalid' );
            if ( msg ) msg.remove();
        }
    }

    function refreshNextButton() {
        if ( ! nextBtn ) return;
        var anyInvalid = requiredInputs().some( isEmpty );
        nextBtn.disabled = anyInvalid;
        nextBtn.setAttribute( 'aria-disabled', anyInvalid ? 'true' : 'false' );
        nextBtn.title = anyInvalid ? ( i18n.next_blocked || 'Fill in every required field to continue.' ) : '';
    }

    function handleEvent( e ) {
        var el = e.target;
        if ( ! el || ! el.matches ) return;
        if ( ! el.matches( '[required]' ) ) return;
        // Touched flag: only show the inline error once the user has
        // engaged with the field (so empty required fields don't all
        // light up red the moment the step renders).
        if ( e.type === 'blur' || e.type === 'change' ) {
            el.setAttribute( 'data-tt-touched', '1' );
        }
        var touched = el.getAttribute( 'data-tt-touched' ) === '1';
        var empty = isEmpty( el );
        setFieldError( el, touched && empty );
        refreshNextButton();
    }

    // The wizard form re-renders one step at a time per server round-trip;
    // events bound at the form level survive each step's render because
    // the form element itself isn't replaced.
    form.addEventListener( 'input', handleEvent );
    form.addEventListener( 'blur', handleEvent, true ); // capture so it works on radios
    form.addEventListener( 'change', handleEvent );

    // Final guard on submit: mark every untouched required field as
    // touched + render its error. The server still validates and the
    // existing notice path still surfaces server errors, but this
    // ensures users who tab past required fields and click Next see
    // the per-field errors inline.
    form.addEventListener( 'submit', function ( e ) {
        var submitter = e.submitter || nextBtn;
        // Only enforce when the user is trying to advance — Cancel /
        // Back / Save-as-draft all carry formnovalidate and shouldn't
        // be blocked.
        if ( ! submitter || submitter.getAttribute( 'name' ) !== 'tt_wizard_action' ) return;
        if ( submitter.value !== 'next' ) return;

        var firstInvalid = null;
        requiredInputs().forEach( function ( el ) {
            el.setAttribute( 'data-tt-touched', '1' );
            var empty = isEmpty( el );
            setFieldError( el, empty );
            if ( empty && ! firstInvalid ) firstInvalid = el;
        } );
        if ( firstInvalid ) {
            e.preventDefault();
            firstInvalid.focus();
            refreshNextButton();
        }
    } );

    // Initial paint — disable Next if step started empty.
    refreshNextButton();
}() );
