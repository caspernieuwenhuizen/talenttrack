/*
 * frontend-match-execution.js — #847 assistant coach live-match
 * surface. Mobile-first, runs on a phone on the sideline.
 *
 * State machine: not_started → first_half → half_time → second_half →
 * finished. Sticky bottom button label and behaviour switches by state.
 *
 * Network model: online-first. Every action POSTs to REST immediately.
 * Failures are queued in localStorage with a client-generated UUID;
 * the queue flushes on the next successful response. Endpoints are
 * idempotent on event_uuid so a double-flush does not double-insert.
 */
(function () {
    'use strict';

    var root = document.querySelector('.tt-mexec');
    if (!root) return;
    var bootstrap = (function () {
        var el = document.getElementById('tt-mexec-bootstrap');
        if (!el) return {};
        try { return JSON.parse(el.textContent || '{}'); } catch (e) { return {}; }
    })();
    var cfg = window.TT_MATCH_EXECUTION || {};
    var i18n = cfg.i18n || {};
    // #1473 — the match can only be started on match day. The server
    // enforces this too; this keeps the UI honest (disabled CTA + timer,
    // dated tooltip). Defaults to allowed when the flag is absent.
    var IS_MATCH_DAY = bootstrap.is_match_day !== false;
    var START_LOCK_MSG = bootstrap.start_lock_msg || '';
    var ACTIVITY_ID = parseInt(cfg.activity_id, 10) || 0;
    var HALF_LENGTH = parseInt(bootstrap.half_length, 10) || 35;

    // --- Local state ---
    var state = {
        state: bootstrap.state || 'not_started',
        home_score: parseInt(bootstrap.home_score, 10) || 0,
        away_score: parseInt(bootstrap.away_score, 10) || 0,
        // On-pitch starts as starting XI of half 1. Subs mutate it.
        on_pitch: (bootstrap.starting_xi_half1 || []).slice(),
        bench: (bootstrap.bench || []).slice(),
        players_by_id: indexBy(bootstrap.players || [], 'id'),
        // Timer
        half: 1,                    // current half (1 or 2)
        running: false,
        clock_start_ms: 0,          // wall-clock when the current uninterrupted run began
        elapsed_ms_before_pause: 0, // accumulated elapsed within the current half (excl. pauses)
        timer_interval: null,
        // Goal counts: pid => int
        goal_counts: {},
        // Pending offline queue
        queue_key: 'tt_match_exec_queue_' + ACTIVITY_ID
    };

    // --- Element refs ---
    var els = {
        homeScore:  root.querySelector('[data-tt-mexec-home-score]'),
        awayScore:  root.querySelector('[data-tt-mexec-away-score]'),
        halfLabel:  root.querySelector('[data-tt-mexec-half-label]'),
        clock:      root.querySelector('[data-tt-mexec-clock]'),
        timerBtn:   root.querySelector('[data-tt-mexec-timer-toggle]'),
        stateBtn:   root.querySelector('[data-tt-mexec-state-action]'),
        status:     root.querySelector('[data-tt-mexec-status]'),
        benchList:  root.querySelector('.tt-mexec-bench .tt-mexec-player-list'),
        onPitchSection: root.querySelector('[data-tt-mexec-onpitch-section]'),
        onPitchList: root.querySelector('[data-tt-mexec-onpitch-list]')
    };

    // --- Boot ---
    renderStateButton();
    renderHalfLabel();
    renderClock();
    renderOnPitchList();
    flushQueue();
    window.addEventListener('online', flushQueue);

    // --- Score steppers ---
    root.querySelectorAll('[data-tt-mexec-score]').forEach(function (b) {
        b.addEventListener('click', function () {
            var which = b.getAttribute('data-tt-mexec-score');
            var delta = parseInt(b.getAttribute('data-tt-mexec-delta'), 10) || 0;
            if (which === 'home') state.home_score = clamp(state.home_score + delta, 0, 99);
            else state.away_score = clamp(state.away_score + delta, 0, 99);
            renderScore();
            api('score', { home: state.home_score, away: state.away_score });
        });
    });

    // --- Timer toggle ---
    els.timerBtn.addEventListener('click', function () {
        if (state.state === 'finished') return;
        if (!state.running) {
            // Starting the timer for the current half.
            if (state.state === 'not_started') {
                // #1473 — block the start before match day.
                if (!IS_MATCH_DAY) return;
                state.state = 'first_half'; state.half = 1;
                api('start-half', { half: 1 });
                renderStateButton(); renderHalfLabel();
            } else if (state.state === 'half_time') {
                state.state = 'second_half'; state.half = 2;
                state.elapsed_ms_before_pause = 0;
                api('start-half', { half: 2 });
                renderStateButton(); renderHalfLabel();
            }
            state.running = true;
            state.clock_start_ms = Date.now();
            state.timer_interval = setInterval(renderClock, 1000);
            // renderStateButton() also (re)syncs the timer btn label +
            // data-action so #956's colour coding stays in sync.
            renderStateButton(); renderHalfLabel();
        } else {
            // Pause: snapshot elapsed; tell server we paused.
            state.elapsed_ms_before_pause += Date.now() - state.clock_start_ms;
            state.running = false;
            clearInterval(state.timer_interval);
            api('pause', { half: state.half });
            renderStateButton(); renderHalfLabel();
        }
    });

    // --- Sticky bottom action (half transitions) ---
    els.stateBtn.addEventListener('click', function () {
        if (state.state === 'not_started') {
            // #1473 — block the start before match day.
            if (!IS_MATCH_DAY) return;
            // Footer CTA shortcut for "Start match" — same effect as the
            // timer Start button. v4.3.19 (#956) maps this footer state
            // explicitly per the spec table.
            els.timerBtn.click();
        } else if (state.state === 'first_half') {
            api('end-half', { half: 1 });
            state.state = 'half_time';
            state.running = false;
            clearInterval(state.timer_interval);
            renderStateButton(); renderHalfLabel();
        } else if (state.state === 'half_time') {
            // Footer CTA shortcut for "Start second half" — same effect
            // as the timer Start button.
            els.timerBtn.click();
        } else if (state.state === 'second_half') {
            api('end-half', { half: 2 });
            state.state = 'finished';
            state.running = false;
            clearInterval(state.timer_interval);
            api('finish', {});
            renderStateButton(); renderHalfLabel();
        } else if (state.state === 'finished') {
            // v4.3.19 (#956) — "Return to dashboard" CTA on a finished
            // match navigates the coach back to the activity's detail
            // page (or the dashboard root if no referrer is available).
            window.location.href = document.referrer || (window.location.pathname || '/');
        }
    });

    // --- Goal counters (tap = +1, long-press = -1) ---
    root.querySelectorAll('[data-tt-mexec-goal-inc]').forEach(function (btn) {
        var pressTimer = null;
        var longPressed = false;
        var row = btn.closest('[data-tt-mexec-goal-row]');
        var pid = parseInt(row.getAttribute('data-player-id'), 10);

        // #956 — count chip renders inside `.tt-mexec-goal-chip > strong`
        // (was inline on the button label). Button text stays "+ action".
        var chipCountEl = row.querySelector('[data-tt-mexec-goal-count]');
        function renderChip() {
            if (chipCountEl) chipCountEl.textContent = String(state.goal_counts[pid] || 0);
        }

        btn.addEventListener('pointerdown', function () {
            longPressed = false;
            pressTimer = setTimeout(function () {
                longPressed = true;
                var pending = (state.recent_goals && state.recent_goals[pid]) || [];
                var last = pending.pop();
                if (last) {
                    state.goal_counts[pid] = Math.max(0, (state.goal_counts[pid] || 0) - 1);
                    renderChip();
                    apiDelete('goal-event/' + last);
                }
            }, 600);
        });
        btn.addEventListener('pointerup', function () {
            clearTimeout(pressTimer);
            if (longPressed) return;
            var uuid = uuidv4();
            state.goal_counts[pid] = (state.goal_counts[pid] || 0) + 1;
            renderChip();
            state.recent_goals = state.recent_goals || {};
            state.recent_goals[pid] = state.recent_goals[pid] || [];
            state.recent_goals[pid].push(uuid);
            api('goal-event', {
                event_uuid: uuid,
                player_id: pid,
                half: state.half,
                minute: currentMinute()
            });
        });
        btn.addEventListener('pointerleave', function () { clearTimeout(pressTimer); });
    });

    // --- Substitution flow ---
    root.querySelectorAll('[data-tt-mexec-sub-on]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var li = btn.closest('[data-tt-mexec-bench]');
            var pid_on = parseInt(li.getAttribute('data-player-id'), 10);
            if (!pid_on) return;
            openSubSheet(pid_on);
        });
    });

    // #956 — inline sub-target reveal (replaces the v4.1.7 modal sheet).
    // Populates the .tt-mexec-sub-target section below the bench with
    // the full on-pitch XI; coach taps a row to complete the swap.
    var pendingSubOn = null;
    var subBannerEl = root.querySelector('[data-tt-mexec-sub-banner]');
    var subCancelEl = root.querySelector('[data-tt-mexec-sub-cancel]');

    function openSubSheet(pid_on) {
        var pl_on = state.players_by_id[pid_on];
        if (!pl_on) return;
        pendingSubOn = pid_on;
        if (subBannerEl) {
            subBannerEl.textContent = (i18n.sub_label_format || 'Tap a player to swap in %s')
                .replace('%s', pl_on.name);
        }
        renderOnPitchList();
        root.setAttribute('data-swap-mode', 'true');
        // Bring the sub-target into view (the bench is above it on the
        // page; the coach just tapped a bench → on button so the bench
        // is in view; scrolling reveals the sub-target below).
        var target = root.querySelector('.tt-mexec-sub-target');
        if (target && typeof target.scrollIntoView === 'function') {
            target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }

    function closeSubSheet() {
        pendingSubOn = null;
        root.setAttribute('data-swap-mode', 'false');
    }

    if (subCancelEl) {
        subCancelEl.addEventListener('click', closeSubSheet);
    }

    function commitSub(pid_on, pid_off) {
        // Move on_pitch <-> bench.
        var idx = state.on_pitch.indexOf(pid_off);
        if (idx >= 0) {
            state.on_pitch.splice(idx, 1, pid_on);
        }
        var b_idx = state.bench.indexOf(pid_on);
        if (b_idx >= 0) state.bench.splice(b_idx, 1);
        state.bench.push(pid_off);

        renderBenchAndOnPitch();
        var uuid = uuidv4();
        var minute = currentMinute();
        api('substitution', {
            event_uuid: uuid,
            half: state.half,
            minute: minute,
            player_off: pid_off,
            player_on: pid_on
        });
        toast((i18n.sub_toast_format || '✓ %1$s on for %2$s · %3$s\'').replace('%1$s', name(pid_on)).replace('%2$s', name(pid_off)).replace('%3$s', minute));
    }

    // --- Renderers ---
    function renderScore() {
        if (els.homeScore) els.homeScore.textContent = String(state.home_score);
        if (els.awayScore) els.awayScore.textContent = String(state.away_score);
    }
    function renderHalfLabel() {
        if (!els.halfLabel) return;
        var label;
        var status = '';
        if (state.state === 'first_half') {
            label = i18n.half_label_first || 'First half';
            status = state.running ? 'live' : '';
        } else if (state.state === 'half_time') {
            label = i18n.half_label_break || 'Half time';
        } else if (state.state === 'second_half') {
            label = i18n.half_label_second || 'Second half';
            status = state.running ? 'live' : '';
        } else if (state.state === 'finished') {
            label = i18n.half_label_final || 'Final';
        } else {
            label = i18n.half_label_pending || 'Kickoff pending';
        }
        els.halfLabel.textContent = label;
        els.halfLabel.setAttribute('data-status', status);
    }
    function renderClock() {
        if (!els.clock) return;
        var ms = state.elapsed_ms_before_pause;
        if (state.running) ms += Date.now() - state.clock_start_ms;
        var seconds = Math.floor(ms / 1000);
        var mm = Math.floor(seconds / 60);
        var ss = seconds % 60;
        els.clock.textContent = pad2(mm) + ':' + pad2(ss);
    }
    function renderStateButton() {
        if (!els.stateBtn) return;
        // #956 — state→CTA mapping per the spec table. data-action also
        // drives the CSS colour-coding on the footer CTA.
        if (state.state === 'first_half') {
            els.stateBtn.textContent = i18n.end_first_half || 'End first half';
            els.stateBtn.setAttribute('data-action', 'end-first-half');
            els.stateBtn.disabled = false;
        } else if (state.state === 'half_time') {
            els.stateBtn.textContent = i18n.start_second_half || 'Start second half';
            els.stateBtn.setAttribute('data-action', 'start-second-half');
            els.stateBtn.disabled = false;
        } else if (state.state === 'second_half') {
            els.stateBtn.textContent = i18n.end_match || 'End match';
            els.stateBtn.setAttribute('data-action', 'end-match');
            els.stateBtn.disabled = false;
        } else if (state.state === 'finished') {
            els.stateBtn.textContent = i18n.match_finished || 'Return to dashboard';
            els.stateBtn.setAttribute('data-action', 'done');
            els.stateBtn.disabled = false;
        } else {
            els.stateBtn.textContent = i18n.start_match || 'Start match';
            els.stateBtn.setAttribute('data-action', 'start-match');
            // #1473 — keep Start disabled until match day.
            els.stateBtn.disabled = !IS_MATCH_DAY;
            if (!IS_MATCH_DAY && START_LOCK_MSG) els.stateBtn.title = START_LOCK_MSG;
        }
        // Also sync the parent <div class="tt-mexec"> data-state attr so
        // CSS state-driven visibility rules apply.
        root.setAttribute('data-state', state.state);
        if (els.timerBtn) {
            // Timer button label + data-action drive its colour.
            if (state.state === 'not_started') {
                els.timerBtn.textContent = i18n.start || 'Start';
                els.timerBtn.setAttribute('data-action', 'start');
                // #1473 — keep the timer Start disabled until match day.
                els.timerBtn.disabled = !IS_MATCH_DAY;
                if (!IS_MATCH_DAY && START_LOCK_MSG) els.timerBtn.title = START_LOCK_MSG;
            } else if (state.running) {
                els.timerBtn.textContent = i18n.pause || 'Pause';
                els.timerBtn.setAttribute('data-action', 'pause');
            } else {
                els.timerBtn.textContent = i18n.resume || 'Resume';
                els.timerBtn.setAttribute('data-action', 'resume');
            }
        }
    }
    function renderBenchAndOnPitch() {
        if (els.benchList) {
            els.benchList.innerHTML = '';
            state.bench.forEach(function (pid) {
                var pl = state.players_by_id[pid];
                if (!pl) return;
                var li = document.createElement('li');
                li.className = 'tt-mexec-player';
                li.setAttribute('data-tt-mexec-bench', '');
                li.setAttribute('data-player-id', String(pid));
                var jersey = pl.jersey != null ? String(pl.jersey) : '';
                li.innerHTML =
                    '<span class="tt-mexec-player-number">' + escapeHtml(jersey) + '</span>' +
                    '<span class="tt-mexec-player-name">' + escapeHtml(pl.name) + '</span>' +
                    '<div class="tt-mexec-player-actions">' +
                        '<button type="button" class="tt-mexec-action-btn tt-mexec-action-btn--sub-on" data-tt-mexec-sub-on aria-label="Bring on">' +
                            escapeHtml('→ on') +
                        '</button>' +
                    '</div>';
                li.querySelector('[data-tt-mexec-sub-on]').addEventListener('click', function () {
                    openSubSheet(pid);
                });
                els.benchList.appendChild(li);
            });
        }
        renderOnPitchList();
    }
    function renderOnPitchList() {
        if (!els.onPitchList) return;
        els.onPitchList.innerHTML = '';
        state.on_pitch.forEach(function (pid_off) {
            var pl = state.players_by_id[pid_off];
            if (!pl) return;
            var li = document.createElement('li');
            li.className = 'tt-mexec-player';
            li.setAttribute('data-player-id', String(pid_off));
            var jersey = pl.jersey != null ? String(pl.jersey) : '';
            li.innerHTML =
                '<span class="tt-mexec-player-number">' + escapeHtml(jersey) + '</span>' +
                '<span class="tt-mexec-player-name">' + escapeHtml(pl.name) + '</span>';
            li.addEventListener('click', function () {
                if (pendingSubOn != null) {
                    var pid_on = pendingSubOn;
                    closeSubSheet();
                    commitSub(pid_on, pid_off);
                }
            });
            els.onPitchList.appendChild(li);
        });
    }

    // --- Network with offline queue ---
    function api(action, body) {
        var url = (cfg.rest_url || '/wp-json/talenttrack/v1/match-execution/0/') + action;
        return doFetch(url, 'POST', body).catch(function () {
            enqueue({ url: url, method: 'POST', body: body });
        });
    }
    function apiDelete(path) {
        var url = (cfg.rest_url || '/wp-json/talenttrack/v1/match-execution/0/') + path;
        return doFetch(url, 'DELETE', null).catch(function () {
            enqueue({ url: url, method: 'DELETE', body: null });
        });
    }
    function doFetch(url, method, body) {
        return fetch(url, {
            method: method,
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-WP-Nonce': cfg.rest_nonce || ''
            },
            body: body ? JSON.stringify(body) : undefined
        }).then(function (r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            updateConnectionStatus(true);
            return r.json();
        });
    }
    function enqueue(req) {
        try {
            var raw = localStorage.getItem(state.queue_key);
            var q = raw ? JSON.parse(raw) : [];
            q.push(req);
            localStorage.setItem(state.queue_key, JSON.stringify(q));
            updateConnectionStatus(false, q.length);
        } catch (e) { /* localStorage unavailable */ }
    }
    function flushQueue() {
        var raw;
        try { raw = localStorage.getItem(state.queue_key); } catch (e) { return; }
        if (!raw) return;
        var q;
        try { q = JSON.parse(raw); } catch (e) { return; }
        if (!Array.isArray(q) || q.length === 0) return;
        var next = q.shift();
        doFetch(next.url, next.method, next.body).then(function () {
            try { localStorage.setItem(state.queue_key, JSON.stringify(q)); } catch (e) {}
            if (q.length > 0) flushQueue();
            else updateConnectionStatus(true);
        }).catch(function () {
            // Put the failed item back at the front; try again later.
            q.unshift(next);
            try { localStorage.setItem(state.queue_key, JSON.stringify(q)); } catch (e) {}
        });
    }
    function updateConnectionStatus(ok, pending) {
        if (!els.status) return;
        var textEl = els.status.querySelector('[data-tt-mexec-status-text]') || els.status;
        if (ok) {
            els.status.setAttribute('data-state', 'online');
            textEl.textContent = i18n.connection_back || 'Synced';
        } else {
            els.status.setAttribute('data-state', 'offline');
            var n = pending != null ? pending : 1;
            textEl.textContent = (i18n.queue_pending || 'Offline — actions queued') + ' (' + n + ')';
        }
    }

    // --- Utils ---
    function clamp(v, lo, hi) { return Math.max(lo, Math.min(hi, v)); }
    function pad2(n) { return (n < 10 ? '0' : '') + n; }
    function name(pid) { var pl = state.players_by_id[pid]; return pl ? pl.name : ('#' + pid); }
    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function uuidv4() {
        // RFC 4122 v4-ish UUID; good enough for idempotency keys.
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
            var r = Math.random() * 16 | 0;
            return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
        });
    }
    function indexBy(arr, key) {
        var out = {};
        (arr || []).forEach(function (item) { out[item[key]] = item; });
        return out;
    }
    function currentMinute() {
        var ms = state.elapsed_ms_before_pause;
        if (state.running) ms += Date.now() - state.clock_start_ms;
        return Math.floor(ms / 60000);
    }
    function toast(text) {
        var el = document.createElement('div');
        el.className = 'tt-mexec-toast';
        el.textContent = text;
        document.body.appendChild(el);
        setTimeout(function () { if (el.parentNode) el.parentNode.removeChild(el); }, 3000);
    }
})();
