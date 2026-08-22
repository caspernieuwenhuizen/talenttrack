/**
 * TalentTrack — the sideline view (#2499).
 *
 * One block at a time, a timer, and three big controls. This runs on a
 * phone held in one hand by someone whose attention belongs to fifteen
 * children, so the rules it follows are different from the rest of the
 * plugin's front end:
 *
 *   - Nothing auto-advances. The coach decides when a block is done;
 *     a timer that moved the screen on by itself would be actively
 *     dangerous when they looked back at it.
 *   - Running over is a state, not an error. The screen says what will
 *     be recorded if they finish now, and lets them carry on.
 *   - A failed write is reported, never swallowed. This view is
 *     online-only by decision (epic #2493, D15; offline is #2552), so a
 *     coach who loses signal has to know their timings did not save.
 *
 * All user-facing text comes from cfg.i18n.
 */
(function () {
    'use strict';

    if (typeof window.TT_TRAINING_RUN === 'undefined') return;

    var cfg = window.TT_TRAINING_RUN;
    var i18n = cfg.i18n || {};

    document.addEventListener('DOMContentLoaded', function () {
        if (cfg.mode === 'attach') { bindAttach(); return; }

        var root = document.querySelector('[data-tt-run]');
        if (!root) return;
        bindRun(root);
        bindObservations();
    });

    // ---- observations -----------------------------------------------------

    /**
     * The observation sheet under the sideline view.
     *
     * Two rules worth stating, both from #2500 D7:
     *   - Tapping the selected number again clears it. A coach who taps 7
     *     by mistake needs a way back that is not "reload the page", and
     *     a segmented control with no deselect is a trap.
     *   - A note with no score saves. The server refuses only the case
     *     where both are empty.
     */
    function bindObservations() {
        var sheet = document.querySelector('[data-tt-obs]');
        if (!sheet) return;

        var runId = parseInt(sheet.getAttribute('data-tt-obs'), 10);
        if (!runId) return;

        sheet.addEventListener('click', function (e) {
            var step = e.target.closest && e.target.closest('[data-tt-obs-value]');
            if (step) { toggleStep(step); return; }

            var save = e.target.closest && e.target.closest('[data-tt-obs-save]');
            if (save) { saveRow(save.closest('[data-tt-obs-player]'), runId); }
        });
    }

    function toggleStep(step) {
        var group = step.parentNode;
        var was = step.getAttribute('aria-pressed') === 'true';

        Array.prototype.forEach.call(group.querySelectorAll('[data-tt-obs-value]'), function (b) {
            b.setAttribute('aria-pressed', 'false');
        });

        // Tapping the active one again leaves everything unpressed, which
        // is how a mis-tap is undone.
        if (!was) step.setAttribute('aria-pressed', 'true');
    }

    function saveRow(row, runId) {
        if (!row) return;

        var playerId = parseInt(row.getAttribute('data-tt-obs-player'), 10);
        var noteEl = row.querySelector('[data-tt-obs-note]');
        var note = noteEl ? noteEl.value.trim() : '';
        var pressed = row.querySelector('[data-tt-obs-value][aria-pressed="true"]');
        var rating = pressed ? pressed.getAttribute('data-tt-obs-value') : null;

        if (!note && rating === null) { obsSay(i18n.obsEmpty); return; }

        var body = { player_id: playerId };
        if (note) body.note = note;
        if (rating !== null) body.rating = rating;

        request('POST', cfg.restBase + '/training/runs/' + runId + '/observations', body)
            .then(function () {
                obsSay(i18n.obsSaved);
                if (noteEl) noteEl.value = '';
                if (pressed) pressed.setAttribute('aria-pressed', 'false');
                row.classList.add('is-saved');
            })
            .catch(function () { obsSay(i18n.obsFailed); });
    }

    function obsSay(text) {
        var msg = document.querySelector('[data-tt-obs-msg]');
        if (msg) msg.textContent = text || '';
    }

    // ---- attach -----------------------------------------------------------

    function bindAttach() {
        var form = document.querySelector('[data-tt-attach]');
        if (!form) return;

        form.addEventListener('submit', function (e) {
            e.preventDefault();

            var select = form.querySelector('[name="plan_id"]');
            var planId = select ? parseInt(select.value, 10) : 0;
            if (!planId) return;

            say(i18n.attaching);

            request('POST', cfg.restBase + '/training/runs', {
                plan_id: planId,
                activity_id: cfg.activityId
            }).then(function (result) {
                var id = result.data && result.data.run && result.data.run.id;
                if (!id) { say(i18n.attachFailed); return; }

                // 200 rather than 201 means the activity already had a run.
                // That is not a failure — say so, then go to it.
                if (result.status === 200) say(i18n.alreadyOne);

                window.location.href = cfg.runUrl + '&id=' + id;
            }).catch(function () {
                say(i18n.attachFailed);
            });
        });
    }

    // ---- the run ----------------------------------------------------------

    function bindRun(root) {
        var state = {
            blocks: (cfg.blocks || []).slice(),
            index: 0,
            startedAt: null,
            status: cfg.status || 'planned',
            tick: null
        };

        // Resume where the coach left off: the first block with nothing
        // recorded against it. Reloading the page mid-session must not
        // send them back to the warm-up.
        for (var i = 0; i < state.blocks.length; i++) {
            if (state.blocks[i].actual_minutes === null && !state.blocks[i].was_skipped) { state.index = i; break; }
            state.index = Math.min(i + 1, state.blocks.length - 1);
        }

        var el = {
            progress: root.querySelector('[data-tt-run-progress]'),
            card: root.querySelector('[data-tt-run-card]'),
            controls: root.querySelector('[data-tt-run-controls]')
        };

        render();

        function render() {
            renderProgress();

            if (state.status === 'completed') { renderDone(); return; }
            if (state.status !== 'running') { renderReady(); return; }

            renderBlock();
        }

        function renderProgress() {
            el.progress.textContent = '';
            state.blocks.forEach(function (block, index) {
                var pip = document.createElement('i');
                if (block.was_skipped) pip.className = 'is-skipped';
                else if (block.actual_minutes !== null) pip.className = 'is-done';
                else if (index === state.index && state.status === 'running') pip.className = 'is-now';
                el.progress.appendChild(pip);
            });
        }

        function renderReady() {
            var minutes = 0;
            state.blocks.forEach(function (b) { minutes += b.planned_minutes || 0; });

            el.card.textContent = '';
            el.card.appendChild(node('p', 'tt-run__kicker', i18n.ready));
            el.card.appendChild(node('h2', 'tt-run__name',
                fmt2(i18n.readySummary, state.blocks.length, minutes)));
            el.card.appendChild(timeline(state.blocks));

            el.controls.textContent = '';
            el.controls.appendChild(bigButton(i18n.start, 'tt-run__go', function () {
                setStatus('running').then(function () {
                    state.status = 'running';
                    state.startedAt = Date.now();
                    render();
                });
            }));
        }

        /**
         * The drill's diagram (#2501).
         *
         * Handed to `TTTrainingScene.render()` — the same call the
         * exercise page makes — rather than drawn here. That is what
         * makes the picture on the pitch the picture that was approved
         * at the desk, including the play controls and the
         * reduced-motion behaviour, none of which this file has to know
         * anything about.
         */
        function renderScene(block) {
            if (!block.scene || !window.TTTrainingScene) { return; }

            var figure = document.createElement('figure');
            figure.className = 'tt-training-scene tt-run__scene';
            figure.setAttribute('data-i18n-play', i18n.scenePlay || '');
            figure.setAttribute('data-i18n-pause', i18n.scenePause || '');
            figure.setAttribute('data-i18n-restart', i18n.sceneRestart || '');
            figure.setAttribute('aria-label', i18n.sceneLabel || '');

            // Already a JSON string from PHP — see the note on `scene`
            // in shapeBlocks(). Handed straight to the payload element
            // rather than parsed and re-encoded.
            var payload = document.createElement('script');
            payload.type = 'application/json';
            payload.textContent = block.scene;
            figure.appendChild(payload);

            el.card.appendChild(figure);
            window.TTTrainingScene.render(figure);
        }

        function renderBlock() {
            var block = state.blocks[state.index];
            if (!block) { renderDone(); return; }

            if (state.startedAt === null) state.startedAt = Date.now();

            el.card.textContent = '';
            el.card.appendChild(node('p', 'tt-run__kicker',
                fmt3(i18n.blockOf, state.index + 1, state.blocks.length, typeLabel(block))));
            el.card.appendChild(node('h2', 'tt-run__name', block.name || i18n.unnamed));

            var timer = document.createElement('div');
            timer.className = 'tt-run__timer';
            timer.setAttribute('data-tt-run-timer', '');
            var big = node('span', 'tt-run__timer-big', '0:00');
            var of = node('span', 'tt-run__timer-of', fmt(i18n.of, clock((block.planned_minutes || 0) * 60)));
            timer.appendChild(big);
            timer.appendChild(of);
            el.card.appendChild(timer);

            var bar = document.createElement('div');
            bar.className = 'tt-run__bar';
            var fill = document.createElement('i');
            bar.appendChild(fill);
            el.card.appendChild(bar);

            var over = node('p', 'tt-run__over', '');
            over.hidden = true;
            el.card.appendChild(over);

            renderScene(block);

            if (block.organisation) {
                el.card.appendChild(node('h3', 'tt-run__sub', i18n.organisation));
                el.card.appendChild(node('p', 'tt-run__org', block.organisation));
            }
            if (block.coaching_points) {
                el.card.appendChild(node('h3', 'tt-run__sub', i18n.coachingPts));
                el.card.appendChild(points(block.coaching_points));
            }

            el.controls.textContent = '';
            var prev = iconButton('‹', i18n.previous, function () { move(-1); });
            prev.disabled = state.index === 0;
            el.controls.appendChild(prev);
            el.controls.appendChild(bigButton(i18n.finishBlock, 'tt-run__go', finishBlock));
            el.controls.appendChild(iconButton('›', i18n.next, function () { move(1); }));

            el.controls.appendChild(textButton(i18n.skipBlock, skipBlock));
            el.controls.appendChild(textButton(i18n.finishRun, function () {
                if (i18n.confirmEnd && !window.confirm(i18n.confirmEnd)) return;
                finishRun();
            }));

            startTicking(block, big, fill, over);
        }

        function startTicking(block, big, fill, over) {
            if (state.tick) clearInterval(state.tick);

            var planned = (block.planned_minutes || 0) * 60;

            function paint() {
                var elapsed = Math.max(0, Math.round((Date.now() - state.startedAt) / 1000));
                big.textContent = clock(elapsed);

                var ratio = planned > 0 ? Math.min(1, elapsed / planned) : 0;
                fill.style.width = (ratio * 100) + '%';

                var isOver = planned > 0 && elapsed > planned;
                big.classList.toggle('is-over', isOver);
                fill.parentNode.classList.toggle('is-over', isOver);

                if (isOver) {
                    // State the consequence, rather than nagging. The
                    // coach knows they are over; what they cannot see is
                    // what finishing now would record.
                    over.hidden = false;
                    over.textContent = fmt2(
                        i18n.overBy || '',
                        clock(elapsed - planned),
                        Math.round(elapsed / 60)
                    );
                } else {
                    over.hidden = true;
                }
            }

            paint();
            state.tick = setInterval(paint, 1000);
        }

        function move(delta) {
            var next = state.index + delta;
            if (next < 0 || next >= state.blocks.length) return;
            state.index = next;
            state.startedAt = Date.now();
            render();
        }

        function elapsedMinutes() {
            return Math.max(1, Math.round((Date.now() - state.startedAt) / 60000));
        }

        function finishBlock() {
            var block = state.blocks[state.index];
            var minutes = elapsedMinutes();

            writeBlock(block.id, { actual_duration_minutes: minutes }).then(function () {
                block.actual_minutes = minutes;
                if (state.index + 1 >= state.blocks.length) { finishRun(); return; }
                state.index += 1;
                state.startedAt = Date.now();
                render();
            });
        }

        function skipBlock() {
            var block = state.blocks[state.index];

            writeBlock(block.id, { was_skipped: true }).then(function () {
                block.was_skipped = true;
                if (state.index + 1 >= state.blocks.length) { finishRun(); return; }
                state.index += 1;
                state.startedAt = Date.now();
                render();
            });
        }

        function finishRun() {
            setStatus('completed').then(function () {
                state.status = 'completed';
                if (state.tick) clearInterval(state.tick);
                render();
            });
        }

        function renderDone() {
            if (state.tick) clearInterval(state.tick);

            var actual = 0;
            var ran = 0;
            var skipped = 0;
            var planned = 0;
            state.blocks.forEach(function (b) {
                planned += b.planned_minutes || 0;
                if (b.was_skipped) { skipped++; return; }
                if (b.actual_minutes !== null) { actual += b.actual_minutes; ran++; }
            });

            el.card.textContent = '';
            el.card.appendChild(node('h2', 'tt-run__name', fmt(i18n.doneTitle, actual)));

            var kpis = document.createElement('div');
            kpis.className = 'tt-run__kpis';
            kpis.appendChild(kpi(actual, i18n.doneMinutes, fmt(i18n.donePlanned, planned)));
            kpis.appendChild(kpi(ran, i18n.doneBlocks, ''));
            kpis.appendChild(kpi(skipped, i18n.doneSkipped, ''));
            el.card.appendChild(kpis);

            el.card.appendChild(node('p', 'tt-run__note', i18n.doneNote));
            if (skipped > 0) el.card.appendChild(node('p', 'tt-run__note', i18n.skippedNote));

            el.controls.textContent = '';
            var back = document.createElement('a');
            back.className = 'tt-btn tt-btn-secondary tt-run__wide';
            back.href = cfg.planUrl || '';
            back.textContent = i18n.backToPlan || '';
            el.controls.appendChild(back);
        }

        function writeBlock(blockId, patch) {
            say(i18n.saving);
            return request('PATCH', cfg.restBase + '/training/runs/' + cfg.runId + '/blocks/' + blockId, patch)
                .then(function () { say(''); })
                .catch(function (e) { say(i18n.saveFailed); throw e; });
        }

        function setStatus(status) {
            say(i18n.saving);
            return request('PATCH', cfg.restBase + '/training/runs/' + cfg.runId, { status: status })
                .then(function () { say(''); })
                .catch(function (e) { say(i18n.saveFailed); throw e; });
        }

        function typeLabel(block) {
            return (cfg.blockTypeLabels || {})[block.block_type] || block.block_type || '';
        }
    }

    // ---- helpers ----------------------------------------------------------

    function timeline(blocks) {
        var strip = document.createElement('div');
        strip.className = 'tt-run__timeline';
        blocks.forEach(function (block) {
            var minutes = block.planned_minutes || 0;
            if (!minutes) return;
            var seg = document.createElement('span');
            seg.className = 'tt-run__seg tt-run__seg--' + (block.block_type || 'main');
            seg.style.flex = String(minutes);
            seg.textContent = String(minutes);
            strip.appendChild(seg);
        });
        return strip;
    }

    function points(text) {
        var list = document.createElement('ul');
        list.className = 'tt-run__points';
        String(text).split(/\r?\n/).forEach(function (line) {
            var trimmed = line.trim();
            if (!trimmed) return;
            var li = document.createElement('li');
            li.textContent = trimmed;
            list.appendChild(li);
        });
        return list;
    }

    function kpi(value, label, sub) {
        var box = document.createElement('div');
        box.className = 'tt-run__kpi';
        box.appendChild(node('span', 'tt-run__kpi-num', String(value)));
        box.appendChild(node('span', 'tt-run__kpi-label', label || ''));
        if (sub) box.appendChild(node('span', 'tt-run__kpi-sub', sub));
        return box;
    }

    function node(tag, className, text) {
        var element = document.createElement(tag);
        element.className = className;
        element.textContent = text || '';
        return element;
    }

    function bigButton(label, className, onClick) {
        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'tt-btn ' + className;
        button.textContent = label || '';
        button.addEventListener('click', onClick);
        return button;
    }

    function iconButton(glyph, label, onClick) {
        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'tt-run__nav';
        button.textContent = glyph;
        button.setAttribute('aria-label', label || '');
        button.addEventListener('click', onClick);
        return button;
    }

    function textButton(label, onClick) {
        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'tt-run__text';
        button.textContent = label || '';
        button.addEventListener('click', onClick);
        return button;
    }

    function clock(seconds) {
        var m = Math.floor(seconds / 60);
        var s = seconds % 60;
        return m + ':' + (s < 10 ? '0' : '') + s;
    }

    function say(text) {
        var msg = document.querySelector('[data-tt-run-msg]');
        if (msg) msg.textContent = text || '';
    }

    function request(method, url, body) {
        var init = {
            method: method,
            credentials: 'same-origin',
            headers: { 'X-WP-Nonce': cfg.nonce, 'Content-Type': 'application/json' }
        };
        if (body) init.body = JSON.stringify(body);

        return fetch(url, init).then(function (response) {
            if (!response.ok) throw new Error(String(response.status));
            return response.json().then(function (envelope) {
                if (envelope && envelope.success === false) throw new Error('rest');
                return { status: response.status, data: envelope ? envelope.data : null };
            });
        });
    }

    function fmt(template, a) {
        return String(template || '').replace(/%d|%s/, String(a));
    }

    function fmt2(template, a, b) {
        return String(template || '')
            .replace(/%1\$[ds]/, String(a))
            .replace(/%2\$[ds]/, String(b));
    }

    function fmt3(template, a, b, c) {
        return String(template || '')
            .replace(/%1\$[ds]/, String(a))
            .replace(/%2\$[ds]/, String(b))
            .replace(/%3\$[ds]/, String(c));
    }
})();
