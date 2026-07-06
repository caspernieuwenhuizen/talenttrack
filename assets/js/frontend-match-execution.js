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
    // #2267 — the canonical MatchExecutionState values, exported from the
    // PHP enum via the bootstrap config. The state machine compares against
    // these instead of the legacy hardcoded 'finished' literal (which no
    // longer exists in storage). Defaults keep the JS honest if the config
    // is ever absent.
    var S = cfg.states || {};
    var ST = {
        NOT_STARTED:    S.not_started    || 'not_started',
        FIRST_HALF:     S.first_half     || 'first_half',
        HALF_TIME:      S.half_time      || 'half_time',
        SECOND_HALF:    S.second_half    || 'second_half',
        PENDING_REVIEW: S.pending_review || 'pending_review',
        FINALIZED:      S.finalized      || 'finalized'
    };
    // Post-match, terminal-for-the-timer states: the clock is parked and
    // the half-transition CTA no longer runs the live flow.
    function isPostMatch(s) {
        return s === ST.PENDING_REVIEW || s === ST.FINALIZED;
    }
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
    // #2267 — if we boot straight into a post-match state (a reload of a
    // finished / finalized match), the timer must never start ticking.
    // Nothing here starts an interval; renderClock() paints the frozen
    // 00:00 (no persisted elapsed on a fresh load) and stopTimer() is a
    // no-op guard so a stray running flag can't leak a live clock.
    if (isPostMatch(state.state)) stopTimer();
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
        // #2267 — the timer is inert once the match is post-match
        // (pending_review / finalized). The legacy guard only checked a
        // 'finished' value the server never emits, so the clock kept
        // ticking; compare against the real terminal states now.
        if (isPostMatch(state.state)) return;
        if (!state.running) {
            // Starting the timer for the current half.
            if (state.state === ST.NOT_STARTED) {
                // #1473 — block the start before match day.
                if (!IS_MATCH_DAY) return;
                state.state = ST.FIRST_HALF; state.half = 1;
                api('start-half', { half: 1 });
                renderStateButton(); renderHalfLabel();
            } else if (state.state === ST.HALF_TIME) {
                state.state = ST.SECOND_HALF; state.half = 2;
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
            stopTimer();
            api('pause', { half: state.half });
            renderStateButton(); renderHalfLabel();
        }
    });

    // --- Sticky bottom action (half transitions) ---
    els.stateBtn.addEventListener('click', function () {
        if (state.state === ST.NOT_STARTED) {
            // #1473 — block the start before match day.
            if (!IS_MATCH_DAY) return;
            // Footer CTA shortcut for "Start match" — same effect as the
            // timer Start button. v4.3.19 (#956) maps this footer state
            // explicitly per the spec table.
            els.timerBtn.click();
        } else if (state.state === ST.FIRST_HALF) {
            api('end-half', { half: 1 });
            state.state = ST.HALF_TIME;
            stopTimer();
            renderStateButton(); renderHalfLabel();
        } else if (state.state === ST.HALF_TIME) {
            // Footer CTA shortcut for "Start second half" — same effect
            // as the timer Start button.
            els.timerBtn.click();
        } else if (state.state === ST.SECOND_HALF) {
            // #2267 — end the match: stop the clock, then finish() and
            // adopt the server's returned state (pending_review). The
            // legacy code hardcoded 'finished' and never read the response,
            // so a reload disagreed with the server. The state lives in the
            // REST success envelope's `data` object (r.data.state).
            api('end-half', { half: 2 });
            stopTimer();
            api('finish', {}).then(function (r) {
                var srvState = (r && r.data && r.data.state) || (r && r.state);
                state.state = srvState || ST.PENDING_REVIEW;
                stopTimer();
                renderStateButton(); renderHalfLabel();
                // A reload surfaces the pending-review affordances (late
                // events, finalize) the server-rendered view carries.
                window.location.reload();
            });
            state.state = ST.PENDING_REVIEW;
            renderStateButton(); renderHalfLabel();
        } else if (state.state === ST.PENDING_REVIEW) {
            // #2267 — "Review match": scroll to the post-match review
            // panel (finalize + late events) rather than navigate away.
            var panel = root.querySelector('.tt-mexec-post-match');
            if (panel && typeof panel.scrollIntoView === 'function') {
                panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        } else if (state.state === ST.FINALIZED) {
            // #2271 — a finalized match is never a dead-end: the footer CTA
            // re-opens it for corrections (server transitions it back to
            // pending_review, then reload surfaces the edit affordances).
            reopenForCorrections();
        }
    });

    // #2267 — single chokepoint for parking the timer. Clears the tick
    // interval and drops the running flag so no code path can leave a
    // live clock behind on a state transition.
    function stopTimer() {
        state.running = false;
        if (state.timer_interval) {
            clearInterval(state.timer_interval);
            state.timer_interval = null;
        }
    }

    // #2271 — re-open a finalized match. Cap-gated + audited server-side;
    // on success the server returns to pending_review and we reload so the
    // full review-&-edit surface (score steppers, subs, goals, minutes,
    // late events) comes back.
    function reopenForCorrections() {
        if (!window.confirm(i18n.reopen_confirm || 'Re-open this finalized match for corrections?')) return;
        if (els.stateBtn) els.stateBtn.disabled = true;
        doFetch((cfg.rest_url || '') + 'reopen', 'POST', {}).then(function () {
            window.location.reload();
        }).catch(function () {
            if (els.stateBtn) els.stateBtn.disabled = false;
            window.alert(i18n.reopen_error || 'Could not re-open the match:');
        });
    }

    // --- Tracked development-action counters (tap = +1, long-press = -1) ---
    // Rebuild — these log tracked-events (per-player development actions),
    // NOT goal-events. Tracked actions are distinct from the score; the
    // action label comes from the prep flag (data-action-label). Counts are
    // seeded server-side and persist across reload.
    state.tracked_counts = {};
    root.querySelectorAll('[data-tt-mexec-tracked-inc]').forEach(function (btn) {
        var pressTimer = null;
        var longPressed = false;
        var row = btn.closest('[data-tt-mexec-tracked-row]');
        var pid = parseInt(row.getAttribute('data-player-id'), 10);
        var actionLabel = row.getAttribute('data-action-label') || '';

        var chipCountEl = row.querySelector('[data-tt-mexec-tracked-count]');
        // Seed from the server-rendered count so a reload keeps the tally.
        state.tracked_counts[pid] = parseInt(chipCountEl && chipCountEl.textContent, 10) || 0;
        function renderChip() {
            if (chipCountEl) chipCountEl.textContent = String(state.tracked_counts[pid] || 0);
        }

        btn.addEventListener('pointerdown', function () {
            longPressed = false;
            pressTimer = setTimeout(function () {
                longPressed = true;
                var pending = (state.recent_tracked && state.recent_tracked[pid]) || [];
                var last = pending.pop();
                if (last) {
                    state.tracked_counts[pid] = Math.max(0, (state.tracked_counts[pid] || 0) - 1);
                    renderChip();
                    // Roll back the optimistic decrement + uuid stack if the
                    // DELETE is rejected outright (a real HTTP error, not a
                    // queued offline retry).
                    apiDelete('tracked-event/' + last).catch(function () {
                        state.tracked_counts[pid] = (state.tracked_counts[pid] || 0) + 1;
                        renderChip();
                        pending.push(last);
                    });
                }
            }, 600);
        });
        btn.addEventListener('pointerup', function () {
            clearTimeout(pressTimer);
            if (longPressed) return;
            var uuid = uuidv4();
            state.tracked_counts[pid] = (state.tracked_counts[pid] || 0) + 1;
            renderChip();
            state.recent_tracked = state.recent_tracked || {};
            state.recent_tracked[pid] = state.recent_tracked[pid] || [];
            state.recent_tracked[pid].push(uuid);
            api('tracked-event', {
                event_uuid: uuid,
                player_id: pid,
                half: state.half,
                minute: currentMinute(),
                action_label: actionLabel
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

    // #2270 item 3 — stop the clock the instant Finalize is tapped, before
    // the network round-trip + reload. The finalize handler itself lives in
    // the view's inline script (it owns the confirm + POST); the timer lives
    // here, so parking it on the same click keeps the clock from visibly
    // ticking during the request. Runs in the capture phase so it lands
    // before the inline handler's confirm dialog blocks the thread.
    (function wireFinalizeStopsClock() {
        var fb = root.querySelector('[data-tt-mexec-finalize]');
        if (!fb) return;
        fb.addEventListener('click', function () { stopTimer(); }, true);
    })();

    // #2269 — reload-safe Undo on each logged goal/sub chip in the event
    // feed. Keyed by the server event id (data-event-uuid), so undo works
    // after a reload — no reliance on the live long-press UUID memory.
    // Goals hit DELETE goal-event/<uuid>; subs hit DELETE substitution/<uuid>.
    root.querySelectorAll('[data-tt-mexec-undo]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var uuid = btn.getAttribute('data-event-uuid');
            var kind = btn.getAttribute('data-tt-mexec-undo');
            if (!uuid) return;
            if (!window.confirm(i18n.undo_confirm || 'Undo this event?')) return;
            btn.disabled = true;
            var path = (kind === 'goal' ? 'goal-event/' : 'substitution/') + uuid;
            apiDelete(path).then(function () {
                window.location.reload();
            }).catch(function () {
                btn.disabled = false;
            });
        });
    });

    // #2273 — correct a logged substitution's minute post-match. The coach
    // often logs a sub late; because minutes derive from the sub time, PATCH
    // the corrected minute and reload so the recomputed minutes show. Reload
    // keeps the derived timeline / minutes in sync with the server truth.
    root.querySelectorAll('[data-tt-mexec-sub-minute]').forEach(function (box) {
        var uuid  = box.getAttribute('data-event-uuid');
        var half  = parseInt(box.getAttribute('data-half'), 10) || 1;
        var input = box.querySelector('[data-tt-mexec-sub-minute-input]');
        if (!uuid || !input) return;
        var max = parseInt(input.getAttribute('max'), 10) || (HALF_LENGTH + 10);
        function commit(v) {
            v = Math.max(0, Math.min(max, isNaN(v) ? 0 : v));
            if (v === (parseInt(input.getAttribute('value'), 10) || 0)) { input.value = v; return; }
            input.value = v;
            input.disabled = true;
            apiPatch('substitution/' + uuid, { half: half, minute: v }).then(function () {
                window.location.reload();
            }).catch(function () {
                input.disabled = false;
            });
        }
        var dec = box.querySelector('[data-tt-mexec-sub-minute-dec]');
        var inc = box.querySelector('[data-tt-mexec-sub-minute-inc]');
        if (dec) dec.addEventListener('click', function () { commit((parseInt(input.value, 10) || 0) - 1); });
        if (inc) inc.addEventListener('click', function () { commit((parseInt(input.value, 10) || 0) + 1); });
        input.addEventListener('change', function () { commit(parseInt(input.value, 10)); });
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

    // #2273 — the "just came off" pill lives for one minute, then the bench
    // row reverts to its normal state. Mirrors the mockup's OFF_PILL_MS.
    var OFF_PILL_MS = 60000;

    function commitSub(pid_on, pid_off) {
        // Move on_pitch <-> bench.
        var idx = state.on_pitch.indexOf(pid_off);
        if (idx >= 0) {
            state.on_pitch.splice(idx, 1, pid_on);
        }
        var b_idx = state.bench.indexOf(pid_on);
        if (b_idx >= 0) state.bench.splice(b_idx, 1);
        state.bench.push(pid_off);

        // #2273 — mark the outgoing player as "just came off" so the bench
        // row shows a transient "↓ Off <min>" pill + "Just came off for
        // <incoming>" line. Auto-clears after ~60s (matching live.html).
        var minute = currentMinute();
        state.recently_off = state.recently_off || {};
        if (state.recently_off[pid_off] && state.recently_off[pid_off].timer) {
            clearTimeout(state.recently_off[pid_off].timer);
        }
        state.recently_off[pid_off] = {
            min: minute,
            replacedBy: name(pid_on),
            timer: setTimeout(function () {
                if (state.recently_off) delete state.recently_off[pid_off];
                renderBenchAndOnPitch();
            }, OFF_PILL_MS)
        };

        renderBenchAndOnPitch();
        var uuid = uuidv4();
        api('substitution', {
            event_uuid: uuid,
            half: state.half,
            minute: minute,
            player_off: pid_off,
            player_on: pid_on
        });
        // #2269 — offer an inline Undo on the just-logged sub (matching the
        // goal long-press UX). Reverts the on-pitch swap locally and soft-
        // deletes the sub server-side. The event-feed chip carries the same
        // Undo after a reload, so a mis-tap is recoverable either way.
        toast(
            (i18n.sub_toast_format || '✓ %1$s on for %2$s · %3$s\'').replace('%1$s', name(pid_on)).replace('%2$s', name(pid_off)).replace('%3$s', minute),
            i18n.undo || 'Undo',
            function () {
                // Revert the local swap: on-player back to bench, off-player on.
                var idx = state.on_pitch.indexOf(pid_on);
                if (idx >= 0) state.on_pitch.splice(idx, 1, pid_off);
                var bi = state.bench.indexOf(pid_off);
                if (bi >= 0) state.bench.splice(bi, 1);
                state.bench.push(pid_on);
                // #2273 — clear the just-came-off pill for the reverted player.
                if (state.recently_off && state.recently_off[pid_off]) {
                    if (state.recently_off[pid_off].timer) clearTimeout(state.recently_off[pid_off].timer);
                    delete state.recently_off[pid_off];
                }
                renderBenchAndOnPitch();
                apiDelete('substitution/' + uuid);
            }
        );
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
        if (state.state === ST.FIRST_HALF) {
            label = i18n.half_label_first || 'First half';
            status = state.running ? 'live' : '';
        } else if (state.state === ST.HALF_TIME) {
            label = i18n.half_label_break || 'Half time';
        } else if (state.state === ST.SECOND_HALF) {
            label = i18n.half_label_second || 'Second half';
            status = state.running ? 'live' : '';
        } else if (state.state === ST.PENDING_REVIEW) {
            // #2267 — the match has ended but review is open; the clock is
            // parked, the label reads "ended · pending review".
            label = i18n.half_label_review || 'Ended · pending review';
        } else if (state.state === ST.FINALIZED) {
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
        if (state.state === ST.FIRST_HALF) {
            els.stateBtn.textContent = i18n.end_first_half || 'End first half';
            els.stateBtn.setAttribute('data-action', 'end-first-half');
            els.stateBtn.disabled = false;
        } else if (state.state === ST.HALF_TIME) {
            els.stateBtn.textContent = i18n.start_second_half || 'Start second half';
            els.stateBtn.setAttribute('data-action', 'start-second-half');
            els.stateBtn.disabled = false;
        } else if (state.state === ST.SECOND_HALF) {
            els.stateBtn.textContent = i18n.end_match || 'End match';
            els.stateBtn.setAttribute('data-action', 'end-match');
            els.stateBtn.disabled = false;
        } else if (state.state === ST.PENDING_REVIEW) {
            // #2267 — post-match, review-open. CTA jumps to the review
            // panel (finalize + late events) instead of the old
            // navigate-away behaviour.
            els.stateBtn.textContent = i18n.review_match || 'Review match';
            els.stateBtn.setAttribute('data-action', 'review-match');
            els.stateBtn.disabled = false;
        } else if (state.state === ST.FINALIZED) {
            // #2271 — finalized is never a dead-end: offer re-open.
            els.stateBtn.textContent = i18n.reopen_match || 'Re-open for corrections';
            els.stateBtn.setAttribute('data-action', 'reopen-match');
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
            if (isPostMatch(state.state)) {
                // #2267 — post-match: the clock is parked, no start/resume.
                els.timerBtn.textContent = i18n.half_label_locked || 'Finalized';
                els.timerBtn.setAttribute('data-action', 'locked');
                els.timerBtn.disabled = true;
            } else if (state.state === ST.NOT_STARTED) {
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
                // #2273 — transient "just came off" pill + story line for a
                // player subbed off within the last minute.
                var off = state.recently_off && state.recently_off[pid];
                if (off) li.setAttribute('data-just-off', 'true');
                var pill = off
                    ? ' <span class="tt-mexec-off-pill">' +
                        escapeHtml('↓ ' + (i18n.came_off || 'Off') + ' ' + off.min + "'") +
                      '</span>'
                    : '';
                var story = off
                    ? '<span class="tt-mexec-bench-story">' +
                        escapeHtml((i18n.just_came_off_for || 'Just came off for') + ' ' + off.replacedBy) +
                      '</span>'
                    : '';
                li.innerHTML =
                    '<span class="tt-mexec-player-number">' + escapeHtml(jersey) + '</span>' +
                    '<span class="tt-mexec-player-name">' + escapeHtml(pl.name) + pill + story + '</span>' +
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
    // #2270 — a rejected request is one of two kinds: a *network* failure
    // (offline / DNS / timeout) which is queued for replay, or an *HTTP*
    // failure (4xx/5xx — a rejected write, e.g. a finalized-match 409 or a
    // validation 400) which must NOT be queued (it would retry-loop) and
    // instead rejects so the caller can roll back its optimistic UI.
    function api(action, body) {
        var url = (cfg.rest_url || '/wp-json/talenttrack/v1/match-execution/0/') + action;
        return doFetch(url, 'POST', body).catch(function (err) {
            if (err && err.isHttp) throw err;
            enqueue({ url: url, method: 'POST', body: body });
        });
    }
    function apiDelete(path) {
        var url = (cfg.rest_url || '/wp-json/talenttrack/v1/match-execution/0/') + path;
        return doFetch(url, 'DELETE', null).catch(function (err) {
            if (err && err.isHttp) throw err;
            enqueue({ url: url, method: 'DELETE', body: null });
        });
    }
    // #2273 — PATCH a logged substitution's minute. Mirrors api()/apiDelete:
    // online-first, offline-queued on network failure, re-thrown on HTTP error.
    function apiPatch(path, body) {
        var url = (cfg.rest_url || '/wp-json/talenttrack/v1/match-execution/0/') + path;
        return doFetch(url, 'PATCH', body).catch(function (err) {
            if (err && err.isHttp) throw err;
            enqueue({ url: url, method: 'PATCH', body: body });
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
            if (!r.ok) {
                var httpErr = new Error('HTTP ' + r.status);
                httpErr.isHttp = true;
                httpErr.status = r.status;
                throw httpErr;
            }
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
    function toast(text, actionLabel, onAction) {
        var el = document.createElement('div');
        el.className = 'tt-mexec-toast';
        var span = document.createElement('span');
        span.className = 'tt-mexec-toast-text';
        span.textContent = text;
        el.appendChild(span);
        // #2269 — optional inline action (e.g. Undo a just-logged sub).
        if (actionLabel && typeof onAction === 'function') {
            var act = document.createElement('button');
            act.type = 'button';
            act.className = 'tt-mexec-toast-action';
            act.textContent = actionLabel;
            act.addEventListener('click', function () {
                onAction();
                if (el.parentNode) el.parentNode.removeChild(el);
            });
            el.appendChild(act);
        }
        document.body.appendChild(el);
        setTimeout(function () { if (el.parentNode) el.parentNode.removeChild(el); }, 5000);
    }

    // --- #2222 — explicit edit affordance for the live data controls ---
    // The view opens read-only (root data-edit-mode="off"); the mutating
    // controls (score steppers, +action / →on buttons, sub-target, late
    // events) are hidden via CSS until the coach opts in. Toggling flips
    // the attribute; the button label + aria-pressed follow.
    (function wireEditToggle() {
        var btn = root.querySelector('[data-tt-mexec-edit-toggle]');
        if (!btn) return;
        var label = btn.querySelector('.tt-mexec-edit-label');
        btn.addEventListener('click', function () {
            var on = root.getAttribute('data-edit-mode') !== 'on';
            root.setAttribute('data-edit-mode', on ? 'on' : 'off');
            btn.setAttribute('aria-pressed', on ? 'true' : 'false');
            if (label) {
                label.textContent = on
                    ? (label.getAttribute('data-label-done') || 'Done editing')
                    : (label.getAttribute('data-label-edit') || 'Edit');
            }
        });
    })();

    // --- #2275 — opponent (away) goals in the post-match review ---
    // Add / remove / correct-the-minute of an opponent goal. Backed by the
    // existing goal-event REST (POST team:'away', PATCH {half,minute},
    // DELETE); the away score syncs server-side. Reload on success so the
    // recomputed score + goal list are authoritative (same pattern as the
    // live goal flow and the sub-minute correction).
    (function wireAwayGoals() {
        var section = root.querySelector('[data-tt-mexec-match-goals]');
        if (!section) return;
        var MG_MAX = HALF_LENGTH + 10;

        // Correct-minute steppers + delete on each existing away goal.
        Array.prototype.forEach.call(section.querySelectorAll('[data-tt-mexec-away-goal]'), function (card) {
            var uuid  = card.getAttribute('data-event-uuid');
            var half  = parseInt(card.getAttribute('data-half'), 10) || 1;
            var input = card.querySelector('[data-tt-mexec-away-min-input]');
            var dec   = card.querySelector('[data-tt-mexec-away-min-dec]');
            var inc   = card.querySelector('[data-tt-mexec-away-min-inc]');
            var del   = card.querySelector('[data-tt-mexec-away-goal-del]');

            function commit(v) {
                if (!uuid || !input) return;
                v = Math.max(0, Math.min(MG_MAX, isNaN(v) ? 0 : v));
                if (v === (parseInt(input.getAttribute('value'), 10) || 0)) { input.value = v; return; }
                input.value = v;
                input.disabled = true;
                apiPatch('goal-event/' + uuid, { half: half, minute: v }).then(function () {
                    window.location.reload();
                }).catch(function () { input.disabled = false; });
            }
            if (input && dec) dec.addEventListener('click', function () { commit((parseInt(input.value, 10) || 0) - 1); });
            if (input && inc) inc.addEventListener('click', function () { commit((parseInt(input.value, 10) || 0) + 1); });
            if (input) input.addEventListener('change', function () { commit(parseInt(input.value, 10)); });

            if (del && uuid) {
                del.addEventListener('click', function () {
                    if (!window.confirm(i18n.away_goal_del_confirm || 'Remove this opponent goal? The score updates.')) return;
                    del.disabled = true;
                    apiDelete('goal-event/' + uuid).then(function () {
                        window.location.reload();
                    }).catch(function () { del.disabled = false; });
                });
            }
        });

        // Add-an-opponent-goal form.
        var openBtn = section.querySelector('[data-tt-mexec-away-goal-open]');
        var form    = section.querySelector('[data-tt-mexec-away-goal-form]');
        var cancel  = section.querySelector('[data-tt-mexec-away-goal-cancel]');
        if (openBtn && form) {
            openBtn.addEventListener('click', function () { form.classList.add('is-open'); });
        }
        if (cancel && form) {
            cancel.addEventListener('click', function () { form.classList.remove('is-open'); });
        }
        if (form) {
            var submitting = false;
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                if (submitting) return;
                var half   = parseInt(form.querySelector('[name="half"]').value, 10) || 0;
                var minute = parseInt(form.querySelector('[name="minute"]').value, 10);
                if (half !== 1 && half !== 2) return;
                if (isNaN(minute) || minute < 0 || minute > MG_MAX) {
                    window.alert(i18n.away_goal_minute_error || 'Enter a valid minute.');
                    return;
                }
                submitting = true;
                var saveBtn = form.querySelector('.tt-mexec-add-goal-save');
                if (saveBtn) saveBtn.disabled = true;
                api('goal-event', { event_uuid: uuidv4(), half: half, minute: minute, team: 'away' }).then(function () {
                    window.location.reload();
                }).catch(function () {
                    submitting = false;
                    if (saveBtn) saveBtn.disabled = false;
                    window.alert(i18n.away_goal_add_error || 'Could not add the opponent goal.');
                });
            });
        }
    })();

    // --- Correct recorded minutes (per-player override) ---
    // Read-only by default; the "Correct recorded minutes" button flips the
    // section into edit mode (numeric inputs + Save/Cancel). Rebuild: each
    // changed figure is now written as a per-player OVERRIDE through
    // PATCH /match-execution/{activity_id}/minutes {player_id, minutes}.
    // The override wins over the sub-log-derived minutes and survives
    // recompute; the old raw /attendance/{id} path is refused (409) once an
    // execution owns the activity. An empty field clears the override
    // (minutes: null) so the derived value shows again.
    (function wireMinutesCorrection() {
        var section = root.querySelector('[data-tt-mexec-minutes-section]');
        if (!section) return;
        var toggle = section.querySelector('[data-tt-mexec-minutes-edit]');
        var form = section.querySelector('[data-tt-mexec-minutes-form]');
        if (!toggle || !form) return;

        toggle.addEventListener('click', function () {
            var on = section.getAttribute('data-edit-mode') !== 'on';
            section.setAttribute('data-edit-mode', on ? 'on' : 'off');
            toggle.setAttribute('aria-pressed', on ? 'true' : 'false');
        });

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var saveBtn = form.querySelector('.tt-save-btn');

            // Collect only rows whose minutes actually changed, keyed by
            // player id (the override endpoint is player-scoped).
            var changes = [];
            var rows = form.querySelectorAll('.tt-mexec-minutes-row');
            Array.prototype.forEach.call(rows, function (row) {
                var pid = parseInt(row.getAttribute('data-player-id'), 10) || 0;
                if (pid <= 0) return;
                var input = row.querySelector('[data-tt-mexec-minutes-input]');
                if (!input) return;
                var raw = input.value.trim();
                var orig = input.defaultValue.trim();
                if (raw === orig) return;
                changes.push({
                    player_id: pid,
                    minutes: raw === '' ? null : Math.max(0, parseInt(raw, 10) || 0)
                });
            });

            if (!changes.length) { window.location.reload(); return; }
            if (saveBtn) saveBtn.setAttribute('data-state', 'saving');

            Promise.all(changes.map(function (c) {
                return doFetch((cfg.rest_url || '') + 'minutes', 'PATCH', {
                    player_id: c.player_id,
                    minutes: c.minutes
                }).then(function () { return null; }).catch(function (err) {
                    return (err && err.status) ? ('HTTP ' + err.status) : 'network error';
                });
            })).then(function (results) {
                var errs = results.filter(function (x) { return x; });
                if (!errs.length) { window.location.reload(); return; }
                if (saveBtn) saveBtn.setAttribute('data-state', 'error');
                window.alert((i18n.minutes_save_error || 'Could not save recorded minutes:') + ' ' + errs[0]);
            }).catch(function () {
                if (saveBtn) saveBtn.setAttribute('data-state', 'error');
                window.alert((i18n.minutes_save_error || 'Could not save recorded minutes:') + ' network error.');
            });
        });
    })();

    // --- Finalize / re-open / late events (rebuild — moved out of the
    // PHP view's inline <script> so the module owns 100% of behaviour and
    // the view emits zero script). All three reuse the module's own
    // api()/doFetch()/uuidv4() helpers instead of a second lazy-cfg copy. ---
    var MINUTE_MAX = HALF_LENGTH + 10;

    // Finalize — lock the match. Confirm, POST, reload; surface a server
    // error message inline.
    (function wireFinalize() {
        var btn = root.querySelector('[data-tt-mexec-finalize]');
        if (!btn) return;
        btn.addEventListener('click', function () {
            if (!window.confirm(i18n.finalize_confirm || 'Finalize this match? Goals, subs, and score cannot be edited after.')) return;
            btn.disabled = true;
            doFetch((cfg.rest_url || '') + 'finalize', 'POST', {}).then(function () {
                window.location.reload();
            }).catch(function (err) {
                btn.disabled = false;
                window.alert((i18n.finalize_error || 'Could not finalize:') + ' ' + ((err && err.status) ? ('HTTP ' + err.status) : 'network error.'));
            });
        });
    })();

    // Re-open the dedicated post-match-panel button (distinct from the
    // footer state CTA, which also re-opens when FINALIZED).
    (function wireReopenButton() {
        var btn = root.querySelector('[data-tt-mexec-reopen]');
        if (!btn) return;
        btn.addEventListener('click', function () {
            if (!window.confirm(i18n.reopen_confirm || 'Re-open this finalized match for corrections?')) return;
            btn.disabled = true;
            doFetch((cfg.rest_url || '') + 'reopen', 'POST', {}).then(function () {
                window.location.reload();
            }).catch(function (err) {
                btn.disabled = false;
                window.alert((i18n.reopen_error || 'Could not re-open the match:') + ' ' + ((err && err.status) ? ('HTTP ' + err.status) : 'network error.'));
            });
        });
    })();

    // Late-event forms — add a goal / sub the coach forgot to log live.
    // Client-generated event_uuid keeps the offline-queue replay idempotent.
    (function wireLateEvents() {
        function wireLateForm(form, endpoint, build) {
            if (!form) return;
            var submitting = false;
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                if (submitting) return;
                var body = build(form);
                if (!body) return;
                submitting = true;
                var btn = form.querySelector('.tt-mexec-late-event-submit');
                if (btn) btn.disabled = true;
                var reenable = function () { submitting = false; if (btn) btn.disabled = false; };
                doFetch((cfg.rest_url || '') + endpoint, 'POST', body).then(function () {
                    window.location.reload();
                }).catch(function (err) {
                    reenable();
                    window.alert((i18n.late_save_error || 'Could not save:') + ' ' + ((err && err.status) ? ('HTTP ' + err.status) : 'network error.'));
                });
            });
        }

        wireLateForm(root.querySelector('[data-tt-mexec-late-goal-form]'), 'goal-event', function (f) {
            var pid = parseInt(f.querySelector('[name="player_id"]').value, 10) || 0;
            var half = parseInt(f.querySelector('[name="half"]').value, 10) || 0;
            var minute = parseInt(f.querySelector('[name="minute"]').value, 10);
            if (pid <= 0 || (half !== 1 && half !== 2)) return null;
            if (isNaN(minute) || minute < 0 || minute > MINUTE_MAX) return null;
            return { event_uuid: uuidv4(), player_id: pid, half: half, minute: minute };
        });

        wireLateForm(root.querySelector('[data-tt-mexec-late-sub-form]'), 'substitution', function (f) {
            var off = parseInt(f.querySelector('[name="player_off"]').value, 10) || 0;
            var on = parseInt(f.querySelector('[name="player_on"]').value, 10) || 0;
            var half = parseInt(f.querySelector('[name="half"]').value, 10) || 0;
            var minute = parseInt(f.querySelector('[name="minute"]').value, 10);
            if (off <= 0 || on <= 0 || off === on) return null;
            if (half !== 1 && half !== 2) return null;
            if (isNaN(minute) || minute < 0 || minute > MINUTE_MAX) return null;
            return { event_uuid: uuidv4(), half: half, minute: minute, player_off: off, player_on: on };
        });
    })();
})();
