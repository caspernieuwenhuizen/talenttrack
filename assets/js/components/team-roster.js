/**
 * TalentTrack — team roster helpers
 * #0019 Sprint 3 session 3.2
 *
 * Lives on the team edit form. Two responsibilities:
 *
 *   1. Add a player to the roster — POST /teams/{id}/players/{player_id}
 *   2. Remove a player from the roster — DELETE /teams/{id}/players/{player_id}
 *
 * On success either path reloads the page so the rendered roster
 * (and the addable-players dropdown) reflect the new state. Reload
 * is the simplest correct option; if perceived latency becomes a
 * complaint we can do an in-place DOM update later.
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
        var el = root.querySelector('[data-tt-roster-msg="1"]');
        if (!el) return;
        el.classList.remove('tt-success', 'tt-error');
        if (kind) el.classList.add(kind === 'success' ? 'tt-success' : 'tt-error');
        el.textContent = text || '';
    }

    function rosterRequest(path, method, btn, root) {
        var rest = getRest();
        var headers = { 'Accept': 'application/json' };
        if (rest.nonce) headers['X-WP-Nonce'] = rest.nonce;
        if (btn) btn.disabled = true;
        return fetch(rest.url + path.replace(/^\/+/, ''), {
            method: method,
            credentials: 'same-origin',
            headers: headers
        }).then(function(res) {
            return res.json().then(function(json) { return { ok: res.ok, json: json }; });
        }).then(function(r) {
            if (r.ok && r.json && r.json.success) {
                window.location.reload();
            } else {
                var i18n = (window.TT && window.TT.i18n) || {};
                var msg = (r.json && r.json.errors && r.json.errors[0] && r.json.errors[0].message) || i18n.error_generic || 'Error';
                setMsg(root, 'error', msg);
                if (btn) btn.disabled = false;
            }
        }).catch(function() {
            var i18n = (window.TT && window.TT.i18n) || {};
            setMsg(root, 'error', i18n.network_error || 'Network error.');
            if (btn) btn.disabled = false;
        });
    }

    function wire(root) {
        var teamId = root.getAttribute('data-team-id');
        if (!teamId) return;

        var addBtn = root.querySelector('[data-tt-roster-add="1"]');
        var picker = root.querySelector('[data-tt-roster-picker="1"]');
        if (addBtn && picker) {
            addBtn.addEventListener('click', function() {
                var pid = picker.value;
                if (!pid) return;
                rosterRequest('teams/' + encodeURIComponent(teamId) + '/players/' + encodeURIComponent(pid), 'POST', addBtn, root);
            });
        }

        root.addEventListener('click', function(e) {
            var btn = e.target.closest('[data-tt-roster-remove="1"]');
            if (!btn || !root.contains(btn)) return;
            var pid = btn.getAttribute('data-player-id');
            if (!pid) return;
            rosterRequest('teams/' + encodeURIComponent(teamId) + '/players/' + encodeURIComponent(pid), 'DELETE', btn, root);
        });
    }

    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('.tt-dashboard [data-tt-team-roster="1"]').forEach(wire);
    });
})();
