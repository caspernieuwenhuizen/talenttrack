/*
 * frontend-strava-admin.js (#2127, epic #2002) — Strava operator console.
 *
 * Drives FrontendStravaAdminView against the talenttrack/v1 REST surface:
 *   POST   /strava/app                   — save app Client ID + secret
 *   GET    /strava/webhook/subscription  — subscription status (first paint)
 *   POST   /strava/webhook/subscription  — create / re-verify subscription
 *   DELETE /strava/webhook/subscription  — delete subscription
 *   GET    /strava/connections           — connected-players roster
 *
 * Compose-only: the view ships the static shell + first-paint config flags;
 * this script mutates state and fills the roster. Vanilla JS, no jQuery; the
 * secret is sent on save but never read back into the DOM. Strings come from
 * the localised TT_StravaAdmin object — no hard-coded English.
 */
(function () {
    'use strict';

    var root = document.querySelector('[data-tt-strava-admin]');
    if (!root) return;

    var cfg = window.TT_StravaAdmin || {};
    var i18n = cfg.i18n || {};
    var rest = (cfg.rest_url || '/wp-json/talenttrack/v1/').replace(/\/+$/, '/');
    var nonce = cfg.rest_nonce || (window.TT && window.TT.rest_nonce) || '';

    var msg = root.querySelector('[data-tt-strava-admin-msg]');
    var subState = root.querySelector('[data-tt-strava-admin-sub-state]');
    var unsubBtn = root.querySelector('[data-tt-strava-admin-unsubscribe]');

    function headers() {
        var h = { Accept: 'application/json', 'Content-Type': 'application/json' };
        if (nonce) h['X-WP-Nonce'] = nonce;
        return h;
    }

    function firstError(json) {
        return (json && json.errors && json.errors[0] && json.errors[0].message) || '';
    }

    function setMsg(text, kind) {
        if (!msg) return;
        msg.className = 'tt-strava-admin__msg' + (kind ? ' tt-' + kind : '');
        msg.textContent = text || '';
    }

    function call(path, method, body) {
        return fetch(rest + path, {
            method: method || 'GET',
            credentials: 'same-origin',
            headers: headers(),
            body: body ? JSON.stringify(body) : undefined
        }).then(function (res) {
            return res.json().then(function (json) { return { ok: res.ok, json: json }; });
        });
    }

    function badge(kind, label) {
        return '<span class="tt-strava-admin__badge tt-strava-admin__badge--' + kind + '">' + label + '</span>';
    }

    function setSubState(active) {
        if (subState) {
            subState.innerHTML = active
                ? badge('ok', i18n.active || 'Active')
                : badge('muted', i18n.not_created || 'Not created');
        }
        if (unsubBtn) unsubBtn.hidden = !active;
    }

    // ---- Save app credentials ------------------------------------------
    var appForm = root.querySelector('[data-tt-strava-admin-app-form]');
    if (appForm) {
        appForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var btn = appForm.querySelector('.tt-save-btn');
            if (btn) btn.setAttribute('data-state', 'saving');
            setMsg('', '');

            var fd = new FormData(appForm);
            call('strava/app', 'POST', {
                client_id: String(fd.get('client_id') || ''),
                client_secret: String(fd.get('client_secret') || '')
            }).then(function (r) {
                if (r.ok && r.json && r.json.success) {
                    if (btn) btn.setAttribute('data-state', 'saved');
                    setMsg(i18n.saved || 'Saved.', 'success');
                    var secret = appForm.querySelector('[name="client_secret"]');
                    if (secret) secret.value = '';
                } else {
                    if (btn) btn.setAttribute('data-state', 'error');
                    setMsg(firstError(r.json) || i18n.error || 'Error.', 'error');
                    setTimeout(function () { if (btn) btn.setAttribute('data-state', 'idle'); }, 2500);
                }
            }).catch(function () {
                if (btn) btn.setAttribute('data-state', 'error');
                setMsg(i18n.network_error || 'Network error.', 'error');
                setTimeout(function () { if (btn) btn.setAttribute('data-state', 'idle'); }, 2500);
            });
        });
    }

    // ---- Create / re-verify subscription -------------------------------
    var subBtn = root.querySelector('[data-tt-strava-admin-subscribe]');
    if (subBtn) {
        subBtn.addEventListener('click', function () {
            subBtn.disabled = true;
            setMsg(i18n.sub_creating || 'Creating subscription…', '');
            call('strava/webhook/subscription', 'POST', {}).then(function (r) {
                subBtn.disabled = false;
                if (r.ok && r.json && r.json.success && r.json.data && r.json.data.subscribed) {
                    setSubState(true);
                    setMsg(i18n.sub_created || 'Subscription active.', 'success');
                } else {
                    setMsg(firstError(r.json) || i18n.sub_failed || 'Could not create the subscription.', 'error');
                }
            }).catch(function () {
                subBtn.disabled = false;
                setMsg(i18n.network_error || 'Network error.', 'error');
            });
        });
    }

    // ---- Delete subscription -------------------------------------------
    if (unsubBtn) {
        unsubBtn.addEventListener('click', function () {
            if (!window.confirm(i18n.confirm_unsub || 'Delete the Strava webhook subscription?')) return;
            unsubBtn.disabled = true;
            setMsg(i18n.sub_deleting || 'Deleting subscription…', '');
            call('strava/webhook/subscription', 'DELETE').then(function (r) {
                unsubBtn.disabled = false;
                if (r.ok && r.json && r.json.success) {
                    setSubState(false);
                    setMsg(i18n.sub_deleted || 'Subscription deleted.', 'success');
                } else {
                    setMsg(firstError(r.json) || i18n.error || 'Error.', 'error');
                }
            }).catch(function () {
                unsubBtn.disabled = false;
                setMsg(i18n.network_error || 'Network error.', 'error');
            });
        });
    }

    // ---- Connected players roster --------------------------------------
    var summaryEl = root.querySelector('[data-tt-strava-admin-summary]');
    var rowsEl = root.querySelector('[data-tt-strava-admin-rows]');

    function statusLabel(status) {
        switch (status) {
            case 'connected': return { kind: 'ok', label: i18n.status_connected || 'Connected' };
            case 'pending': return { kind: 'muted', label: i18n.status_pending || 'Pending consent' };
            case 'revoked': return { kind: 'error', label: i18n.status_revoked || 'Revoked' };
            default: return { kind: 'muted', label: i18n.status_disconnected || 'Disconnected' };
        }
    }

    function fmtDate(value) {
        if (!value) return i18n.never || 'Never';
        return String(value).substring(0, 10);
    }

    function cell(text, cls) {
        var td = document.createElement('td');
        if (cls) td.className = cls;
        td.textContent = text;
        return td;
    }

    function renderRoster(data) {
        var conns = (data && data.connections) || [];
        var connected = conns.filter(function (c) { return c.status === 'connected'; }).length;

        if (summaryEl) {
            summaryEl.textContent = (i18n.summary || '%1$d connected of %2$d players.')
                .replace('%1$d', connected).replace('%2$d', conns.length);
        }
        if (!rowsEl) return;
        rowsEl.textContent = '';

        if (!conns.length) {
            var tr = document.createElement('tr');
            tr.appendChild(cell(i18n.no_connections || 'No players have connected yet.', 'tt-strava-admin__muted'));
            tr.firstChild.setAttribute('colspan', '5');
            rowsEl.appendChild(tr);
            return;
        }

        conns.forEach(function (c) {
            var tr = document.createElement('tr');
            tr.appendChild(cell(c.player_name || ''));

            var st = statusLabel(c.status);
            var stTd = document.createElement('td');
            stTd.innerHTML = badge(st.kind, st.label);
            tr.appendChild(stTd);

            tr.appendChild(cell(String(c.activity_count || 0)));
            tr.appendChild(cell(fmtDate(c.last_activity_at)));
            tr.appendChild(cell(fmtDate(c.last_sync_at)));
            rowsEl.appendChild(tr);
        });
    }

    function loadRoster() {
        call('strava/connections').then(function (r) {
            if (r.ok && r.json && r.json.success) {
                renderRoster(r.json.data);
            } else if (summaryEl) {
                summaryEl.textContent = i18n.error || 'Something went wrong.';
            }
        }).catch(function () {
            if (summaryEl) summaryEl.textContent = i18n.network_error || 'Network error.';
        });
    }

    loadRoster();
})();
