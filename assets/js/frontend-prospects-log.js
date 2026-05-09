/*
 * frontend-prospects-log.js (#0081) — click handler for the "+ New
 * prospect" entry-point button.
 *
 * The button (`[data-tt-prospect-log]`) is rendered on the standalone
 * onboarding-pipeline view. POSTs to /talenttrack/v1/prospects/log,
 * which dispatches the LogProspect chain and returns a redirect_url
 * to the new task's form.
 */
(function () {
    'use strict';

    var cfg = window.TT_PROSPECT_LOG || null;
    if (!cfg || !cfg.rest_url || !cfg.nonce) return;

    var btns = document.querySelectorAll('[data-tt-prospect-log]');
    Array.prototype.forEach.call(btns, function (btn) {
        btn.addEventListener('click', function (ev) {
            ev.preventDefault();
            if (btn.disabled) return;
            btn.disabled = true;

            fetch(cfg.rest_url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'X-WP-Nonce': cfg.nonce
                }
            }).then(function (res) {
                return res.json().then(function (body) {
                    return { status: res.status, body: body };
                });
            }).then(function (r) {
                var data = (r.body && r.body.data) || null;
                if (r.body && r.body.success && data && data.redirect_url) {
                    window.location.assign(data.redirect_url);
                    return;
                }
                btn.disabled = false;
                var msg = (cfg.i18n && cfg.i18n.error) || 'Could not start the prospect-logging flow.';
                if (r.body && r.body.errors && r.body.errors[0] && r.body.errors[0].message) {
                    msg = r.body.errors[0].message;
                }
                window.alert(msg);
            }).catch(function () {
                btn.disabled = false;
                var msg = (cfg.i18n && cfg.i18n.network) || 'Network error. Please try again.';
                window.alert(msg);
            });
        });
    });
})();
