/*
 * pdp-archive-button.js (#1293)
 *
 * Inline Archive / Restore button handler for the PDP manage view.
 * Differs from frontend-archive-button.js (which targets detail-page
 * buttons that redirect on success) by:
 *   1. Living inside the FrontendListTable rows
 *   2. Fading the row out + flashing a near-by toast on success,
 *      no page reload
 *   3. Refreshing the list when "Show archived" is toggled
 *
 * Wire-up: buttons carry `data-tt-pdp-archive="<id>"` (active rows)
 * or `data-tt-pdp-restore="<id>"` (archived rows). Click → confirm →
 * fetch DELETE / POST → fade + remove row + toast.
 *
 * Nonce + REST root come from window.TT (set by public.js on every
 * dashboard page). Strings come from window.TT_PdpArchiveI18n
 * (localised in FrontendPdpManageView::enqueueAssets).
 */
(function () {
    'use strict';

    function rest() {
        var t = window.TT || {};
        return {
            url:   (t.rest_url   || '/wp-json/talenttrack/v1/').replace(/\/+$/, '/'),
            nonce: t.rest_nonce || ''
        };
    }

    function i18n() {
        var raw = window.TT_PdpArchiveI18n || {};
        return {
            confirm_archive: raw.confirm_archive || 'Archive this PDP?',
            confirm_restore: raw.confirm_restore || 'Restore this PDP?',
            archived_toast:  raw.archived_toast  || 'PDP archived',
            restored_toast:  raw.restored_toast  || 'PDP restored',
            error_generic:   raw.error_generic   || 'Action failed.'
        };
    }

    /**
     * Browser confirm. Cheap, accessible by default, matches the
     * frontend-archive-button.js fallback path. The detail-page
     * variant uses a <dialog>-backed modal because it lives outside
     * a list row; inside a row, browser confirm is fine and is
     * consistent with FrontendListTable's row_actions handler.
     */
    function ask(msg) {
        if (typeof window.ttConfirm === 'function') {
            return window.ttConfirm({ message: msg, danger: true });
        }
        return Promise.resolve(window.confirm(msg));
    }

    function fadeRemove(row) {
        if (!row) return;
        row.style.transition = 'opacity .25s ease-out';
        row.style.opacity = '0';
        setTimeout(function () {
            if (row.parentNode) row.parentNode.removeChild(row);
        }, 280);
    }

    function toast(anchor, kind, msg) {
        if (window.ttFlash && typeof window.ttFlash.addNear === 'function') {
            window.ttFlash.addNear(anchor, kind, msg);
        } else if (window.ttFlash && typeof window.ttFlash.add === 'function') {
            window.ttFlash.add(kind, msg);
        }
    }

    function doRequest(method, path, btn, opts) {
        var r = rest();
        var headers = { 'Accept': 'application/json' };
        if (r.nonce) headers['X-WP-Nonce'] = r.nonce;
        btn.disabled = true;
        return fetch(r.url + path.replace(/^\/+/, ''), {
            method: method,
            credentials: 'same-origin',
            headers: headers
        }).then(function (res) {
            return res.json().then(function (body) {
                return { ok: res.ok, status: res.status, body: body };
            }).catch(function () {
                return { ok: res.ok, status: res.status, body: null };
            });
        }).then(function (r2) {
            var success = r2.ok && r2.body && r2.body.success !== false;
            if (success) {
                opts.onSuccess();
                return;
            }
            btn.disabled = false;
            var msg = opts.errorFallback;
            if (r2.body && r2.body.errors && r2.body.errors[0] && r2.body.errors[0].message) {
                msg = r2.body.errors[0].message;
            }
            toast(btn, 'error', msg);
        }).catch(function () {
            btn.disabled = false;
            toast(btn, 'error', opts.errorFallback);
        });
    }

    function handleArchive(btn) {
        var id = btn.getAttribute('data-tt-pdp-archive');
        if (!id) return;
        var strings = i18n();
        ask(strings.confirm_archive).then(function (ok) {
            if (!ok) return;
            doRequest('DELETE', 'pdp-files/' + encodeURIComponent(id), btn, {
                errorFallback: strings.error_generic,
                onSuccess: function () {
                    var row = btn.closest('tr');
                    toast(btn, 'success', strings.archived_toast);
                    fadeRemove(row);
                }
            });
        });
    }

    function handleRestore(btn) {
        var id = btn.getAttribute('data-tt-pdp-restore');
        if (!id) return;
        var strings = i18n();
        ask(strings.confirm_restore).then(function (ok) {
            if (!ok) return;
            doRequest('POST', 'pdp-files/' + encodeURIComponent(id) + '/restore', btn, {
                errorFallback: strings.error_generic,
                onSuccess: function () {
                    var row = btn.closest('tr');
                    toast(btn, 'success', strings.restored_toast);
                    fadeRemove(row);
                }
            });
        });
    }

    function init() {
        // Delegate from document — FrontendListTable re-renders rows
        // after every filter/sort/page change, so per-button listeners
        // would go stale. Delegated handlers survive re-renders.
        document.addEventListener('click', function (ev) {
            var archiveBtn = ev.target.closest && ev.target.closest('[data-tt-pdp-archive]');
            if (archiveBtn) {
                ev.preventDefault();
                ev.stopPropagation();
                handleArchive(archiveBtn);
                return;
            }
            var restoreBtn = ev.target.closest && ev.target.closest('[data-tt-pdp-restore]');
            if (restoreBtn) {
                ev.preventDefault();
                ev.stopPropagation();
                handleRestore(restoreBtn);
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
