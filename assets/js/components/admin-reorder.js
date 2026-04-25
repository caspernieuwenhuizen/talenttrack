/**
 * TalentTrack — admin-tier reorder/delete handler
 * #0019 Sprint 5
 *
 * Generic up/down-arrow + delete handler that powers two surfaces:
 *
 *   - Eval Categories: `[data-tt-eval-categories="1"]` parent. Move
 *     buttons carry `[data-tt-eval-cat-move="up|down"]`. Delete
 *     buttons carry `[data-tt-eval-cat-delete="<id>"]`. REST path
 *     is `eval-categories/{id}` and `eval-categories/{id}/move`.
 *
 *   - Custom Fields list: `[data-tt-list-table="1"]` already drives
 *     the Edit/Delete row actions via FrontendListTable; this script
 *     adds a sibling "Reorder" affordance for the custom-fields tile
 *     when needed. (List uses FrontendListTable's standard row
 *     actions — no extra wiring required for delete.)
 *
 * The functional-roles surface from Sprint 4 has its own tiny
 * `functional-roles.js` because it predates this generic helper;
 * leaving it as-is.
 */
(function(){
    'use strict';

    function getRest() {
        var t = window.TT || {};
        return {
            url: (t.rest_url || '/wp-json/talenttrack/v1/').replace(/\/+$/, '/'),
            nonce: t.rest_nonce || ''
        };
    }

    function call(path, method, body, btn, root) {
        var rest = getRest();
        var headers = { 'Accept': 'application/json' };
        if (rest.nonce) headers['X-WP-Nonce'] = rest.nonce;
        if (body) headers['Content-Type'] = 'application/json';
        if (btn) btn.disabled = true;
        return fetch(rest.url + path.replace(/^\/+/, ''), {
            method: method,
            credentials: 'same-origin',
            headers: headers,
            body: body ? JSON.stringify(body) : undefined
        }).then(function(res) {
            return res.json().then(function(json) { return { ok: res.ok, json: json }; });
        }).then(function(r) {
            if (r.ok && r.json && r.json.success) {
                window.location.reload();
            } else {
                var msg = (r.json && r.json.errors && r.json.errors[0] && r.json.errors[0].message) || 'Error';
                var msgEl = root.querySelector('[data-tt-eval-cat-msg="1"]');
                if (msgEl) {
                    msgEl.classList.remove('tt-success');
                    msgEl.classList.add('tt-error');
                    msgEl.textContent = msg;
                } else if (window.alert) window.alert(msg);
                if (btn) btn.disabled = false;
            }
        }).catch(function() {
            var i18n = (window.TT && window.TT.i18n) || {};
            if (window.alert) window.alert(i18n.network_error || 'Network error.');
            if (btn) btn.disabled = false;
        });
    }

    function wireEvalCategories(root) {
        root.addEventListener('click', function(e) {
            var moveBtn = e.target.closest('[data-tt-eval-cat-move]');
            if (moveBtn && root.contains(moveBtn)) {
                e.preventDefault();
                var rowEl = moveBtn.closest('[data-cat-id]');
                var catId = rowEl && rowEl.getAttribute('data-cat-id');
                var direction = moveBtn.getAttribute('data-tt-eval-cat-move');
                if (!catId || !direction) return;
                call('eval-categories/' + encodeURIComponent(catId) + '/move', 'POST', { direction: direction }, moveBtn, root);
                return;
            }
            var deleteBtn = e.target.closest('[data-tt-eval-cat-delete]');
            if (deleteBtn && root.contains(deleteBtn)) {
                e.preventDefault();
                var i18n = (window.TT && window.TT.i18n) || {};
                if (!window.confirm(i18n.eval_cat_delete_confirm || 'Delete this category?')) return;
                var id = deleteBtn.getAttribute('data-tt-eval-cat-delete');
                if (!id) return;
                call('eval-categories/' + encodeURIComponent(id), 'DELETE', null, deleteBtn, root);
            }
        });
    }

    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('.tt-dashboard [data-tt-eval-categories="1"]').forEach(wireEvalCategories);
    });
})();
