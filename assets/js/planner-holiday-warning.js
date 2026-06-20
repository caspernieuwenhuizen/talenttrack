/*
 * planner-holiday-warning.js (#1480) — soft warning when a coach
 * schedules an activity on an academy holiday from the team planner.
 *
 * Holiday days carry `data-tt-holiday-name` on the day cell. Clicking
 * the day's "+ Add" link confirms first (soft — Cancel stops, OK
 * proceeds). Never blocks: scheduling on a holiday is allowed.
 *
 * Vanilla JS, document-delegated, no dependencies.
 */
(function () {
    'use strict';

    var STR = (window.TT_HOLIDAY && window.TT_HOLIDAY.warning)
        ? window.TT_HOLIDAY.warning
        : 'This day is an academy holiday (%s). Schedule an activity anyway?';

    document.addEventListener('click', function (e) {
        var link = e.target.closest ? e.target.closest('a.tt-planner-empty') : null;
        if (!link) return;
        var dayCell = link.closest('.tt-planner-day-holiday');
        if (!dayCell) return;
        var name = dayCell.getAttribute('data-tt-holiday-name') || '';
        var msg = STR.replace('%s', name);
        if (!window.confirm(msg)) {
            e.preventDefault();
        }
    });
})();
