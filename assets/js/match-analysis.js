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
     * composing over minutes, not filling in a record, so it autosaves —
     * through the same `TT.Autosave` component match prep uses, which
     * brings the save state, undo and revert with it.
     *
     * Three things this wiring owns and the component does not:
     *
     *  - **Serialising the form.** `TT.formToJSON` is public.js's own
     *    bracket expansion, exposed rather than reimplemented so an
     *    autosave and a submit cannot reach the same endpoint with two
     *    different shapes.
     *  - **The version token.** Every write carries `base_updated_at`, the
     *    `updated_at` last seen, and every response refreshes it. A 409
     *    means a second coach has written since, and the surface halts
     *    rather than composing over them.
     *  - **Marking final.** The one deliberate commit left on the page. It
     *    is a publish, not a save — see the share-link behaviour.
     */
    (function () {
        var form = document.querySelector('[data-tt-ma-form]');
        if (!form || !window.TT || !TT.Autosave || typeof TT.formToJSON !== 'function') return;

        var url      = restBase() + String(form.getAttribute('data-rest-path') || '');
        var finalise = form.querySelector('[data-tt-ma-finalise]');
        var note     = form.querySelector('[data-tt-ma-final-note]');
        var base     = String(form.getAttribute('data-updated') || '');

        function body() {
            return TT.formToJSON(form);
        }

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

        var saver = TT.Autosave.create({
            stateEl:  form.querySelector('[data-tt-save-state]'),
            undoEl:   form.querySelector('[data-tt-save-undo]'),
            revertEl: form.querySelector('[data-tt-save-revert]'),
            storageKey: 'match-analysis:' + String(form.getAttribute('data-rest-path') || ''),
            nonce:    (window.TT && TT.rest_nonce) || '',
            delay:    600,
            i18n:     (window.TT_Autosave && window.TT_Autosave.i18n) || {},
            serialise: function () {
                return { method: 'PUT', url: endpoint(), body: body() };
            },
            apply: function (payload) { mount(payload); },
            onSaved: remember,
            onError: function (err) {
                // 409 is the one failure retrying makes worse: the record
                // moved, so every further write would overwrite whoever
                // moved it. Stop, and say so.
                if (err && err.status === 409) {
                    saver.halt(t('conflict', 'Someone else changed this analysis. Reload the page to see their version.'));
                }
            }
        });

        /**
         * Put a previously committed body back into the form — the inverse
         * of `TT.formToJSON`, and what makes undo and revert reach this
         * surface. Walks the payload rather than the DOM so a field the
         * snapshot does not mention is left alone rather than blanked.
         */
        function mount(payload) {
            if (!payload) return;

            Array.prototype.forEach.call(form.elements, function (el) {
                if (!el.name || el.disabled) return;

                var value = lookup(payload, el.name);

                // Absent is meaningful, not "skip": a rating the snapshot
                // does not carry is a rating that was not chosen, and an
                // undo that left it standing would restore three fields out
                // of four. Disabled controls are exempt because they were
                // never in the serialisation to begin with.
                if (el.type === 'radio' || el.type === 'checkbox') {
                    el.checked = value !== undefined && String(el.value) === String(value);
                    return;
                }
                el.value = value === undefined ? '' : value;
            });

            // The roster's chips and counters are drawn from the radios, so
            // they have to be told the radios moved.
            form.dispatchEvent(new CustomEvent('tt:ma-remounted', { bubbles: true }));
        }

        /** `players[12][marker]` -> payload.players['12'].marker */
        function lookup(payload, name) {
            var match = name.match(/^([^\[]+)((?:\[[^\]]*\])*)$/);
            if (!match) return undefined;

            var cursor = payload[match[1]];
            var keys   = [];
            match[2].replace(/\[([^\]]*)\]/g, function (_m, k) { keys.push(k); return ''; });

            for (var i = 0; i < keys.length; i++) {
                if (cursor == null || typeof cursor !== 'object') return undefined;
                cursor = cursor[keys[i]];
            }
            return cursor === undefined || cursor === null ? undefined : cursor;
        }

        saver.seed(body());

        form.addEventListener('input',  function () { saver.change(); });
        // Radios, selects and the roster's chips settle the moment they are
        // pressed, so they skip the typing debounce.
        form.addEventListener('change', function () { saver.change(60); });

        // Nothing on this form submits any more, but a stray Enter in a
        // text input still tries to. Swallow it and let the save that is
        // already queued do the work.
        form.addEventListener('submit', function (e) { e.preventDefault(); });

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
