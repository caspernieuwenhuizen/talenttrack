/**
 * #4803 — "Reset to default" on the Appearance form's colours.
 *
 * Puts the colour's default back in its field and fires the field's input
 * and change events, so the form sees an edit. Saving then stores the colour
 * as unset, and it follows the design's own colour again.
 */
(function () {
    'use strict';

    document.addEventListener('click', function (event) {
        var button = event.target instanceof Element ? event.target.closest('[data-tt-color-reset]') : null;
        if (!button) return;

        var input = document.getElementById(button.getAttribute('data-tt-color-reset') || '');
        var value = button.getAttribute('data-tt-color-default') || '';
        if (!input || !value) return;

        event.preventDefault();
        input.value = value;
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
        input.focus();
    });
})();
