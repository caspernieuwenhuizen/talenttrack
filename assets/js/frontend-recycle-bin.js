/*
 * frontend-recycle-bin.js (#2024, epic #2018)
 *
 * Drives the centralized recycle-bin view (?tt_view=recycle-bin). Each
 * row carries two action buttons:
 *
 *   [data-tt-rb-action="restore"] data-tt-rb-path="recycle-bin/{e}/{id}/restore"
 *   [data-tt-rb-action="purge"]   data-tt-rb-path="recycle-bin/{e}/{id}"
 *                                 data-tt-rb-preview="recycle-bin/preview/{e}/{id}"
 *
 * Restore  → confirm modal → POST   .../restore           → reload on success.
 * Delete   → fetch the cascade preview → confirm modal listing what will be
 *            removed / kept → DELETE .../{id} → reload on success. A 409
 *            (DeleteBlockedException) re-renders the modal as a blocked
 *            dependency report and leaves the row in place.
 *
 * REST root + nonce come from window.TT (set by public.js on every dashboard
 * page); localised strings come from window.TT_RecycleBinI18n
 * (wp_localize_script). Native <dialog>, with a window.confirm fallback.
 */
(function () {
    'use strict';

    var globals = window.TT || {};
    var restRoot = globals.rest_url || '';
    var restNonce = globals.rest_nonce || '';

    var i18n = window.TT_RecycleBinI18n || {};
    var DIALOG_ID = 'tt-rb-dialog';

    function t(key, fallback) {
        return i18n[key] || fallback;
    }

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
        });
    }

    function ensureDialog() {
        var existing = document.getElementById(DIALOG_ID);
        if (existing) return existing;
        if (typeof HTMLDialogElement === 'undefined') return null;

        var dialog = document.createElement('dialog');
        dialog.id = DIALOG_ID;
        dialog.className = 'tt-modal tt-modal--recycle-bin';
        dialog.innerHTML =
            '<form method="dialog" class="tt-modal-form">' +
                '<h2 class="tt-modal-title" data-tt-rb-title></h2>' +
                '<div class="tt-modal-message" data-tt-rb-body></div>' +
                '<div class="tt-modal-actions">' +
                    '<button type="submit" value="cancel" class="tt-btn tt-btn-secondary" data-tt-rb-cancel></button>' +
                    '<button type="submit" value="confirm" class="tt-btn tt-btn-danger" data-tt-rb-confirm></button>' +
                '</div>' +
            '</form>';
        document.body.appendChild(dialog);
        return dialog;
    }

    /**
     * Show the modal. opts: { title, bodyHtml, confirmLabel, confirmVariant,
     * hideConfirm }. Resolves via onResult(true|false).
     */
    function showModal(opts, onResult) {
        var dialog = ensureDialog();
        if (!dialog) {
            onResult(window.confirm(opts.plainMessage || opts.title || ''));
            return;
        }
        dialog.querySelector('[data-tt-rb-title]').textContent = opts.title || '';
        dialog.querySelector('[data-tt-rb-body]').innerHTML = opts.bodyHtml || '';
        dialog.querySelector('[data-tt-rb-cancel]').textContent = t('cancel', 'Cancel');

        var confirmBtn = dialog.querySelector('[data-tt-rb-confirm]');
        if (opts.hideConfirm) {
            confirmBtn.hidden = true;
        } else {
            confirmBtn.hidden = false;
            confirmBtn.textContent = opts.confirmLabel || '';
            confirmBtn.className = 'tt-btn ' + (opts.confirmVariant === 'primary' ? 'tt-btn-primary' : 'tt-btn-danger');
        }

        var closeHandler = function () {
            dialog.removeEventListener('close', closeHandler);
            onResult(dialog.returnValue === 'confirm');
        };
        dialog.addEventListener('close', closeHandler);
        dialog.showModal();
        var cancelBtn = dialog.querySelector('[data-tt-rb-cancel]');
        if (cancelBtn) cancelBtn.focus();
    }

    function restConfig() {
        if (!restRoot || !restNonce) {
            window.alert(t('configError', 'Recycle bin is unavailable: REST configuration missing.'));
            return false;
        }
        return true;
    }

    function reload() {
        window.location.reload();
    }

    function readBody(res) {
        return res.json().then(function (body) {
            return { status: res.status, body: body };
        }).catch(function () {
            return { status: res.status, body: null };
        });
    }

    function errorMessage(r) {
        if (r.body && r.body.errors && r.body.errors[0] && r.body.errors[0].message) {
            return r.body.errors[0].message;
        }
        return t('genericError', 'Action failed. Please try again.');
    }

    /* ---- restore -------------------------------------------------------- */

    function doRestore(btn) {
        showModal({
            title: t('restoreTitle', 'Restore record'),
            bodyHtml: '<p>' + escapeHtml(t('restoreConfirm', 'Restore this record to the archive?')) + '</p>',
            plainMessage: t('restoreConfirm', 'Restore this record to the archive?'),
            confirmLabel: t('restoreAction', 'Restore'),
            confirmVariant: 'primary'
        }, function (ok) {
            if (!ok) return;
            if (!restConfig()) return;
            btn.disabled = true;
            fetch(restRoot + btn.getAttribute('data-tt-rb-path'), {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'X-WP-Nonce': restNonce }
            }).then(readBody).then(function (r) {
                if (r.status >= 200 && r.status < 300 && (!r.body || r.body.success !== false)) {
                    reload();
                    return;
                }
                btn.disabled = false;
                window.alert(errorMessage(r));
            }).catch(function () {
                btn.disabled = false;
                window.alert(t('networkError', 'Network error. Please try again.'));
            });
        });
    }

    /* ---- purge ---------------------------------------------------------- */

    function cascadeHtml(data) {
        var html = '';
        var removals = (data && data.removals) || [];
        var nulls = (data && data.nullifications) || [];
        var zeros = (data && data.zeroings) || [];

        if (!removals.length && !nulls.length && !zeros.length) {
            html += '<p>' + escapeHtml(t('purgeNothing', 'No other records depend on this one.')) + '</p>';
            return html;
        }

        html += '<div class="tt-rb-preview">';
        if (removals.length) {
            html += '<p class="tt-rb-preview__heading">' + escapeHtml(t('removedLabel', 'Removed:')) + '</p><ul class="tt-rb-preview__list">';
            removals.forEach(function (r) {
                html += '<li>' + escapeHtml(humanTable(r.table)) + ' (' + (parseInt(r.count, 10) || 0) + ')</li>';
            });
            html += '</ul>';
        }
        var kept = nulls.concat(zeros);
        if (kept.length) {
            html += '<p class="tt-rb-preview__heading">' + escapeHtml(t('purgeKept', 'Kept (references cleared, not deleted):')) + '</p><ul class="tt-rb-preview__list">';
            kept.forEach(function (r) {
                html += '<li>' + escapeHtml(humanTable(r.table)) + ' (' + (parseInt(r.count, 10) || 0) + ')</li>';
            });
            html += '</ul>';
        }
        html += '</div>';
        return html;
    }

    function blockedHtml(report) {
        var html = '<div class="tt-rb-preview tt-rb-preview--blocked">';
        html += '<p class="tt-rb-preview__heading">' + escapeHtml(t('purgeBlocked', 'This record cannot be deleted yet — other records still depend on it:')) + '</p>';
        html += '<ul class="tt-rb-preview__list">';
        Object.keys(report || {}).forEach(function (table) {
            html += '<li>' + escapeHtml(humanTable(table)) + ' (' + (parseInt(report[table], 10) || 0) + ')</li>';
        });
        html += '</ul></div>';
        return html;
    }

    function humanTable(table) {
        return String(table || '').replace(/^tt_/, '').replace(/_/g, ' ');
    }

    function doPurge(btn) {
        if (!restConfig()) return;
        var previewPath = btn.getAttribute('data-tt-rb-preview');
        var label = btn.getAttribute('data-tt-rb-label') || '';

        btn.disabled = true;
        fetch(restRoot + previewPath, {
            method: 'GET',
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-WP-Nonce': restNonce }
        }).then(readBody).then(function (r) {
            btn.disabled = false;
            var data = (r.body && r.body.data) || {};
            var intro = '<p>' + escapeHtml(label) + '</p><p>' + escapeHtml(t('purgeIntro', 'This permanently deletes the record and cannot be undone. The following will also be removed or cleared:')) + '</p>';

            showModal({
                title: t('purgeTitle', 'Delete permanently'),
                bodyHtml: intro + cascadeHtml(data),
                confirmLabel: t('purgeAction', 'Delete permanently'),
                confirmVariant: 'danger'
            }, function (ok) {
                if (!ok) return;
                sendPurge(btn);
            });
        }).catch(function () {
            btn.disabled = false;
            window.alert(t('networkError', 'Network error. Please try again.'));
        });
    }

    function sendPurge(btn) {
        btn.disabled = true;
        fetch(restRoot + btn.getAttribute('data-tt-rb-path'), {
            method: 'DELETE',
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-WP-Nonce': restNonce }
        }).then(readBody).then(function (r) {
            if (r.status >= 200 && r.status < 300 && (!r.body || r.body.success !== false)) {
                reload();
                return;
            }
            btn.disabled = false;
            // 409 fail-closed: re-render the modal as a blocked dependency
            // report; the record stays in the bin.
            if (r.status === 409 && r.body && r.body.errors && r.body.errors[0] && r.body.errors[0].details && r.body.errors[0].details.report) {
                showModal({
                    title: t('purgeTitle', 'Delete permanently'),
                    bodyHtml: blockedHtml(r.body.errors[0].details.report),
                    hideConfirm: true
                }, function () {});
                return;
            }
            window.alert(errorMessage(r));
        }).catch(function () {
            btn.disabled = false;
            window.alert(t('networkError', 'Network error. Please try again.'));
        });
    }

    function init() {
        var buttons = document.querySelectorAll('.tt-rb-action');
        Array.prototype.forEach.call(buttons, function (btn) {
            btn.addEventListener('click', function (ev) {
                ev.preventDefault();
                if (btn.disabled) return;
                var action = btn.getAttribute('data-tt-rb-action');
                if (action === 'restore') {
                    doRestore(btn);
                } else if (action === 'purge') {
                    doPurge(btn);
                }
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
