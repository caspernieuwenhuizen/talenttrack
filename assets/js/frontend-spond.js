/*
 * frontend-spond.js — FrontendSpondView (#1936).
 *
 * Wires the credentials form, Test connection / Disconnect buttons, the
 * per-team "Refresh now" buttons, and the API base-URL override on the
 * frontend Spond view to the REST surface:
 *   POST   /wp-json/talenttrack/v1/spond/credentials
 *   DELETE /wp-json/talenttrack/v1/spond/credentials
 *   POST   /wp-json/talenttrack/v1/spond/test
 *   POST   /wp-json/talenttrack/v1/spond/base-url
 *   POST   /wp-json/talenttrack/v1/teams/{id}/spond/sync
 *   POST   /wp-json/talenttrack/v1/teams/{id}/spond/credentials
 *   DELETE /wp-json/talenttrack/v1/teams/{id}/spond/credentials
 *   POST   /wp-json/talenttrack/v1/teams/{id}/spond/test
 *
 * The view composes the payload here; the controller decides (keep-on-
 * blank password, the live login, the override write live server-side).
 * The password is sent on save/test but never read back into the DOM.
 * Strings come from the localised TT_Spond object — no hard-coded
 * English.
 */
(function () {
    'use strict';

    var root = document.querySelector('[data-tt-spond]');
    if (!root) return;

    var cfg = window.TT_Spond || {};
    var i18n = cfg.i18n || {};
    var rest = ((window.TT && window.TT.rest_url) || '/wp-json/talenttrack/v1/').replace(/\/+$/, '/');
    var nonce = (window.TT && window.TT.rest_nonce) || (window.wpApiSettings && window.wpApiSettings.nonce) || '';

    var msg = root.querySelector('[data-tt-spond-msg]');

    function headers() {
        var h = { 'Accept': 'application/json', 'Content-Type': 'application/json' };
        if (nonce) h['X-WP-Nonce'] = nonce;
        return h;
    }

    function firstError(json) {
        return (json && json.errors && json.errors[0] && json.errors[0].message) || '';
    }

    function setMsg(text, kind) {
        if (!msg) return;
        msg.className = 'tt-spond__form-msg' + (kind ? ' tt-' + kind : '');
        msg.textContent = text || '';
    }

    function post(path, body, method) {
        return fetch(rest + path, {
            method: method || 'POST',
            credentials: 'same-origin',
            headers: headers(),
            body: JSON.stringify(body || {})
        }).then(function (res) {
            return res.json().then(function (json) { return { ok: res.ok, json: json }; });
        });
    }

    function reloadSoon() {
        setTimeout(function () { window.location.reload(); }, 700);
    }

    // ---- Sync preview (#3247) ------------------------------------------
    // Rendered from the dry-run `/teams/{id}/spond/preview` payload, the
    // same one the monitor view uses. Kept to a summary plus the first few
    // events: this is a confirmation that the right calendar is behind the
    // link, not the field-level comparison, which the monitor still owns.
    var PREVIEW_MAX = 6;

    function fmt(tpl, args) {
        return String(tpl).replace(/%(\d+)\$d|%d/g, function (m, idx) {
            return String(args[idx ? parseInt(idx, 10) - 1 : 0]);
        });
    }

    function el(tag, cls, text) {
        var node = document.createElement(tag);
        if (cls) node.className = cls;
        if (text != null) node.textContent = text;
        return node;
    }

    function clearPreview(box) {
        if (!box) return;
        box.textContent = '';
        box.hidden = true;
    }

    function showPreviewNote(box, text) {
        if (!box || !text) return;
        box.textContent = '';
        box.appendChild(el('p', 'tt-spond__preview-note', text));
        box.hidden = false;
    }

    function renderPreview(box, data) {
        if (!box) return;
        box.textContent = '';
        box.hidden = false;

        // The endpoint answers 200 with ok:false for the states that are
        // not failures — most often "no group linked yet", which is exactly
        // where someone is when they first press Test.
        if (data.ok === false) {
            box.appendChild(el('p', 'tt-spond__preview-note', data.error_message || i18n.preview_failed || ''));
            return;
        }

        var counts = data.counts || {};
        box.appendChild(el('p', 'tt-spond__preview-counts', fmt(
            i18n.preview_counts || '%1$d new · %2$d updated · %3$d archived',
            [counts['new'] || 0, counts.update || 0, counts.archive || 0]
        )));

        var events = Array.isArray(data.events) ? data.events : [];
        if (!events.length) {
            box.appendChild(el('p', 'tt-spond__preview-note', i18n.preview_none || ''));
        } else {
            var list = el('ul', 'tt-spond__preview-list');
            events.slice(0, PREVIEW_MAX).forEach(function (ev) {
                var item = el('li', 'tt-spond__preview-item');
                item.appendChild(el('span',
                    'tt-spond__badge tt-spond__badge--' + (ev.status === 'update' ? 'partial' : 'ok'),
                    ev.status === 'update' ? (i18n.status_update || 'Update') : (i18n.status_new || 'New')));
                item.appendChild(el('span', 'tt-spond__preview-when', ev.dtstart || ''));
                item.appendChild(el('span', 'tt-spond__preview-title', ev.summary || ''));
                list.appendChild(item);
            });
            box.appendChild(list);

            if (events.length > PREVIEW_MAX) {
                box.appendChild(el('p', 'tt-spond__preview-note',
                    fmt(i18n.preview_more || '%d more not listed.', [events.length - PREVIEW_MAX])));
            }
        }

        box.appendChild(el('p', 'tt-spond__preview-note', i18n.preview_safe || ''));

        if (cfg.monitor_url) {
            var link = el('a', 'tt-spond__preview-link', i18n.preview_monitor || '');
            link.href = cfg.monitor_url;
            box.appendChild(link);
        }
    }

    // ---- Save credentials ----------------------------------------------
    var credForm = root.querySelector('[data-tt-spond-creds-form]');
    if (credForm) {
        credForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var btn = credForm.querySelector('.tt-save-btn');
            if (btn) btn.setAttribute('data-state', 'saving');
            setMsg('', '');

            var fd = new FormData(credForm);
            post('spond/credentials', {
                email: String(fd.get('email') || ''),
                password: String(fd.get('password') || '')
            }).then(function (r) {
                if (r.ok && r.json && r.json.success) {
                    if (btn) btn.setAttribute('data-state', 'saved');
                    setMsg(i18n.saved || 'Saved.', 'success');
                    reloadSoon();
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

    // ---- Test connection -----------------------------------------------
    var testBtn = root.querySelector('[data-tt-spond-test]');
    if (testBtn) {
        testBtn.addEventListener('click', function () {
            testBtn.disabled = true;
            setMsg('', '');
            var body = {};
            if (credForm) {
                var fd = new FormData(credForm);
                body = { email: String(fd.get('email') || ''), password: String(fd.get('password') || '') };
            }
            post('spond/test', body).then(function (r) {
                testBtn.disabled = false;
                if (r.ok && r.json && r.json.success) {
                    setMsg(i18n.test_ok || 'Login successful.', 'success');
                } else {
                    setMsg(firstError(r.json) || i18n.test_failed || 'Login failed.', 'error');
                }
            }).catch(function () {
                testBtn.disabled = false;
                setMsg(i18n.network_error || 'Network error.', 'error');
            });
        });
    }

    // ---- Disconnect ----------------------------------------------------
    var disconnectBtn = root.querySelector('[data-tt-spond-disconnect]');
    if (disconnectBtn) {
        disconnectBtn.addEventListener('click', function () {
            if (!window.confirm(i18n.disconnect_confirm || 'Disconnect Spond?')) return;
            disconnectBtn.disabled = true;
            setMsg('', '');
            post('spond/credentials', {}, 'DELETE').then(function (r) {
                disconnectBtn.disabled = false;
                if (r.ok && r.json && r.json.success) {
                    setMsg(i18n.disconnected || 'Disconnected.', 'success');
                    reloadSoon();
                } else {
                    setMsg(firstError(r.json) || i18n.error || 'Error.', 'error');
                }
            }).catch(function () {
                disconnectBtn.disabled = false;
                setMsg(i18n.network_error || 'Network error.', 'error');
            });
        });
    }

    // ---- API base-URL override -----------------------------------------
    var baseForm = root.querySelector('[data-tt-spond-baseurl-form]');
    if (baseForm) {
        baseForm.addEventListener('submit', function (e) {
            e.preventDefault();
            setMsg('', '');
            var fd = new FormData(baseForm);
            post('spond/base-url', { api_base_url: String(fd.get('api_base_url') || '') }).then(function (r) {
                if (r.ok && r.json && r.json.success) {
                    setMsg(i18n.base_url_saved || 'Endpoint saved.', 'success');
                    reloadSoon();
                } else {
                    setMsg(firstError(r.json) || i18n.error || 'Error.', 'error');
                }
            }).catch(function () {
                setMsg(i18n.network_error || 'Network error.', 'error');
            });
        });
    }

    // ---- Per-team Refresh now ------------------------------------------
    root.querySelectorAll('[data-tt-spond-refresh]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var teamId = parseInt(btn.getAttribute('data-team-id') || '0', 10);
            if (!teamId) return;
            var original = btn.textContent;
            btn.disabled = true;
            btn.textContent = i18n.refreshing || 'Refreshing…';
            setMsg('', '');
            post('teams/' + teamId + '/spond/sync', {}).then(function (r) {
                btn.disabled = false;
                btn.textContent = original;
                if (r.ok && r.json && r.json.success) {
                    setMsg(i18n.refreshed || 'Sync triggered.', 'success');
                    reloadSoon();
                } else {
                    setMsg(firstError(r.json) || i18n.error || 'Error.', 'error');
                }
            }).catch(function () {
                btn.disabled = false;
                btn.textContent = original;
                setMsg(i18n.network_error || 'Network error.', 'error');
            });
        });
    });

    // ---- Per-team Spond account override (#2286) -----------------------
    root.querySelectorAll('[data-tt-spond-team-creds-form]').forEach(function (form) {
        var teamId = parseInt(form.getAttribute('data-team-id') || '0', 10);
        if (!teamId) return;

        var base = 'teams/' + teamId + '/spond/';
        var saveBtn = form.querySelector('[data-tt-spond-team-save]');
        var testBtn = form.querySelector('[data-tt-spond-team-test]');
        var useClubBtn = form.querySelector('[data-tt-spond-team-use-club]');

        function creds() {
            var fd = new FormData(form);
            return {
                email: String(fd.get('email') || ''),
                password: String(fd.get('password') || '')
            };
        }

        // Save (POST credentials).
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            if (saveBtn) saveBtn.disabled = true;
            setMsg('', '');
            post(base + 'credentials', creds()).then(function (r) {
                if (saveBtn) saveBtn.disabled = false;
                if (r.ok && r.json && r.json.success) {
                    setMsg(i18n.team_saved || 'Saved.', 'success');
                    reloadSoon();
                } else {
                    setMsg(firstError(r.json) || i18n.error || 'Error.', 'error');
                }
            }).catch(function () {
                if (saveBtn) saveBtn.disabled = false;
                setMsg(i18n.network_error || 'Network error.', 'error');
            });
        });

        // Test (POST test), then show what a sync would replicate (#3247).
        //
        // A passing login answers "is the password right", which is not the
        // question someone pressing Test has — they have just linked a group
        // and want to know whether the right calendar is behind it. The
        // dry-run preview endpoint already answers that and writes nothing,
        // so Test runs both and reports the second.
        if (testBtn) {
            var preview = root.querySelector('[data-tt-spond-team-preview][data-team-id="' + teamId + '"]');
            var idleLabel = testBtn.textContent;

            testBtn.addEventListener('click', function () {
                testBtn.disabled = true;
                testBtn.textContent = i18n.testing || 'Testing…';
                setMsg('', '');
                clearPreview(preview);

                post(base + 'test', creds()).then(function (r) {
                    if (!(r.ok && r.json && r.json.success)) {
                        // Login failed — no point asking for a calendar.
                        testBtn.disabled = false;
                        testBtn.textContent = idleLabel;
                        setMsg(firstError(r.json) || i18n.test_failed || 'Login failed.', 'error');
                        return;
                    }

                    setMsg(i18n.test_ok || 'Login successful.', 'success');
                    showPreviewNote(preview, i18n.preview_loading || 'Checking what would sync…');

                    return post(base + 'preview', {}).then(function (p) {
                        testBtn.disabled = false;
                        testBtn.textContent = idleLabel;
                        var data = (p.json && p.json.data) || {};
                        if (!p.ok || !(p.json && p.json.success)) {
                            showPreviewNote(preview, firstError(p.json) || i18n.preview_failed || '');
                            return;
                        }
                        renderPreview(preview, data);
                    });
                }).catch(function () {
                    testBtn.disabled = false;
                    testBtn.textContent = idleLabel;
                    clearPreview(preview);
                    setMsg(i18n.network_error || 'Network error.', 'error');
                });
            });
        }

        // Use club account (DELETE the override).
        if (useClubBtn) {
            useClubBtn.addEventListener('click', function () {
                if (!window.confirm(i18n.team_use_club_confirm || 'Use the club account for this team?')) return;
                useClubBtn.disabled = true;
                setMsg('', '');
                post(base + 'credentials', {}, 'DELETE').then(function (r) {
                    useClubBtn.disabled = false;
                    if (r.ok && r.json && r.json.success) {
                        setMsg(i18n.team_cleared || 'Team now uses the club account.', 'success');
                        reloadSoon();
                    } else {
                        setMsg(firstError(r.json) || i18n.error || 'Error.', 'error');
                    }
                }).catch(function () {
                    useClubBtn.disabled = false;
                    setMsg(i18n.network_error || 'Network error.', 'error');
                });
            });
        }
    });

    // ---- Per-team Spond group selection (#2399) ------------------------
    // The select is only rendered when the group listing succeeded, i.e.
    // after the login works — so there is no "no groups" state to handle
    // here. Sharing a group with another team is warned about, never
    // blocked: a combined age-group calendar legitimately does it.
    root.querySelectorAll('[data-tt-spond-team-group]').forEach(function (select) {
        var teamId = parseInt(select.getAttribute('data-team-id') || '0', 10);
        if (!teamId) return;

        var wrap = select.closest('.tt-spond__team-group') || root;
        var warning = wrap.querySelector('[data-tt-spond-group-warning]');
        var saveBtn = wrap.querySelector('[data-tt-spond-team-group-save]');

        function usedBy() {
            var opt = select.options[select.selectedIndex];
            return (opt && opt.getAttribute('data-tt-used-by')) || '';
        }

        function renderWarning() {
            if (!warning) return;
            var team = usedBy();
            if (!team) {
                warning.hidden = true;
                warning.textContent = '';
                return;
            }
            var tpl = i18n.group_shared || '%s is already linked to this Spond group.';
            warning.textContent = tpl.replace('%s', team);
            warning.hidden = false;
        }

        select.addEventListener('change', renderWarning);
        renderWarning();

        if (saveBtn) {
            saveBtn.addEventListener('click', function () {
                saveBtn.disabled = true;
                setMsg('', '');
                post('teams/' + teamId + '/spond/group', { group_id: select.value }).then(function (r) {
                    saveBtn.disabled = false;
                    if (r.ok && r.json && r.json.success) {
                        setMsg(i18n.group_saved || 'Spond group saved.', 'success');
                        reloadSoon();
                    } else {
                        setMsg(firstError(r.json) || i18n.error || 'Error.', 'error');
                    }
                }).catch(function () {
                    saveBtn.disabled = false;
                    setMsg(i18n.network_error || 'Network error.', 'error');
                });
            });
        }
    });
})();
