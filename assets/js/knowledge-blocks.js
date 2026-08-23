/**
 * Interactive lesson blocks (#2643, epic #2641).
 *
 * Three tools: the zero-point resolver, the week planner and the pitch-size
 * calculator, plus the load matrix's cycle toggle. All vanilla, no
 * dependencies, no jQuery.
 *
 * Every block renders a usable server-side state first. This file upgrades
 * that state; it never creates it. A lesson with the script blocked still
 * shows the pitch table, the model and the default matrix.
 *
 * Numbers come from TTKnowledge.periodisation, localised from
 * Periodisation::forScript(), so the course and the (future) planner cannot
 * disagree about how many hours 4v4 needs. Strings come from
 * TTKnowledge.i18n — never hardcoded English; the reader is Dutch.
 */
(function () {
    'use strict';

    var data = window.TTKnowledge || {};
    var P = data.periodisation || {};
    var I18N = data.i18n || {};

    /** printf-style %1$d / %2$s substitution, matching the PHP side. */
    function format(template, args) {
        if (!template) {
            return '';
        }
        return template.replace(/%(\d+)\$[dsf]|%[dsf]/g, function (match, index) {
            var i = index ? parseInt(index, 10) - 1 : format._auto++;
            var value = args[i];
            return value === undefined ? match : String(value);
        });
    }

    function fmt(template) {
        format._auto = 0;
        return format(template, Array.prototype.slice.call(arguments, 1));
    }

    /**
     * State the reader saved for this lesson last time (#2646).
     *
     * Read lazily rather than captured at parse time: knowledge-reader.js
     * is enqueued as a dependent of this file, so it assigns these after
     * this script parses but before either boots on DOMContentLoaded.
     */
    function savedState(key) {
        var all = (window.TTKnowledge && window.TTKnowledge.savedState) || {};
        return all[key] || null;
    }

    /** Hand state to the reader to persist. No-op when it is not loaded. */
    function persist(key, state) {
        if (window.TTKnowledge && typeof window.TTKnowledge.persist === 'function') {
            window.TTKnowledge.persist(key, state);
        }
    }

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        if (text !== undefined) {
            node.textContent = text;
        }
        return node;
    }

    /* ── zero point ─────────────────────────────────────────────────── */

    /**
     * Highest step whose total load the measurement reaches.
     *
     * Mirrors ZeroPointBlock::resolveStep. A coach who managed 25 minutes
     * has completed step 3 at 24 minutes, not step 4 — rounding up would
     * start them above their own measurement, which is the one direction
     * that causes injuries rather than slow progress.
     */
    function resolveStep(methodKey, minutes) {
        var method = (P.overloadSteps || {})[methodKey];
        if (!method || !minutes || minutes <= 0) {
            return null;
        }

        var found = null;
        method.steps.forEach(function (step) {
            if (step.total <= minutes) {
                found = step;
            }
        });

        return found;
    }

    function initZeroPoint(root) {
        var methodEl = root.querySelector('[data-tt-zeropoint-method]');
        var minutesEl = root.querySelector('[data-tt-zeropoint-minutes]');
        var output = root.querySelector('[data-tt-zeropoint-output]');

        if (!methodEl || !minutesEl || !output) {
            return;
        }

        // Rehydrate before the first render, so a coach returning to the
        // lesson sees the measurement they took rather than an empty box.
        var saved = savedState('zeropoint');
        if (saved) {
            if (saved.method) {
                methodEl.value = saved.method;
            }
            if (saved.minutes) {
                minutesEl.value = saved.minutes;
            }
        }

        function update(save) {
            var minutes = parseFloat(minutesEl.value);

            if (isNaN(minutes) || minutes <= 0) {
                output.textContent = I18N.zeroPointPrompt || '';
                return;
            }

            var step = resolveStep(methodEl.value, minutes);

            if (!step) {
                output.textContent = I18N.zeroPointTooLow || '';
                return;
            }

            output.textContent = fmt(I18N.zeroPointResult, step.step, step.games, step.minutes);
            root.dataset.ttZeropointStep = String(step.step);
            root.dataset.ttZeropointMethod = methodEl.value;
            root.dataset.ttZeropointMinutes = String(minutes);

            if (save) {
                persist('zeropoint', {
                    method: methodEl.value,
                    minutes: minutes,
                    step: step.step
                });
            }
        }

        methodEl.addEventListener('change', function () { update(true); });
        minutesEl.addEventListener('input', function () { update(true); });

        // First paint does not save: rendering what was already stored is
        // not a change, and writing it back would touch the record on
        // every page view.
        update(false);
    }

    /* ── week planner ───────────────────────────────────────────────── */

    var HOURS_PER_DAY = 24;

    /**
     * Recovery violations in a plan.
     *
     * Mirrors WeekPlannerBlock::violations. Two rules: nothing inside its
     * own recovery window before a match, and no two identical stimuli
     * closer together than the exercise needs.
     */
    function weekViolations(plan, dayNames) {
        var types = P.sessionTypes || {};
        var recovery = P.supercompensation || {};
        var problems = [];
        var matchDays = [];
        var lastSeen = {};

        plan.forEach(function (key, index) {
            if (key === 'match') {
                matchDays.push(index);
            }
        });

        plan.forEach(function (key, index) {
            var type = types[key];
            var exercise = type && type.exercise;
            if (!exercise || !recovery[exercise]) {
                return;
            }

            var required = recovery[exercise].max;

            matchDays.forEach(function (matchDay) {
                if (matchDay <= index) {
                    return;
                }
                var available = (matchDay - index) * HOURS_PER_DAY;
                if (available < required) {
                    problems.push(
                        type.label + ' — ' + dayNames[index] + ' → ' + dayNames[matchDay] +
                        ': ' + available + ' / ' + required + ' h'
                    );
                }
            });

            if (lastSeen[exercise] !== undefined) {
                var gap = (index - lastSeen[exercise]) * HOURS_PER_DAY;
                if (gap < required) {
                    problems.push(
                        type.label + ' — ' + dayNames[lastSeen[exercise]] + ' + ' + dayNames[index] +
                        ': ' + gap + ' / ' + required + ' h'
                    );
                }
            }

            lastSeen[exercise] = index;
        });

        return problems;
    }

    function initWeekPlanner(root) {
        var selects = Array.prototype.slice.call(root.querySelectorAll('[data-tt-week-day]'));
        var verdict = root.querySelector('[data-tt-week-verdict]');

        if (!selects.length || !verdict) {
            return;
        }

        var dayNames = selects.map(function (select) {
            var label = root.querySelector('label[for="' + select.id + '"]');
            return label ? label.textContent : '';
        });

        var savedPlan = savedState('weekplan');
        if (savedPlan && Array.isArray(savedPlan.plan)) {
            savedPlan.plan.forEach(function (value, index) {
                if (selects[index] && value) {
                    selects[index].value = value;
                }
            });
        }

        function update(save) {
            var plan = selects.map(function (select) {
                return select.value;
            });

            if (save) {
                persist('weekplan', { plan: plan });
            }

            var hasMatch = plan.indexOf('match') !== -1;
            var hasSession = plan.some(function (key) {
                var type = (P.sessionTypes || {})[key];
                return type && type.exercise;
            });

            verdict.classList.remove('tt-lesson-week__verdict--ok', 'tt-lesson-week__verdict--problem');
            verdict.textContent = '';

            if (!hasMatch || !hasSession) {
                verdict.textContent = I18N.weekPrompt || '';
                return;
            }

            var problems = weekViolations(plan, dayNames);

            if (!problems.length) {
                verdict.classList.add('tt-lesson-week__verdict--ok');
                verdict.textContent = '✓ ' + (I18N.weekOk || '');
                return;
            }

            verdict.classList.add('tt-lesson-week__verdict--problem');
            verdict.appendChild(el('p', null, '✕ ' + fmt(I18N.weekProblems, problems.length)));

            var list = el('ul', 'tt-lesson-week__problems');
            problems.forEach(function (problem) {
                list.appendChild(el('li', null, problem));
            });
            verdict.appendChild(list);
        }

        selects.forEach(function (select) {
            select.addEventListener('change', function () { update(true); });
        });

        update(false);
    }

    /* ── pitch size ─────────────────────────────────────────────────── */

    function initPitchSize(root) {
        var select = root.querySelector('[data-tt-pitchsize-format]');
        var computed = root.querySelector('[data-tt-pitchsize-computed]');
        var use = root.querySelector('[data-tt-pitchsize-use]');
        var note = root.querySelector('[data-tt-pitchsize-note]');

        if (!select || !computed || !use || !note) {
            return;
        }

        function update() {
            var size = (P.pitchSizes || []).filter(function (entry) {
                return entry.format === select.value;
            })[0];

            if (!size) {
                return;
            }

            computed.textContent = size.length + ' × ' + size.width + ' m';
            use.textContent = size.length + ' × ' + size.min_width + ' m';
            note.textContent = size.min_width > size.width
                ? fmt(I18N.pitchTooNarrow, size.width, size.min_width)
                : (I18N.pitchRuleWorks || '');
        }

        select.addEventListener('change', update);
        update();
    }

    /* ── load matrix ────────────────────────────────────────────────── */

    var FORMATS = ['games_large', 'games_medium', 'games_small'];

    /** Mirrors LoadMatrixBlock::loadFor. */
    function loadFor(index, week, cycle) {
        var slots = FORMATS.length;
        var slotSize = Math.max(1, Math.round(cycle / slots));
        var slot = Math.min(Math.floor((week % cycle) / slotSize), slots - 1);

        if (slot === index) {
            return 100;
        }
        if (slot === (index - 1 + slots) % slots) {
            return 50;
        }
        return 0;
    }

    function initLoadMatrix(root) {
        var target = root.querySelector('[data-tt-matrix-target]');
        var radios = Array.prototype.slice.call(root.querySelectorAll('[data-tt-matrix-cycle]'));

        if (!target || !radios.length) {
            return;
        }

        // Row labels are read off the server-rendered table rather than
        // duplicated here, so the two renderings cannot disagree.
        var labels = Array.prototype.slice
            .call(target.querySelectorAll('tbody th[scope="row"]'))
            .map(function (cell) {
                return cell.textContent;
            });

        var headLabel = target.querySelector('thead th');
        var exerciseLabel = headLabel ? headLabel.textContent : '';

        function rebuild(cycle) {
            var cycles = parseInt(root.dataset.ttCycles, 10) || 2;
            var weeks = cycle * cycles;

            var table = el('table', 'tt-lesson-matrix__table');
            var thead = el('thead');
            var headRow = el('tr');

            var corner = el('th', null, exerciseLabel);
            corner.setAttribute('scope', 'col');
            headRow.appendChild(corner);

            for (var week = 1; week <= weeks; week++) {
                var th = el('th', null, String(week));
                th.setAttribute('scope', 'col');
                headRow.appendChild(th);
            }

            thead.appendChild(headRow);
            table.appendChild(thead);

            var tbody = el('tbody');

            labels.forEach(function (label, index) {
                var row = el('tr');
                var rowHead = el('th', null, label);
                rowHead.setAttribute('scope', 'row');
                row.appendChild(rowHead);

                for (var w = 0; w < weeks; w++) {
                    var load = loadFor(index, w, cycle);
                    row.appendChild(el('td', 'tt-lesson-matrix__cell tt-lesson-matrix__cell--' + load, String(load)));
                }

                tbody.appendChild(row);
            });

            table.appendChild(tbody);
            target.replaceChildren(table);
        }

        radios.forEach(function (radio) {
            radio.addEventListener('change', function () {
                if (radio.checked) {
                    rebuild(parseInt(radio.value, 10) || 6);
                }
            });
        });
    }

    /* ── inline check (#2738) ───────────────────────────────────────── */

    /**
     * The mid-lesson check: pick an option, get told immediately.
     *
     * Scored here rather than on the server, unlike the end-of-lesson quiz.
     * Nothing is recorded, nothing unlocks and nothing reports, so there is
     * no result to protect — and what this needs instead is a verdict with
     * no round trip, which is what makes it usable on a phone with one bar.
     *
     * Answering is one-way. Once a reader has committed, the options lock:
     * letting them retry until it goes green turns retrieval practice into
     * a guessing game, and there is no score here to salvage by guessing.
     */
    function initCheck(root) {
        var answer = (root.dataset.ttAnswer || '').toUpperCase();
        var verdict = root.querySelector('[data-tt-check-verdict]');
        var why = root.querySelector('[data-tt-check-why]');
        var options = Array.prototype.slice.call(
            root.querySelectorAll('.tt-lesson-check__option')
        );

        if (!answer || !options.length) {
            return;
        }

        // The disclosure is the no-JS fallback. With the script running the
        // explanation is opened at the moment it becomes useful, so it stops
        // being something the reader has to notice and click.
        if (why) {
            why.hidden = true;
        }

        function settle(chosen) {
            if (root.dataset.ttAnswered === '1') {
                return;
            }
            root.dataset.ttAnswered = '1';

            var correct = chosen === answer;
            root.dataset.ttResult = correct ? 'correct' : 'wrong';

            options.forEach(function (option) {
                var key = (option.dataset.ttOption || '').toUpperCase();
                var input = option.querySelector('input');
                if (input) {
                    input.disabled = true;
                }
                if (key === answer) {
                    option.dataset.ttState = 'answer';
                } else if (key === chosen) {
                    option.dataset.ttState = 'chosen-wrong';
                }
            });

            if (verdict) {
                verdict.textContent = correct
                    ? (I18N.checkCorrect || '')
                    : (I18N.checkWrong || '');
            }

            if (why) {
                why.hidden = false;
                why.open = true;
            }
        }

        options.forEach(function (option) {
            var input = option.querySelector('input');
            if (!input) {
                return;
            }
            input.addEventListener('change', function () {
                settle((input.value || '').toUpperCase());
            });
        });
    }

    /* ── boot ───────────────────────────────────────────────────────── */

    var INITIALISERS = {
        zeropoint: initZeroPoint,
        weekplanner: initWeekPlanner,
        pitchsize: initPitchSize,
        loadmatrix: initLoadMatrix,
        check: initCheck
    };

    function boot() {
        document.querySelectorAll('[data-tt-block]').forEach(function (root) {
            var init = INITIALISERS[root.dataset.ttBlock];
            if (init) {
                init(root);
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
}());
