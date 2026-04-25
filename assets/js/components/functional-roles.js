/**
 * TalentTrack — functional role types reorder + delete
 * #0019 Sprint 4
 *
 * Q2 in shaping: up/down arrow buttons for reorder, no DragReorder.
 * Each click swaps sort_order with the adjacent row and reloads.
 * Delete buttons hit DELETE /functional-roles/{id} with confirm.
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

    function setMsg(root, kind, text) {
        var el = root.querySelector('[data-tt-fnrole-msg="1"]');
        if (!el) return;
        el.classList.remove('tt-success', 'tt-error');
        if (kind) el.classList.add(kind === 'success' ? 'tt-success' : 'tt-error');
        el.textContent = text || '';
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
                setMsg(root, 'error', msg);
                if (btn) btn.disabled = false;
            }
        }).catch(function() {
            setMsg(root, 'error', (window.TT && TT.i18n && TT.i18n.network_error) || 'Network error.');
            if (btn) btn.disabled = false;
        });
    }

    function wire(root) {
        root.addEventListener('click', function(e) {
            var moveBtn = e.target.closest('[data-tt-fnrole-move]');
            if (moveBtn && root.contains(moveBtn)) {
                e.preventDefault();
                var tr = moveBtn.closest('tr');
                var roleId = tr && tr.getAttribute('data-role-id');
                var direction = moveBtn.getAttribute('data-tt-fnrole-move');
                if (!roleId || !direction) return;
                call('functional-roles/' + encodeURIComponent(roleId) + '/move', 'POST', { direction: direction }, moveBtn, root);
                return;
            }

            var deleteBtn = e.target.closest('[data-tt-fnrole-delete]');
            if (deleteBtn && root.contains(deleteBtn)) {
                e.preventDefault();
                var i18n = (window.TT && window.TT.i18n) || {};
                if (!window.confirm(i18n.fnrole_delete_confirm || 'Delete this role type?')) return;
                var id = deleteBtn.getAttribute('data-tt-fnrole-delete');
                if (!id) return;
                call('functional-roles/' + encodeURIComponent(id), 'DELETE', null, deleteBtn, root);
                return;
            }
        });
    }

    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('.tt-dashboard [data-tt-fnrole-types="1"]').forEach(wire);
    });
})();
