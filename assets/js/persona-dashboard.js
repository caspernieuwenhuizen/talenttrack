/*
 * Persona dashboard runtime (#0060 + #0073).
 *
 * Wires:
 *   - Role-switcher pill → POST /me/active-persona (durable persona).
 *   - Team-overview-grid card expand/collapse with localStorage state
 *     and a one-shot AJAX fetch of the team's player breakdown.
 */
(function () {
    'use strict';

    var cfg = window.TT_PersonaDashboard || {};
    if (!cfg.rest_url || !cfg.rest_nonce) return;

    document.addEventListener('click', function (ev) {
        var btn = ev.target && ev.target.closest && ev.target.closest('[data-tt-pd-active-persona]');
        if (btn) {
            var persona = btn.getAttribute('data-tt-pd-active-persona');
            if (!persona) return;
            ev.preventDefault();
            fetch(cfg.rest_url + 'me/active-persona', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.rest_nonce },
                body: JSON.stringify({ persona: persona })
            }).then(function (res) {
                if (res.ok) window.location.reload();
            }).catch(function () { /* fail silent */ });
            return;
        }

        var toggle = ev.target && ev.target.closest && ev.target.closest('[data-tt-team-toggle]');
        if (toggle) {
            ev.preventDefault();
            handleTeamCardToggle(toggle);
        }
    });

    function handleTeamCardToggle(toggleEl) {
        var teamId = toggleEl.getAttribute('data-tt-team-toggle');
        if (!teamId) return;
        var card = toggleEl.closest('.tt-pd-team-card');
        if (!card) return;
        var body = card.querySelector('[data-tt-team-body="' + teamId + '"]');
        if (!body) return;

        var willExpand = body.hasAttribute('hidden');
        if (willExpand) {
            body.removeAttribute('hidden');
            toggleEl.setAttribute('aria-expanded', 'true');
            persistState(teamId, true);
            if (!body.dataset.ttLoaded) {
                fetchTeamBreakdown(teamId, body, parseInt(card.getAttribute('data-tt-days') || '30', 10));
            }
        } else {
            body.setAttribute('hidden', 'hidden');
            toggleEl.setAttribute('aria-expanded', 'false');
            persistState(teamId, false);
        }
    }

    function persistState(teamId, expanded) {
        try {
            window.localStorage.setItem('tt_pd_team_card_' + teamId, expanded ? '1' : '0');
        } catch (e) { /* private browsing — silently skip */ }
    }

    function fetchTeamBreakdown(teamId, body, days) {
        var url = cfg.rest_url + 'persona-dashboard/team-breakdown?team_id=' + encodeURIComponent(teamId)
            + '&days=' + encodeURIComponent(days);
        fetch(url, {
            credentials: 'same-origin',
            headers: { 'X-WP-Nonce': cfg.rest_nonce }
        }).then(function (res) {
            return res.ok ? res.json() : Promise.reject(res.status);
        }).then(function (json) {
            body.dataset.ttLoaded = '1';
            renderBreakdown(body, json && json.players ? json.players : []);
        }).catch(function () {
            body.innerHTML = '<div class="tt-pd-team-card-loading">' + (cfg.i18n_breakdown_failed || 'Could not load player breakdown.') + '</div>';
        });
    }

    function renderBreakdown(body, players) {
        if (!players.length) {
            body.innerHTML = '<div class="tt-pd-team-card-loading">' + (cfg.i18n_breakdown_empty || 'No players in window.') + '</div>';
            return;
        }
        var rows = players.map(function (p) {
            var att = (p.attendance_pct === null || p.attendance_pct === undefined) ? '—' : Math.round(p.attendance_pct) + '%';
            var rat = (p.avg_rating === null || p.avg_rating === undefined) ? '—' : (Math.round(p.avg_rating * 10) / 10).toFixed(1);
            var name = p.name || '—';
            var url  = (cfg.player_view_base_url || '') + '?tt_view=players&id=' + encodeURIComponent(p.player_id);
            return '<div class="tt-pd-team-player-row">'
                + '<a class="tt-pd-team-player-name" href="' + encodeURI(url) + '">' + escapeHtml(name) + '</a>'
                + '<span class="tt-pd-team-player-stat">' + escapeHtml(att) + '</span>'
                + '<span class="tt-pd-team-player-stat">' + escapeHtml(rat) + '</span>'
                + '</div>';
        }).join('');
        body.innerHTML = rows;
    }

    function escapeHtml(s) {
        var div = document.createElement('div');
        div.textContent = String(s);
        return div.innerHTML;
    }

    // Restore persisted expand-state on load.
    document.addEventListener('DOMContentLoaded', function () {
        var cards = document.querySelectorAll('.tt-pd-team-card');
        Array.prototype.forEach.call(cards, function (card) {
            var teamId = card.getAttribute('data-tt-team-id');
            if (!teamId) return;
            var stored;
            try { stored = window.localStorage.getItem('tt_pd_team_card_' + teamId); } catch (e) { stored = null; }
            if (stored === '1') {
                var toggleEl = card.querySelector('[data-tt-team-toggle="' + teamId + '"]');
                if (toggleEl) handleTeamCardToggle(toggleEl);
            }
        });
    });
})();
