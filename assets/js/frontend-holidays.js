/*
 * frontend-holidays.js (#1602) — holiday edit-form save against the REST
 * contract (/wp-json/talenttrack/v1/holidays/{id}). Submits the flat
 * edit form via PUT; the list itself (delete + row-link) is handled by
 * the shared FrontendListTable hydrator. Vanilla, no dependencies.
 */
(function () {
    'use strict';

    var CFG = window.TT_HOLIDAYS || {};
    var I18N = CFG.i18n || {};

    function headers() {
        return {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            'X-WP-Nonce': CFG.nonce || ''
        };
    }

    function errorFrom(json) {
        if (json && json.errors && json.errors.length && json.errors[0].message) {
            return json.errors[0].message;
        }
        return I18N.genericError || 'Error';
    }

    function showMsg(form, text, ok) {
        var box = form.querySelector('.tt-form-msg');
        if (!box) { if (!ok) window.alert(text); return; }
        box.textContent = text;
        box.style.color = ok ? '#1e6b3a' : '#b3261e';
    }

    var form = document.querySelector('[data-tt-holiday-form]');
    if (!form) return;

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var id = parseInt(form.getAttribute('data-tt-holiday-id') || '0', 10);
        if (id <= 0) return;

        var name = (form.querySelector('[name="name"]') || {}).value || '';
        var start = (form.querySelector('[name="start_date"]') || {}).value || '';
        var end = (form.querySelector('[name="end_date"]') || {}).value || '';
        var note = (form.querySelector('[name="note"]') || {}).value || '';

        if (start && end && end < start) {
            showMsg(form, I18N.badRange || 'Invalid range', false);
            return;
        }

        var btn = form.querySelector('.tt-save-btn');
        if (btn) btn.setAttribute('data-state', 'saving');
        showMsg(form, I18N.saving || '…', true);

        fetch(CFG.restUrl + '/' + id, {
            method: 'PUT',
            credentials: 'same-origin',
            headers: headers(),
            body: JSON.stringify({ name: name, start_date: start, end_date: end, note: note })
        })
            .then(function (res) { return res.json().then(function (j) { return { ok: res.ok, json: j }; }); })
            .then(function (r) {
                if (r.ok && r.json && r.json.success) {
                    window.location.href = CFG.listUrl;
                } else {
                    if (btn) btn.removeAttribute('data-state');
                    showMsg(form, errorFrom(r.json), false);
                }
            })
            .catch(function () {
                if (btn) btn.removeAttribute('data-state');
                showMsg(form, I18N.genericError || 'Error', false);
            });
    });
})();
