/*
 * Test results — export button (#4194).
 *
 * The list's filters live in the URL and change without a page load (the
 * shared list table keeps the querystring in step), so the export form
 * reads them when the button is pressed instead of carrying the values the
 * page was first rendered with. With no test chosen it does not submit; it
 * says so and moves focus to the Test filter.
 */
(function () {
    'use strict';

    var i18n = window.ttTestResultsI18n || {};

    // The filter bar renders its controls twice (inline row and phone
    // sheet); the visible one is the one the reader set.
    function control(key) {
        var els = document.querySelectorAll('[name="filter[' + key + ']"]');
        for (var i = 0; i < els.length; i++) {
            if (els[i].offsetParent !== null) return els[i];
        }
        return els[0] || null;
    }

    function current(params, key) {
        var el = control(key);
        if (el && el.value) return el.value;
        return params.get('filter[' + key + ']') || '';
    }

    function setField(form, name, value) {
        var input = form.querySelector('input[name="' + name + '"]');
        if (input) input.value = value;
    }

    function say(form, text) {
        var note = form.querySelector('.tt-tr-export__note');
        if (!note) {
            note = document.createElement('p');
            note.className = 'tt-tr-export__note';
            note.setAttribute('role', 'status');
            form.appendChild(note);
        }
        note.textContent = text;
    }

    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form || !form.matches || !form.matches('form[data-tt-tr-export]')) return;

        var params = new URLSearchParams(window.location.search);
        var definition = current(params, 'definition_id');
        if (definition === '') {
            e.preventDefault();
            say(form, i18n.chooseTest || '');
            var select = control('definition_id');
            if (select && select.offsetParent !== null) select.focus();
            return;
        }
        say(form, '');
        setField(form, 'definition_id', definition);
        setField(form, 'team_id', current(params, 'team_id'));
        setField(form, 'date_from', current(params, 'date_from'));
        setField(form, 'date_to', current(params, 'date_to'));
        setField(form, 'tt_export_return_url', window.location.href);
    });
})();
