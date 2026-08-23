/**
 * Quiz submission (#2647, epic #2641).
 *
 * An upgrade, not the mechanism. The quiz is a real form that posts and is
 * scored server-side; this submits the same data over REST so the reader
 * gets their result without losing their place in a long lesson.
 *
 * Nothing here scores anything. The answer key never reaches the browser —
 * that is the point of scoring on the server — so this file cannot mark an
 * answer even if it wanted to. It sends what was filled in and renders
 * what comes back.
 */
(function () {
    'use strict';

    var cfg = window.TTKnowledgeReader;
    if (!cfg) {
        return;
    }

    var I18N = cfg.i18n || {};

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

    /** printf-style %1$d / %2$s, matching the PHP side. */
    function fmt(template) {
        var args = Array.prototype.slice.call(arguments, 1);
        var auto = 0;
        return (template || '').replace(/%(\d+)\$[dsf]|%[dsf]/g, function (match, index) {
            var i = index ? parseInt(index, 10) - 1 : auto++;
            return args[i] === undefined ? match : String(args[i]);
        });
    }

    /**
     * Read the form into the shape the server expects.
     *
     * FormData already produces exactly the names the no-JavaScript path
     * posts, so the two submissions are the same payload by construction
     * rather than by two implementations agreeing.
     */
    function collect(form) {
        var data = new FormData(form);
        var q = {};

        data.forEach(function (value, key) {
            var m = key.match(/^q\[([^\]]+)\](?:\[([^\]]*)\])?$/);
            if (!m) {
                return;
            }

            var id = m[1];
            var sub = m[2];

            if (sub === undefined) {
                // q[id] — a single choice.
                q[id] = value;
                return;
            }

            if (sub === '') {
                // q[id][] — a list: checkboxes, or one select per pair.
                if (!Array.isArray(q[id])) {
                    q[id] = [];
                }
                if (value !== '') {
                    q[id].push(value);
                }
                return;
            }

            // q[id][label] — an ordering position.
            if (typeof q[id] !== 'object' || Array.isArray(q[id]) || q[id] === null) {
                q[id] = {};
            }
            q[id][sub] = value;
        });

        return q;
    }

    function renderResult(root, form, result) {
        var host = root.querySelector('[data-tt-quiz-result]');
        if (!host) {
            return;
        }

        host.replaceChildren();
        host.classList.remove('tt-quiz__result--passed', 'tt-quiz__result--failed');
        host.classList.add(result.passed ? 'tt-quiz__result--passed' : 'tt-quiz__result--failed');

        host.appendChild(el(
            'p',
            'tt-quiz__score',
            fmt(I18N.quizScore, result.score, result.max)
        ));

        host.appendChild(el(
            'p',
            'tt-quiz__verdict',
            result.passed ? (I18N.quizPassed || '') : fmt(I18N.quizFailed, result.pass_mark)
        ));

        // Per-question feedback goes beside the question it belongs to, so
        // a reader does not have to hold a list of numbers in their head
        // while scrolling back up.
        (result.questions || []).forEach(function (q) {
            var slot = root.querySelector('[data-tt-quiz-feedback="' + q.id + '"]');
            var field = root.querySelector('[data-tt-quiz-question="' + q.id + '"]');

            if (field) {
                field.classList.remove('tt-quiz__question--correct', 'tt-quiz__question--wrong');
                field.classList.add(q.correct ? 'tt-quiz__question--correct' : 'tt-quiz__question--wrong');
            }

            if (!slot) {
                return;
            }

            var parts = [];
            parts.push(q.correct ? (I18N.quizCorrect || '') : (I18N.quizWrong || ''));

            if (!q.correct && q.expected && q.expected.length) {
                parts.push(fmt(I18N.quizAnswer, q.expected.join(' → ')));
            }
            if (q.explanation) {
                parts.push(q.explanation);
            }

            slot.textContent = parts.join(' ');
        });

        if (result.passed) {
            // The lesson's completion state and the course percentage are
            // server-rendered; reloading is the honest way to show them
            // rather than patching half the page from here.
            form.querySelectorAll('input, select, button').forEach(function (control) {
                control.disabled = true;
            });
            host.appendChild(el('p', 'tt-quiz__reload', I18N.quizReloading || ''));
            window.setTimeout(function () { window.location.reload(); }, 1500);
        }
    }

    function wire(root) {
        var form = root.querySelector('[data-tt-quiz-form]');
        var button = root.querySelector('[data-tt-quiz-submit]');
        var lesson = root.getAttribute('data-tt-quiz-lesson') || cfg.lesson;

        if (!form || !button) {
            return;
        }

        form.addEventListener('submit', function (event) {
            if (typeof window.fetch !== 'function') {
                return; // let the plain POST happen
            }

            event.preventDefault();
            button.disabled = true;

            fetch(
                cfg.root + '/courses/' + encodeURIComponent(cfg.course) +
                '/quiz/' + encodeURIComponent(lesson),
                {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': cfg.nonce
                    },
                    body: JSON.stringify({ q: collect(form) })
                }
            ).then(function (response) {
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status);
                }
                return response.json();
            }).then(function (body) {
                var result = (body && body.data) || body;
                renderResult(root, form, result);
                if (!result.passed) {
                    button.disabled = false;
                }
            }).catch(function () {
                button.disabled = false;
                var host = root.querySelector('[data-tt-quiz-result]');
                if (host) {
                    host.replaceChildren(el('p', 'tt-quiz__error', I18N.failed || ''));
                }
            });
        });
    }

    function boot() {
        document.querySelectorAll('.tt-quiz[data-tt-block="quiz"]').forEach(wire);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
}());
