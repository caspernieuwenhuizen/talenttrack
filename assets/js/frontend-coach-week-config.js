/**
 * Configuration > Dashboard, "This week panel" (#4379): keep the hidden
 * `coach_week.personas` field in step with the persona checkboxes, so the
 * shared config submit sends one JSON list.
 */
(function () {
    'use strict';

    function bind(panel) {
        var hidden = panel.querySelector('[data-tt-coach-week-json]');
        var boxes = panel.querySelectorAll('[data-tt-coach-week-persona]');
        if (!hidden || !boxes.length) return;

        function sync() {
            var chosen = [];
            Array.prototype.forEach.call(boxes, function (box) {
                if (box.checked) chosen.push(box.value);
            });
            hidden.value = JSON.stringify(chosen);
        }

        Array.prototype.forEach.call(boxes, function (box) {
            box.addEventListener('change', sync);
        });
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
