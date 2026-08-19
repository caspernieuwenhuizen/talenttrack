/**
 * Exercise promotion (#2495, epic #2493 D9).
 *
 * The head of development's queue lists team-scoped drills a coach wrote.
 * Pressing the button makes one club-wide through the REST route and
 * removes the row.
 *
 * Deliberately small and self-contained. Without JS the button does
 * nothing and the queue is still readable — which is acceptable here
 * because the exercise is already usable by the team that wrote it.
 * Promotion only widens who else gets it.
 */
(function () {
    'use strict';

    var cfg = window.TTExercisePromote;
    if (!cfg || !cfg.root) return;

    function promote(button) {
        var id = button.getAttribute('data-exercise-id');
        if (!id) return;

        var original = button.textContent;
        button.disabled = true;
        button.textContent = cfg.i18n.busy;

        fetch(cfg.root + encodeURIComponent(id) + '/promote', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'X-WP-Nonce': cfg.nonce }
        })
            .then(function (res) { return res.json().then(function (body) { return { ok: res.ok, body: body }; }); })
            .then(function (result) {
                if (!result.ok || !result.body || result.body.success !== true) {
                    throw new Error('promote failed');
                }
                var row = button.closest('.tt-ex-queue__item');
                if (row && row.parentNode) row.parentNode.removeChild(row);

                // Last one out closes the whole section, so an empty queue
                // never sits there as a heading with nothing under it.
                var list = document.querySelector('.tt-ex-queue__list');
                if (list && !list.querySelector('.tt-ex-queue__item')) {
                    var section = document.querySelector('.tt-ex-queue');
                    if (section && section.parentNode) section.parentNode.removeChild(section);
                }
            })
            .catch(function () {
                button.disabled = false;
                button.textContent = original;
                window.alert(cfg.i18n.failed);
            });
    }

    document.addEventListener('click', function (event) {
        var button = event.target.closest ? event.target.closest('.tt-ex-queue__promote') : null;
        if (!button) return;
        event.preventDefault();
        promote(button);
    });
}());
