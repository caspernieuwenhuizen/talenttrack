/**
 * TalentTrack — localStorage form drafts
 * #0019 Sprint 1 session 3
 *
 * Any form with a `data-draft-key` attribute opts in. On input change
 * we debounce-save a serialized form snapshot to
 * `localStorage['tt_draft_' + key]`. On page load, if a draft exists
 * for the key, we prompt the user whether to restore it; accepting
 * re-fills every matching input, declining clears it. A successful
 * submit clears the draft.
 *
 * Design notes:
 *   - Keys are expected to be stable (e.g. "eval-form"). For
 *     per-entity drafts the form template can append an id
 *     (`data-draft-key="eval-form-{player_id}"`).
 *   - Only form elements with a `name` attribute are captured.
 *   - Passwords and nonces are excluded via `data-draft-skip`.
 *   - Clears if the saved-at timestamp is older than 14 days so stale
 *     drafts don't pile up indefinitely.
 *   - This is NOT a sync story; it's purely local rescue for dropped
 *     connections. Sprint 7 PWA layer will reuse this as its offline
 *     base but sync is deferred.
 */
(function(){
    'use strict';

    var PREFIX = 'tt_draft_';
    var DEBOUNCE_MS = 400;
    var MAX_AGE_MS = 14 * 24 * 60 * 60 * 1000;

    function storageKey(form) { return PREFIX + form.getAttribute('data-draft-key'); }

    function hasLocalStorage() {
        try {
            var k = '__tt_draft_test__';
            window.localStorage.setItem(k, '1');
            window.localStorage.removeItem(k);
            return true;
        } catch (_) { return false; }
    }

    function snapshot(form) {
        var out = {};
        var fields = form.querySelectorAll('input[name], select[name], textarea[name]');
        fields.forEach(function(el) {
            if (el.getAttribute('data-draft-skip') !== null) return;
            if (el.type === 'password' || el.type === 'file' || el.type === 'hidden') return;
            if (el.type === 'checkbox' || el.type === 'radio') {
                if (el.checked) out[el.name] = el.value;
            } else if (el.tagName === 'SELECT' && el.multiple) {
                out[el.name] = Array.prototype.map.call(el.selectedOptions, function(o) { return o.value; });
            } else {
                out[el.name] = el.value;
            }
        });
        return out;
    }

    function apply(form, data) {
        Object.keys(data).forEach(function(name) {
            var els = form.querySelectorAll('[name="' + name + '"]');
            if (!els.length) return;
            var value = data[name];
            if (els[0].type === 'checkbox' || els[0].type === 'radio') {
                els.forEach(function(el) { el.checked = (el.value === value); });
            } else if (els[0].tagName === 'SELECT' && els[0].multiple && Array.isArray(value)) {
                Array.prototype.forEach.call(els[0].options, function(o) { o.selected = value.indexOf(o.value) !== -1; });
            } else {
                els[0].value = value;
            }
            els[0].dispatchEvent(new Event('change', { bubbles: true }));
        });
    }

    function save(form) {
        try {
            var payload = { at: Date.now(), data: snapshot(form) };
            window.localStorage.setItem(storageKey(form), JSON.stringify(payload));
        } catch (_) { /* quota / private mode — silently give up */ }
    }

    function read(form) {
        try {
            var raw = window.localStorage.getItem(storageKey(form));
            if (!raw) return null;
            var parsed = JSON.parse(raw);
            if (!parsed || !parsed.data) return null;
            if (parsed.at && Date.now() - parsed.at > MAX_AGE_MS) {
                window.localStorage.removeItem(storageKey(form));
                return null;
            }
            return parsed.data;
        } catch (_) { return null; }
    }

    function clear(form) {
        try { window.localStorage.removeItem(storageKey(form)); } catch (_) {}
    }

    function renderPrompt(form, onRestore, onDiscard) {
        var prompt = document.createElement('div');
        prompt.className = 'tt-draft-prompt';
        prompt.innerHTML =
            '<span class="tt-draft-prompt-body"></span>' +
            '<button type="button" class="tt-btn tt-btn-primary tt-draft-restore"></button>' +
            '<button type="button" class="tt-btn tt-btn-secondary tt-draft-discard"></button>';
        var i18n = (window.TT && window.TT.i18n) || {};
        prompt.querySelector('.tt-draft-prompt-body').textContent = i18n.draft_prompt || 'You have unsaved changes from an earlier session — restore?';
        prompt.querySelector('.tt-draft-restore').textContent = i18n.draft_restore || 'Restore';
        prompt.querySelector('.tt-draft-discard').textContent = i18n.draft_discard || 'Discard';
        prompt.querySelector('.tt-draft-restore').addEventListener('click', function() { onRestore(); prompt.remove(); });
        prompt.querySelector('.tt-draft-discard').addEventListener('click', function() { onDiscard(); prompt.remove(); });
        form.parentNode.insertBefore(prompt, form);
    }

    function debounce(fn, ms) {
        var t;
        return function() { clearTimeout(t); t = setTimeout(fn, ms); };
    }

    function wire(form) {
        if (!form.getAttribute('data-draft-key')) return;
        var existing = read(form);
        if (existing && Object.keys(existing).length > 0) {
            renderPrompt(form,
                function() { apply(form, existing); clear(form); },
                function() { clear(form); }
            );
        }
        var deb = debounce(function() { save(form); }, DEBOUNCE_MS);
        form.addEventListener('input', deb);
        form.addEventListener('change', deb);
        // Any consumer can dispatch `tt:form-saved` to declare success;
        // the REST submit handler in public.js does this.
        form.addEventListener('tt:form-saved', function() { clear(form); });
    }

    document.addEventListener('DOMContentLoaded', function() {
        if (!hasLocalStorage()) return;
        document.querySelectorAll('form[data-draft-key]').forEach(wire);
    });
})();
