/**
 * match-analysis.js — the share-link controls on the match-analysis surface.
 *
 * Three actions, all on one block: create the link (it does not exist until
 * somebody asks for it), copy it, and replace it. Everything else on the
 * page is a plain form that public.js already submits over REST.
 *
 * Creating and replacing update the block in place rather than reloading:
 * the coach is usually mid-analysis when they reach for the link, and
 * reloading would throw away anything they had typed and not yet saved.
 */
(function () {
    'use strict';

    var strings = window.TT_MatchAnalysis || {};

    function t(key, fallback) {
        return (strings[key] !== undefined && strings[key] !== '') ? strings[key] : fallback;
    }

    function restBase() {
        return (window.TT && TT.rest_url) ? TT.rest_url : '/wp-json/talenttrack/v1/';
    }

    function post(path) {
        return fetch(restBase() + path, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': (window.TT && TT.rest_nonce) || ''
            }
        }).then(function (res) { return res.json(); });
    }

    function flash(type, message) {
        if (window.ttFlash) { window.ttFlash.add(type, message); }
    }

    function block(el) {
        return el.closest ? el.closest('.tt-ma__share') : null;
    }

    function show(root, url) {
        var empty = root.querySelector('.tt-ma__share-empty');
        var live  = root.querySelector('.tt-ma__share-live');
        var field = root.querySelector('.tt-ma__share-url');

        if (field) field.value = url;
        if (empty) empty.hidden = true;
        if (live)  live.hidden = false;
    }

    function copy(root) {
        var field = root.querySelector('.tt-ma__share-url');
        if (!field || !field.value) return;

        var done = function () { flash('success', t('copied', 'Link copied.')); };

        // The async clipboard API needs a secure context; the fallback is
        // the old selection trick, which works on plain http — and a local
        // install is exactly where that matters.
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(field.value).then(done).catch(function () { select(field); });
            return;
        }
        select(field);
        try { document.execCommand('copy'); done(); } catch (e) { /* the URL is selected; the coach can copy it */ }
    }

    function select(field) {
        field.focus();
        field.setSelectionRange(0, field.value.length);
    }

    document.addEventListener('click', function (e) {
        if (!e.target.closest) return;

        var create = e.target.closest('.tt-ma__share-create');
        if (create) {
            e.preventDefault();
            var root = block(create);
            if (!root) return;
            create.disabled = true;

            post('activities/' + root.getAttribute('data-activity-id') + '/analysis/share')
                .then(function (json) {
                    if (!json || !json.success) throw new Error('create failed');
                    show(root, json.data.share_url);
                    flash('success', t('created', 'Share link created.'));
                })
                .catch(function () {
                    create.disabled = false;
                    flash('error', t('failed', 'The link could not be created.'));
                });
            return;
        }

        var copyBtn = e.target.closest('.tt-ma__share-copy');
        if (copyBtn) {
            e.preventDefault();
            var copyRoot = block(copyBtn);
            if (copyRoot) copy(copyRoot);
            return;
        }

        var rotate = e.target.closest('.tt-ma__share-rotate');
        if (rotate) {
            e.preventDefault();
            var rotateRoot = block(rotate);
            if (!rotateRoot) return;
            if (!window.confirm(t('confirmRotate', 'Replace the share link? The current one stops working immediately.'))) return;

            rotate.disabled = true;
            post('activities/' + rotateRoot.getAttribute('data-activity-id') + '/analysis/share/rotate')
                .then(function (json) {
                    if (!json || !json.success) throw new Error('rotate failed');
                    show(rotateRoot, json.data.share_url);
                    rotate.disabled = false;
                    flash('success', t('rotated', 'A new link has been issued. The previous one no longer works.'));
                })
                .catch(function () {
                    rotate.disabled = false;
                    flash('error', t('failed', 'The link could not be replaced.'));
                });
        }
    });

    // -----------------------------------------------------------------
    // Autosave (#3007, epic #2881)
    // -----------------------------------------------------------------

    /**
     * The analysis form used to be a `tt-ajax-form` with a Save button.
     * A coach writing up a game on a phone after the final whistle is
     * composing over minutes, not filling in a record, so it autosaves.
     *
     * #3008 moved the generic half of this — serialising the form, putting
     * an old payload back into it for undo and revert, and the listener
     * wiring — into `TT.FormAutosave`, because four more surfaces needed
     * exactly the same thing. What is left here is what is genuinely this
     * surface's:
     *
     *  - **The version token.** Every write carries `base_updated_at`, the
     *    `updated_at` last seen, and every response refreshes it. A 409
     *    means a second coach has written since, and the surface halts
     *    rather than composing over them.
     *  - **Marking final.** The one deliberate commit left on the page. It
     *    is a publish, not a save — see the share-link behaviour.
     */
    (function () {
        var form = document.querySelector('[data-tt-ma-form]');
        if (!form || !window.TT || !TT.FormAutosave) return;

        var url      = restBase() + String(form.getAttribute('data-rest-path') || '');
        var finalise = form.querySelector('[data-tt-ma-finalise]');
        var note     = form.querySelector('[data-tt-ma-final-note]');
        var base     = String(form.getAttribute('data-updated') || '');

        /**
         * The version token rides in the query string, not in the body.
         *
         * That is not cosmetic: the body is also what undo and revert
         * snapshot, and a token that changed on every save would show up in
         * the diff as a changed field — so the surface would offer to
         * revert a record nobody had touched. Empty means "no version to
         * have been composed against", which is a first write, and the
         * server treats an absent token as opt-out.
         */
        function endpoint() {
            return base ? url + '?base_updated_at=' + encodeURIComponent(base) : url;
        }

        function remember(resp) {
            var data = resp && resp.data;
            if (data && typeof data.updated_at === 'string') base = data.updated_at;
        }

        var saver = TT.FormAutosave.attach(form, {
            endpoint:   endpoint,
            storageKey: 'match-analysis:' + String(form.getAttribute('data-rest-path') || ''),
            onSaved:    remember,
            onError: function (err) {
                // 409 is the one failure retrying makes worse: the record
                // moved, so every further write would overwrite whoever
                // moved it. Stop, and say so.
                if (err && err.status === 409) {
                    saver.halt(t('conflict', 'Someone else changed this analysis. Reload the page to see their version.'));
                }
            }
        });
        if (!saver) return;

        if (finalise) {
            finalise.addEventListener('click', function () {
                if (!window.confirm(t('confirmFinal', 'Mark this analysis final? Anyone holding the share link can then read it.'))) return;

                finalise.disabled = true;

                // Flush what is in the fields first, so the document being
                // published is the one on screen rather than the one the
                // debounce had got to.
                saver.saveNow().then(function () {
                    return saver.send({
                        method: 'PUT',
                        url: endpoint(),
                        body: { status: 'final' }
                    });
                }).then(function (resp) {
                    finalise.disabled = false;
                    if (!resp || !resp.success) {
                        flash('error', t('finalFailed', 'The analysis could not be marked final. Try again.'));
                        return;
                    }
                    remember(resp);
                    finalise.hidden = true;
                    if (note) note.hidden = false;
                });
            });
        }
    }());
}());
