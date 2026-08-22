/**
 * Lesson reader (#2646, epic #2641).
 *
 * Two jobs, both upgrades to a page that already works without them:
 *
 *   1. Persist interactive-block state, so the zero-point measurement a
 *      coach took in module 4 is still there in module 11 where the final
 *      assignment asks for it.
 *   2. Mark a lesson read without a page reload. The button is a real
 *      submit inside a real form, so with this script blocked it still
 *      posts and still works.
 *
 * Rehydration is why this file loads after the block script and hands the
 * saved state over through `window.TTKnowledge.savedState` — the blocks
 * read it during their own init rather than being re-initialised here.
 */
(function () {
    'use strict';

    var cfg = window.TTKnowledgeReader;
    if (!cfg) {
        return;
    }

    var I18N = cfg.i18n || {};

    /* ── talking to the API ─────────────────────────────────────────── */

    function patchProgress(body) {
        return fetch(
            cfg.root + '/courses/' + encodeURIComponent(cfg.course) +
            '/progress/' + encodeURIComponent(cfg.lesson),
            {
                method: 'PATCH',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': cfg.nonce
                },
                body: JSON.stringify(body)
            }
        ).then(function (response) {
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }
            return response.json();
        });
    }

    /* ── save indicator ─────────────────────────────────────────────── */

    var indicator = null;

    function ensureIndicator() {
        if (indicator) {
            return indicator;
        }
        var host = document.querySelector('[data-tt-lesson-completion]');
        if (!host) {
            return null;
        }
        indicator = document.createElement('p');
        indicator.className = 'tt-knowledge-lesson__save-state';
        indicator.setAttribute('role', 'status');
        host.appendChild(indicator);
        return indicator;
    }

    function say(message, failed) {
        var node = ensureIndicator();
        if (!node) {
            return;
        }
        node.textContent = message || '';
        node.classList.toggle('tt-knowledge-lesson__save-state--failed', !!failed);
    }

    /* ── block state ────────────────────────────────────────────────── */

    // Debounced per key: a coach dragging through the week planner would
    // otherwise fire a request per change.
    var timers = {};
    var pending = {};

    function persist(key, state) {
        if (!key) {
            return;
        }

        pending[key] = state;

        if (timers[key]) {
            clearTimeout(timers[key]);
        }

        timers[key] = setTimeout(function () {
            var payload = {};
            payload[key] = pending[key];

            say(I18N.saving, false);

            patchProgress({ tool_state: payload })
                .then(function () { say(I18N.saved, false); })
                .catch(function () { say(I18N.failed, true); });
        }, 600);
    }

    // The block script reads these during init. Set before DOMContentLoaded
    // fires for the blocks, because this file is enqueued after it but both
    // run their boot on the same event — assignment here happens at parse
    // time, which is earlier than either boot.
    window.TTKnowledge = window.TTKnowledge || {};
    window.TTKnowledge.savedState = cfg.state || {};
    window.TTKnowledge.persist = persist;

    /* ── mark as read ───────────────────────────────────────────────── */

    function wireMarkRead() {
        var button = document.querySelector('[data-tt-mark-read]');
        if (!button) {
            return;
        }

        var form = button.closest('form');
        if (!form) {
            return;
        }

        form.addEventListener('submit', function (event) {
            // Let the plain POST happen if fetch is unavailable.
            if (typeof window.fetch !== 'function') {
                return;
            }

            event.preventDefault();
            button.disabled = true;
            say(I18N.saving, false);

            patchProgress({ read: true })
                .then(function () {
                    var done = document.createElement('p');
                    done.className = 'tt-knowledge-lesson__read';
                    done.setAttribute('data-tt-read-state', '');
                    done.textContent = form.getAttribute('data-tt-read-label') || I18N.saved;
                    form.replaceWith(done);
                    say('', false);

                    // The completion block's "next lesson" link and the
                    // course percentage are both server-rendered, so a
                    // reload is the honest way to reflect the new state
                    // rather than half-updating the page from the client.
                    window.location.reload();
                })
                .catch(function () {
                    button.disabled = false;
                    say(I18N.failed, true);
                });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', wireMarkRead);
    } else {
        wireMarkRead();
    }
}());
