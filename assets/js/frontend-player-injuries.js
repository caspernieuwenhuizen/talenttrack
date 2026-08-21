/**
 * #2609 — record a player's return to play from the injuries tab.
 *
 * Setting `actual_return` is what closes an injury: the repository emits
 * the `injury_ended` journey event and the squad overview stops counting
 * the player as out. It is one field, so it asks for one field rather
 * than routing through an edit form.
 *
 * Vanilla JS + fetch against the existing PUT /player-injuries/{id}.
 */
(function () {
    'use strict';

    if (typeof window.TTInjuries === 'undefined') return;

    var cfg = window.TTInjuries;
    var ISO = /^\d{4}-\d{2}-\d{2}$/;

    document.addEventListener('click', function (ev) {
        var btn = ev.target.closest('[data-tt-injury-recover]');
        if (!btn) return;

        ev.preventDefault();

        var id = parseInt(btn.getAttribute('data-tt-injury-recover'), 10);
        if (!id) return;

        var today = new Date().toISOString().slice(0, 10);
        var value = window.prompt(cfg.i18n.prompt, today);
        if (value === null) return;

        value = value.trim();
        if (!ISO.test(value)) {
            window.alert(cfg.i18n.badDate);
            return;
        }

        btn.disabled = true;

        fetch(cfg.restBase + id, {
            method: 'PUT',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': cfg.nonce
            },
            body: JSON.stringify({ actual_return: value })
        })
            .then(function (res) {
                if (!res.ok) throw new Error('http_' + res.status);
                window.location.reload();
            })
            .catch(function () {
                btn.disabled = false;
                window.alert(cfg.i18n.failed);
            });
    });
})();
