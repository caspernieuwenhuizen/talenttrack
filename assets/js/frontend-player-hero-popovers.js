/* Player profile hero — quick-record popovers (#870).
 *
 * Vanilla JS, no jQuery. Reads config from window.TTPlayerHeroPopovers
 * which the PHP view localises with: rest_url, rest_nonce, player_id,
 * activities (list of { id, label }), i18n strings.
 */
(function () {
    'use strict';

    var cfg = window.TTPlayerHeroPopovers || null;
    if ( ! cfg || ! cfg.rest_url || ! cfg.rest_nonce ) return;

    var rest   = String(cfg.rest_url).replace(/\/+$/, '/');
    var nonce  = String(cfg.rest_nonce);
    var pid    = parseInt(cfg.player_id, 10);
    if ( ! pid ) return;

    var i18n = cfg.i18n || {};

    var overlay = null;
    var lastFocus = null;
    var firstField = null;

    function el(tag, attrs, children) {
        var node = document.createElement(tag);
        if (attrs) {
            for (var k in attrs) {
                if (k === 'class')      node.className = attrs[k];
                else if (k === 'text')  node.textContent = attrs[k];
                else if (k === 'html')  node.innerHTML  = attrs[k];
                else                    node.setAttribute(k, attrs[k]);
            }
        }
        if (children) {
            children.forEach(function (c) { if (c) node.appendChild(c); });
        }
        return node;
    }

    function showToast(msg) {
        var t = el('div', { class: 'tt-pp-toast', role: 'status', 'aria-live': 'polite', text: msg });
        document.body.appendChild(t);
        // next frame so transition applies
        requestAnimationFrame(function () { t.setAttribute('data-visible', 'true'); });
        setTimeout(function () {
            t.removeAttribute('data-visible');
            setTimeout(function () { if (t.parentNode) t.parentNode.removeChild(t); }, 250);
        }, 2200);
    }

    function closeOverlay() {
        if ( ! overlay ) return;
        overlay.removeAttribute('data-open');
        document.removeEventListener('keydown', onKey);
        if (overlay.parentNode) overlay.parentNode.removeChild(overlay);
        overlay = null;
        if (lastFocus && typeof lastFocus.focus === 'function') {
            try { lastFocus.focus(); } catch (e) {}
        }
    }

    function onKey(e) {
        if (e.key === 'Escape') {
            e.preventDefault();
            closeOverlay();
            return;
        }
        if (e.key === 'Tab' && overlay) {
            // simple focus trap — keep tab inside the panel
            var focusables = overlay.querySelectorAll(
                'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
            );
            if ( ! focusables.length ) return;
            var first = focusables[0];
            var last  = focusables[focusables.length - 1];
            if (e.shiftKey && document.activeElement === first) {
                e.preventDefault();
                last.focus();
            } else if ( ! e.shiftKey && document.activeElement === last) {
                e.preventDefault();
                first.focus();
            }
        }
    }

    function openPopover(kind, opts) {
        lastFocus = document.activeElement;
        closeOverlay();

        overlay = el('div', {
            class: 'tt-pp-overlay',
            role: 'dialog',
            'aria-modal': 'true',
            'aria-labelledby': 'tt-pp-title'
        });

        var panel = el('div', { class: 'tt-pp-panel' });
        var header = el('div', { class: 'tt-pp-header' }, [
            el('h2', { class: 'tt-pp-title', id: 'tt-pp-title', text: opts.title }),
            el('button', { type: 'button', class: 'tt-pp-close', 'aria-label': i18n.close || 'Close', html: '&times;' })
        ]);
        panel.appendChild(header);

        var form = el('form', { class: 'tt-pp-form', novalidate: '' });
        opts.fields.forEach(function (f) { form.appendChild(f); });

        var footer = el('div', { class: 'tt-pp-footer' });
        if (opts.historyHref) {
            var hl = el('a', {
                class: 'tt-pp-history-link',
                href:  opts.historyHref,
                text:  opts.historyText
            });
            footer.appendChild(hl);
        }
        footer.appendChild(el('button', { type: 'button', class: 'tt-btn tt-btn-secondary tt-pp-cancel', text: i18n.cancel || 'Cancel' }));
        footer.appendChild(el('button', { type: 'submit', class: 'tt-btn tt-btn-primary tt-pp-submit', text: opts.submitText }));
        form.appendChild(footer);
        panel.appendChild(form);

        var errBox = el('p', { class: 'tt-pp-error', role: 'alert' });
        errBox.style.display = 'none';
        panel.appendChild(errBox);

        overlay.appendChild(panel);
        document.body.appendChild(overlay);
        overlay.setAttribute('data-open', 'true');

        // close behaviour
        header.querySelector('.tt-pp-close').addEventListener('click', closeOverlay);
        form.querySelector('.tt-pp-cancel').addEventListener('click', closeOverlay);
        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) closeOverlay();
        });
        document.addEventListener('keydown', onKey);

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var btn = form.querySelector('.tt-pp-submit');
            if (btn) btn.disabled = true;
            errBox.style.display = 'none';
            errBox.textContent = '';

            var body = opts.collect(form);
            if (body === null) {
                if (btn) btn.disabled = false;
                return;
            }
            fetch(rest + opts.endpoint, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': nonce,
                    'Accept': 'application/json'
                },
                body: JSON.stringify(body)
            }).then(function (r) {
                return r.json().then(function (j) { return { ok: r.ok, status: r.status, body: j }; });
            }).then(function (resp) {
                if ( ! resp.ok || (resp.body && resp.body.code && resp.body.code !== 'success') ) {
                    var msg = (resp.body && resp.body.message) || (i18n.error_generic || 'Could not save.');
                    errBox.textContent = msg;
                    errBox.style.display = 'block';
                    if (btn) btn.disabled = false;
                    return;
                }
                showToast(opts.successText);
                closeOverlay();
            }).catch(function () {
                errBox.textContent = i18n.error_network || 'Network error. Try again.';
                errBox.style.display = 'block';
                if (btn) btn.disabled = false;
            });
        });

        // focus first field
        firstField = panel.querySelector('select, input, textarea, button:not(.tt-pp-close)');
        if (firstField) try { firstField.focus(); } catch (e) {}
    }

    function makeField(labelText, control) {
        var wrap = el('div', { class: 'tt-pp-field' });
        wrap.appendChild(el('label', { class: 'tt-pp-field-label', text: labelText }));
        wrap.appendChild(control);
        return wrap;
    }

    function makeBehaviourForm() {
        var min = cfg.rating_min || 5;
        var max = cfg.rating_max || 10;
        var sel = el('select', { class: 'tt-input', name: 'rating', required: '' });
        sel.appendChild(el('option', { value: '', text: i18n.rating_placeholder || '— pick a rating —' }));
        for (var v = min; v <= max; v++) {
            sel.appendChild(el('option', { value: String(v), text: String(v) }));
        }

        var act = el('select', { class: 'tt-input', name: 'related_activity_id' });
        act.appendChild(el('option', { value: '0', text: i18n.activity_none || '— none —' }));
        (cfg.activities || []).forEach(function (a) {
            act.appendChild(el('option', { value: String(a.id), text: a.label }));
        });

        var notes = el('textarea', {
            class: 'tt-input',
            name: 'notes',
            rows: '2',
            placeholder: i18n.notes_placeholder || 'Optional context'
        });

        return [
            makeField(i18n.rating_label || 'Rating', sel),
            makeField(i18n.activity_label || 'Related activity (optional)', act),
            makeField(i18n.notes_label || 'Notes', notes)
        ];
    }

    function collectBehaviour(form) {
        var rating = parseFloat(form.querySelector('[name="rating"]').value);
        if (isNaN(rating) || rating <= 0) {
            return null;
        }
        var aid = parseInt(form.querySelector('[name="related_activity_id"]').value, 10);
        var notes = (form.querySelector('[name="notes"]').value || '').trim();
        var body = { rating: rating };
        if (aid > 0) body.related_activity_id = aid;
        if (notes !== '') body.notes = notes;
        return body;
    }

    function makePotentialForm() {
        var bands = (cfg.potential_bands || []);
        var sel = el('select', { class: 'tt-input', name: 'potential_band', required: '' });
        sel.appendChild(el('option', { value: '', text: i18n.band_placeholder || '— pick a band —' }));
        bands.forEach(function (b) {
            var opt = el('option', { value: b.key, text: b.label });
            if (b.key === cfg.current_potential_band) opt.setAttribute('selected', 'selected');
            sel.appendChild(opt);
        });

        var notes = el('textarea', {
            class: 'tt-input',
            name: 'notes',
            rows: '2',
            placeholder: i18n.notes_placeholder || 'Optional context'
        });

        return [
            makeField(i18n.band_label || 'Potential band', sel),
            makeField(i18n.notes_label || 'Notes', notes)
        ];
    }

    function collectPotential(form) {
        var band = (form.querySelector('[name="potential_band"]').value || '').trim();
        if ( ! band) return null;
        var notes = (form.querySelector('[name="notes"]').value || '').trim();
        var body = { potential_band: band };
        if (notes !== '') body.notes = notes;
        return body;
    }

    document.addEventListener('click', function (e) {
        var t = e.target && e.target.closest ? e.target.closest('[data-tt-popover-trigger]') : null;
        if ( ! t) return;
        e.preventDefault();
        var kind = t.getAttribute('data-tt-popover-trigger');
        if (kind === 'behaviour') {
            openPopover('behaviour', {
                title: i18n.log_behaviour_title || 'Log behaviour',
                endpoint: 'players/' + pid + '/behaviour-ratings',
                fields: makeBehaviourForm(),
                collect: collectBehaviour,
                submitText: i18n.save_behaviour || 'Save rating',
                successText: i18n.success_behaviour || 'Behaviour recorded',
                historyHref: cfg.history_url,
                historyText: i18n.view_all_behaviour || 'View all behaviour ratings →'
            });
        } else if (kind === 'potential') {
            openPopover('potential', {
                title: i18n.set_potential_title || 'Set potential',
                endpoint: 'players/' + pid + '/potential',
                fields: makePotentialForm(),
                collect: collectPotential,
                submitText: i18n.save_potential || 'Update potential',
                successText: i18n.success_potential || 'Potential updated',
                historyHref: cfg.history_url,
                historyText: i18n.view_all_potential || 'View potential history →'
            });
        }
    });
}());
