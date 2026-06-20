/*
 * frontend-seasons.js (#1481) — Seasons manager actions against the REST
 * contract (/wp-json/talenttrack/v1/seasons). Create + edit submit the
 * form; set-current and delete act per row. Vanilla, no dependencies.
 */
(function () {
    'use strict';

    var CFG = window.TT_SEASONS || {};
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

    // ── Create / edit ────────────────────────────────────────────
    var form = document.querySelector('[data-tt-season-form]');
    if (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var id = parseInt(form.getAttribute('data-tt-season-id') || '0', 10);
            var name = (form.querySelector('[name="name"]') || {}).value || '';
            var start = (form.querySelector('[name="start_date"]') || {}).value || '';
            var end = (form.querySelector('[name="end_date"]') || {}).value || '';

            if (start && end && end <= start) {
                showMsg(form, I18N.badRange || 'Invalid range', false);
                return;
            }

            var btn = form.querySelector('.tt-save-btn');
            if (btn) btn.setAttribute('data-state', 'saving');
            showMsg(form, I18N.saving || '…', true);

            var url = CFG.restUrl + (id > 0 ? '/' + id : '');
            var method = id > 0 ? 'PATCH' : 'POST';

            fetch(url, {
                method: method,
                credentials: 'same-origin',
                headers: headers(),
                body: JSON.stringify({ name: name, start_date: start, end_date: end })
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
    }

    // ── Set current / delete (list rows) ─────────────────────────
    document.addEventListener('click', function (e) {
        var setBtn = e.target.closest ? e.target.closest('[data-tt-season-current]') : null;
        var delBtn = e.target.closest ? e.target.closest('[data-tt-season-delete]') : null;

        if (setBtn) {
            var sid = setBtn.getAttribute('data-tt-season-current');
            if (!window.confirm(I18N.confirmCurrent || 'Set current?')) return;
            setBtn.disabled = true;
            fetch(CFG.restUrl + '/' + sid + '/current', {
                method: 'PATCH', credentials: 'same-origin', headers: headers()
            })
                .then(function (res) { return res.json().then(function (j) { return { ok: res.ok, json: j }; }); })
                .then(function (r) {
                    if (r.ok && r.json && r.json.success) { window.location.reload(); }
                    else { setBtn.disabled = false; window.alert(errorFrom(r.json)); }
                })
                .catch(function () { setBtn.disabled = false; window.alert(I18N.genericError || 'Error'); });
            return;
        }

        if (delBtn) {
            var did = delBtn.getAttribute('data-tt-season-delete');
            var dname = delBtn.getAttribute('data-tt-season-name') || '';
            var msg = (I18N.confirmDelete || 'Delete %s?').replace('%s', dname);
            if (!window.confirm(msg)) return;
            delBtn.disabled = true;
            fetch(CFG.restUrl + '/' + did, {
                method: 'DELETE', credentials: 'same-origin', headers: headers()
            })
                .then(function (res) { return res.json().then(function (j) { return { ok: res.ok, json: j }; }); })
                .then(function (r) {
                    if (r.ok && r.json && r.json.success) { window.location.reload(); }
                    else { delBtn.disabled = false; window.alert(errorFrom(r.json)); }
                })
                .catch(function () { delBtn.disabled = false; window.alert(I18N.genericError || 'Error'); });
        }
    });
})();
