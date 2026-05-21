/*
 * frontend-match-prep.js — #838 head coach match preparation form.
 *
 * - Slot selects per (half, player): two players cannot share the same
 *   slot in the same half. JS clears the previous occupant when a
 *   conflicting slot is picked.
 * - Minutes auto-compute: half_length when the player is in any slot
 *   that half, 0 otherwise.
 * - Copy 1e → 2e button duplicates the first half's slot map.
 * - Save POSTs the full state via PUT /talenttrack/v1/match-prep/<id>.
 *
 * Stays in vanilla JS per CLAUDE.md §2.
 */
(function () {
    'use strict';

    var form = document.getElementById('tt-match-prep-form');
    if (!form) return;

    var cfg = window.TT_MATCH_PREP || {};
    var activityId = parseInt(form.getAttribute('data-activity-id'), 10) || 0;
    var halfLength = parseInt(form.getAttribute('data-half-length'), 10) || 35;
    var msg = form.querySelector('[data-tt-mp-msg]');

    var halfLengthInput = form.querySelector('input[name="half_length_minutes"]');
    if (halfLengthInput) {
        halfLengthInput.addEventListener('input', function () {
            var v = parseInt(halfLengthInput.value, 10);
            if (v > 0) {
                halfLength = v;
                form.setAttribute('data-half-length', String(v));
                recomputeAll();
            }
        });
    }

    // Slot uniqueness: if a player picks a slot already used by another
    // player in the same half, clear the other player's slot.
    form.addEventListener('change', function (e) {
        var t = e.target;
        if (!t || !t.classList || !t.classList.contains('tt-mp-slot-select')) return;
        var half = t.getAttribute('data-tt-mp-slot');
        var val = t.value;
        if (val !== '') {
            var siblings = form.querySelectorAll('[data-tt-mp-slot="' + half + '"]');
            siblings.forEach(function (s) {
                if (s !== t && s.value === val) {
                    s.value = '';
                }
            });
        }
        recomputeAll();
    });

    // Copy 1e → 2e
    var copyBtn = form.querySelector('[data-tt-mp-copy-half]');
    if (copyBtn) {
        copyBtn.addEventListener('click', function () {
            var rows = form.querySelectorAll('tbody tr[data-player-id]');
            rows.forEach(function (row) {
                var s1 = row.querySelector('[data-tt-mp-slot="1"]');
                var s2 = row.querySelector('[data-tt-mp-slot="2"]');
                if (s1 && s2) s2.value = s1.value;
            });
            recomputeAll();
        });
    }

    // Manage-availability button is a stub in v1 — opens the wizard for
    // the same activity in a new tab. v2 will swap for an in-place modal.
    var manageBtn = form.querySelector('[data-tt-mp-manage-availability]');
    if (manageBtn) {
        manageBtn.addEventListener('click', function () {
            var u = new URL(window.location.href);
            u.searchParams.set('tt_view', 'wizard');
            u.searchParams.set('slug', 'match-prep');
            u.searchParams.set('activity_id', String(activityId));
            window.open(u.toString(), '_blank');
        });
    }

    function recomputeAll() {
        var rows = form.querySelectorAll('tbody tr[data-player-id]');
        var sum1 = 0, sum2 = 0;
        var slotsUsed1 = {}, slotsUsed2 = {};
        rows.forEach(function (row) {
            var s1 = row.querySelector('[data-tt-mp-slot="1"]');
            var s2 = row.querySelector('[data-tt-mp-slot="2"]');
            var v1 = s1 ? s1.value : '';
            var v2 = s2 ? s2.value : '';
            var min1 = v1 !== '' ? halfLength : 0;
            var min2 = v2 !== '' ? halfLength : 0;
            row.querySelector('[data-tt-mp-min="1"]').textContent = String(min1);
            row.querySelector('[data-tt-mp-min="2"]').textContent = String(min2);
            row.querySelector('[data-tt-mp-min="tot"]').textContent = String(min1 + min2);
            sum1 += min1;
            sum2 += min2;
            if (s1) s1.classList.toggle('tt-mp-on-pitch', v1 !== '');
            if (s2) s2.classList.toggle('tt-mp-on-pitch', v2 !== '');
            if (v1 !== '') slotsUsed1[v1] = (slotsUsed1[v1] || 0) + 1;
            if (v2 !== '') slotsUsed2[v2] = (slotsUsed2[v2] || 0) + 1;
        });
        form.querySelector('[data-tt-mp-total="1"]').textContent = String(sum1);
        form.querySelector('[data-tt-mp-total="2"]').textContent = String(sum2);
        form.querySelector('[data-tt-mp-total="tot"]').textContent = String(sum1 + sum2);

        var picked1 = Object.keys(slotsUsed1).length;
        var picked2 = Object.keys(slotsUsed2).length;
        var validity = form.querySelector('[data-tt-mp-validity]');
        if (validity) {
            if (picked1 === 11 && picked2 === 11) {
                validity.textContent = '✓ 11+11';
                validity.style.color = '#2c8a2c';
            } else {
                validity.textContent = picked1 + ' + ' + picked2 + ' / 11+11';
                validity.style.color = '#c9962a';
            }
        }
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        save();
    });

    function save() {
        if (msg) { msg.textContent = (cfg.i18n && cfg.i18n.saving) || 'Saving…'; msg.style.color = '#5b6e75'; }
        var payload = {
            half_length_minutes: parseInt((form.querySelector('input[name="half_length_minutes"]') || {}).value || halfLength, 10),
            formation_template_id: parseInt((form.querySelector('input[name="formation_template_id"]') || {}).value || '0', 10) || null,
            goals_general: (form.querySelector('textarea[name="goals_general"]') || {}).value || '',
            goals_attack: (form.querySelector('textarea[name="goals_attack"]') || {}).value || '',
            goals_defend: (form.querySelector('textarea[name="goals_defend"]') || {}).value || '',
            goals_attack_setpiece: (form.querySelector('textarea[name="goals_attack_setpiece"]') || {}).value || '',
            goals_defend_setpiece: (form.querySelector('textarea[name="goals_defend_setpiece"]') || {}).value || '',
            lineup: { '1': {}, '2': {} },
            player_goals: {}
        };
        var rows = form.querySelectorAll('tbody tr[data-player-id]');
        rows.forEach(function (row) {
            var pid = row.getAttribute('data-player-id');
            var s1 = (row.querySelector('[data-tt-mp-slot="1"]') || {}).value || '';
            var s2 = (row.querySelector('[data-tt-mp-slot="2"]') || {}).value || '';
            if (s1) payload.lineup['1'][s1] = parseInt(pid, 10);
            if (s2) payload.lineup['2'][s2] = parseInt(pid, 10);
            var attentionEl = row.querySelector('input[name="player_goals[' + pid + '][attention_text]"]');
            var specEl = row.querySelector('input[name="player_goals[' + pid + '][is_specific_goal]"]');
            var anaEl = row.querySelector('input[name="player_goals[' + pid + '][analyst_appointed]"]');
            payload.player_goals[pid] = {
                attention_text: attentionEl ? attentionEl.value : '',
                is_specific_goal: !!(specEl && specEl.checked),
                analyst_appointed: !!(anaEl && anaEl.checked)
            };
        });

        var url = (cfg.rest_url || '/wp-json/talenttrack/v1/match-prep/') + activityId;
        fetch(url, {
            method: 'PUT',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-WP-Nonce': cfg.rest_nonce || ''
            },
            body: JSON.stringify(payload)
        }).then(function (r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        }).then(function () {
            if (msg) {
                msg.textContent = (cfg.i18n && cfg.i18n.saved) || 'Saved.';
                msg.style.color = '#2c8a2c';
            }
        }).catch(function () {
            if (msg) {
                msg.textContent = (cfg.i18n && cfg.i18n.error) || 'Save failed.';
                msg.style.color = '#b32d2e';
            }
        });
    }

    recomputeAll();
})();
