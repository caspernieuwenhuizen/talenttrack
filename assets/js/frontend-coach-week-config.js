/**
 * Configuration > Dashboard, "This week panel" (#4492): one persona's
 * settings at a time. The persona pills switch which persona the controls
 * show; every change is written back into the hidden `coach_week.config`
 * field, which the shared config submit sends as one JSON value. Reset
 * puts the shown persona back on its defaults (TT_CoachWeekConfig).
 */
(function () {
    'use strict';

    function clone(value) {
        return JSON.parse(JSON.stringify(value));
    }

    function bind(panel) {
        var hidden = panel.querySelector('[data-tt-coach-week-json]');
        if (!hidden) return;

        var state;
        try {
            state = JSON.parse(hidden.value || '{}');
        } catch (e) {
            state = {};
        }
        var defaults = (window.TT_CoachWeekConfig && window.TT_CoachWeekConfig.defaults) || {};
        var pills = panel.querySelectorAll('[data-tt-coach-week-persona]');
        var fields = panel.querySelectorAll('[data-tt-coach-week-field]');
        var blocks = panel.querySelectorAll('[data-tt-coach-week-block]');
        var reset = panel.querySelector('[data-tt-coach-week-reset]');

        function current() {
            var chosen = panel.querySelector('[data-tt-coach-week-persona]:checked');
            return chosen ? chosen.value : '';
        }

        function settingsOf(persona) {
            if (!state[persona]) state[persona] = clone(defaults[persona] || {});
            var s = state[persona];
            if (!Array.isArray(s.desktop)) s.desktop = [];
            if (!Array.isArray(s.phone)) s.phone = [];
            return s;
        }

        function markPills() {
            Array.prototype.forEach.call(pills, function (pill) {
                var label = pill.closest('[data-tt-coach-week-pill]');
                if (label) label.classList.toggle('is-off', !(state[pill.value] && state[pill.value].on));
            });
        }

        function load() {
            var s = settingsOf(current());
            Array.prototype.forEach.call(fields, function (field) {
                var name = field.getAttribute('data-tt-coach-week-field');
                if (field.type === 'checkbox') {
                    field.checked = !!s[name];
                } else if (s[name] !== undefined) {
                    field.value = s[name];
                }
            });
            Array.prototype.forEach.call(blocks, function (box) {
                var list = s[box.getAttribute('data-tt-coach-week-device')] || [];
                box.checked = list.indexOf(box.getAttribute('data-tt-coach-week-block')) !== -1;
            });
            markPills();
        }

        function save() {
            var s = settingsOf(current());
            Array.prototype.forEach.call(fields, function (field) {
                var name = field.getAttribute('data-tt-coach-week-field');
                s[name] = field.type === 'checkbox' ? field.checked : field.value;
            });
            s.desktop = [];
            s.phone = [];
            Array.prototype.forEach.call(blocks, function (box) {
                if (box.checked) s[box.getAttribute('data-tt-coach-week-device')].push(box.getAttribute('data-tt-coach-week-block'));
            });
            hidden.value = JSON.stringify(state);
            markPills();
        }

        Array.prototype.forEach.call(pills, function (pill) {
            pill.addEventListener('change', load);
        });
        Array.prototype.forEach.call(fields, function (field) {
            field.addEventListener('change', save);
        });
        Array.prototype.forEach.call(blocks, function (box) {
            box.addEventListener('change', save);
        });
        if (reset) {
            reset.addEventListener('click', function () {
                var persona = current();
                if (!defaults[persona]) return;
                state[persona] = clone(defaults[persona]);
                hidden.value = JSON.stringify(state);
                load();
            });
        }

        load();
    }

    function init() {
        Array.prototype.forEach.call(document.querySelectorAll('[data-tt-coach-week-config]'), bind);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
