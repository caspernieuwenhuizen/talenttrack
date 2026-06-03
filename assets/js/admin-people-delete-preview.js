/*
 * Admin People — bulk "Delete permanently" two-step impact-preview dialog.
 * #1138 (v4.20.0).
 *
 * Wired by `PeoplePage::enqueueAssets()` on the wp-admin People list.
 * Intercepts the bulk-action form submit when the chosen action is
 * `delete_permanent`, fetches `/wp-json/talenttrack/v1/people/delete-preview`
 * for the selected ids, opens a structured <dialog>, and only proceeds to
 * the destructive POST when the operator confirms.
 *
 * Two-step gate (Step 2 = type-DELETE) triggers when:
 *   - selection has >= 3 persons, OR
 *   - any selected person has >= 5 affected references.
 * Skipped for trivial batches (1 person with <=2 refs).
 */
(function () {
    'use strict';

    var BOOT = window.TT_PEOPLE_DELETE_PREVIEW || null;
    if (!BOOT) return;

    function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

    function init() {
        var forms = $$('form.tt-bulk-form');
        forms.forEach(function (form) {
            // The bulk-action helper renders two <select name="tt_bulk_action">
            // (top + bottom). Either may carry the user's choice; PHP takes
            // the last when the form posts. We compute the effective action
            // the same way: prefer non-"-1" values; if both are -1 we don't
            // intercept (the back end will handle the no-op redirect).
            form.addEventListener('submit', function (ev) {
                if (form.dataset.ttDeleteConfirmed === '1') {
                    // Second pass — preview already shown + confirmed.
                    form.dataset.ttDeleteConfirmed = '';
                    return;
                }
                var entity = (form.querySelector('input[name="entity"]') || {}).value || '';
                if (entity !== 'person') return;

                var selects = $$('select[name="tt_bulk_action"]', form);
                var action = '-1';
                selects.forEach(function (s) { if (s.value && s.value !== '-1') action = s.value; });
                if (action !== 'delete_permanent') return;

                var ids = $$('input[name="ids[]"]:checked', form).map(function (i) { return parseInt(i.value, 10); }).filter(function (n) { return n > 0; });
                if (ids.length === 0) return; // let WP show its "no items selected" path

                ev.preventDefault();
                openPreview(form, ids);
            });
        });
    }

    function openPreview(form, ids) {
        fetchPreview(ids).then(function (payload) {
            renderDialog(form, ids, payload);
        }).catch(function (err) {
            // eslint-disable-next-line no-alert
            alert((BOOT.i18n.preview_failed || 'Preview failed') + ': ' + (err && err.message ? err.message : 'unknown'));
        });
    }

    function fetchPreview(ids) {
        return fetch(BOOT.rest_root + 'talenttrack/v1/people/delete-preview', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': BOOT.nonce },
            body: JSON.stringify({ ids: ids })
        }).then(function (r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        }).then(function (j) {
            // RestResponse::success wraps the payload in { ok, data }
            if (j && typeof j === 'object') return j.data || j;
            return {};
        });
    }

    function renderDialog(form, ids, payload) {
        var dialog = document.createElement('dialog');
        dialog.className = 'tt-people-delete-preview';
        dialog.setAttribute('aria-labelledby', 'tt-pdp-title');

        var persons = (payload && payload.persons) || [];
        var summary = (payload && payload.batch_summary) || { total_persons: ids.length, total_affected_rows: 0 };

        // Two-step gate
        var needsTypeDelete = persons.length >= 3 || persons.some(function (p) {
            var n = 0;
            (p.removals || []).forEach(function (r) { n += r.count ? r.count : 1; });
            (p.nullifications || []).forEach(function (r) { n += r.count ? r.count : 1; });
            return n >= 5;
        });

        dialog.innerHTML = wrap([
            '<header class="tt-pdp-header">',
                '<h2 id="tt-pdp-title">' + esc(format(BOOT.i18n.title_n || 'You are about to permanently delete %d person(s):', summary.total_persons)) + '</h2>',
            '</header>',
            '<div class="tt-pdp-body" data-tt-pdp="body">',
                renderPersonsList(persons),
                '<p class="tt-pdp-note">' + esc(BOOT.i18n.wp_user_unaffected || 'The associated WordPress user accounts are NOT affected — they can still log in until deleted separately via WP’s Users admin.') + '</p>',
            '</div>',
            '<footer class="tt-pdp-footer">',
                '<button type="button" class="button" data-tt-pdp="cancel">' + esc(BOOT.i18n.cancel || 'Cancel') + '</button>',
                '<button type="button" class="button button-primary" data-tt-pdp="confirm-1">' + esc(format(BOOT.i18n.delete_n || 'Delete %d person(s)', summary.total_persons)) + '</button>',
            '</footer>'
        ].join(''));

        document.body.appendChild(dialog);
        dialog.showModal();

        dialog.querySelector('[data-tt-pdp="cancel"]').addEventListener('click', function () {
            dialog.close();
            dialog.remove();
        });

        dialog.querySelector('[data-tt-pdp="confirm-1"]').addEventListener('click', function () {
            if (needsTypeDelete) {
                renderTypeDeleteStep(dialog, function () { commitDelete(form, dialog); });
            } else {
                commitDelete(form, dialog);
            }
        });
    }

    function renderTypeDeleteStep(dialog, onConfirm) {
        var body = dialog.querySelector('[data-tt-pdp="body"]');
        var footer = dialog.querySelector('.tt-pdp-footer');
        body.innerHTML =
            '<p class="tt-pdp-final-prompt">' + esc(BOOT.i18n.type_delete_prompt || 'Type DELETE to confirm this destructive action:') + '</p>' +
            '<input type="text" class="tt-pdp-type-input" data-tt-pdp="type-input" autocomplete="off" inputmode="text" autocapitalize="characters" autofocus />';
        footer.innerHTML =
            '<button type="button" class="button" data-tt-pdp="cancel">' + esc(BOOT.i18n.cancel || 'Cancel') + '</button>' +
            '<button type="button" class="button button-primary" data-tt-pdp="confirm-2" disabled>' + esc(BOOT.i18n.final_confirm || 'Confirm delete') + '</button>';

        var input = dialog.querySelector('[data-tt-pdp="type-input"]');
        var confirm2 = dialog.querySelector('[data-tt-pdp="confirm-2"]');
        var cancel2 = dialog.querySelector('[data-tt-pdp="cancel"]');

        input.addEventListener('input', function () {
            confirm2.disabled = (input.value.trim().toUpperCase() !== 'DELETE');
        });
        confirm2.addEventListener('click', function () {
            if (input.value.trim().toUpperCase() !== 'DELETE') return;
            onConfirm();
        });
        cancel2.addEventListener('click', function () {
            dialog.close();
            dialog.remove();
        });
        // Set focus so screenreaders + keyboard users land on the input.
        setTimeout(function () { input.focus(); }, 30);
    }

    function commitDelete(form, dialog) {
        dialog.close();
        dialog.remove();
        form.dataset.ttDeleteConfirmed = '1';
        // Some browsers don't fire submit on form.submit() when other handlers
        // re-prevent — request submitted via requestSubmit when available, else
        // fall back to the synthetic submit() (which intentionally skips
        // listeners, exactly what we want for the second pass).
        if (typeof form.requestSubmit === 'function') {
            form.requestSubmit();
        } else {
            form.submit();
        }
    }

    function renderPersonsList(persons) {
        if (!persons.length) {
            return '<p>' + esc(BOOT.i18n.no_persons || 'No persons in the selection.') + '</p>';
        }
        return '<ul class="tt-pdp-persons">' + persons.map(function (p) {
            var bits = [];
            (p.removals || []).forEach(function (r) { bits.push('<li>' + esc(formatRemoval(r)) + '</li>'); });
            (p.nullifications || []).forEach(function (r) { bits.push('<li>' + esc(formatNullification(r)) + '</li>'); });
            if (!bits.length) bits.push('<li>' + esc(BOOT.i18n.no_refs || 'No related records.') + '</li>');
            return '<li class="tt-pdp-person">' +
                '<strong>' + esc(p.display_name || ('#' + p.id)) + '</strong>' +
                ' <span class="tt-pdp-id">(id ' + parseInt(p.id, 10) + ')</span>' +
                '<ul>' + bits.join('') + '</ul>' +
            '</li>';
        }).join('') + '</ul>';
    }

    function formatRemoval(r) {
        var lang = BOOT.i18n;
        switch (r.kind) {
            case 'team_role':
                return format(lang.team_role || 'Will be removed as %s from team %s.', r.role, r.team);
            case 'role_scope_target':
                return format(lang.role_scope_target || '%d functional-role scope grant(s) will be removed.', r.count);
            case 'staff_development':
                return format(lang.staff_development || '%d staff-development entry(ies) will be removed.', r.count);
            case 'staff_certifications':
                return format(lang.staff_certifications || '%d staff certification record(s) will be removed.', r.count);
            case 'staff_evaluations':
                return format(lang.staff_evaluations || '%d staff evaluation(s) will be removed.', r.count);
            case 'staff_goals':
                return format(lang.staff_goals || '%d staff goal(s) will be removed.', r.count);
            case 'mentorship':
                return format(lang.mentorship || '%d mentorship pairing(s) will be removed.', r.count);
            case 'invitation_pending':
                return format(lang.invitation_pending || '%d pending invitation(s) to this person will be cancelled.', r.count);
            default:
                return JSON.stringify(r);
        }
    }

    function formatNullification(r) {
        var lang = BOOT.i18n;
        switch (r.kind) {
            case 'scope_grantor':
                return format(lang.scope_grantor || '%d functional-role grant(s) attribution will become unknown (grants stay).', r.count);
            case 'invitation_accepted':
                return format(lang.invitation_accepted || '%d accepted invitation(s) will keep the historical record; target reference cleared.', r.count);
            case 'player_parent_link':
                return format(lang.player_parent_link || 'Will be removed as parent contact from player %s.', r.player);
            default:
                return JSON.stringify(r);
        }
    }

    function wrap(html) { return html; }

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function format(template) {
        var args = Array.prototype.slice.call(arguments, 1);
        var i = 0;
        return String(template).replace(/%[ds]/g, function () {
            var v = args[i++];
            return (v == null ? '' : String(v));
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
