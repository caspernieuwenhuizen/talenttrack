/**
 * TalentTrack — frontend dashboard script.
 *
 * #0019 Sprint 1 session 2: rewritten as vanilla JS + fetch() against
 * the REST API. jQuery is no longer a dependency. Forms declare their
 * REST target via `data-rest-path` (path relative to
 * `/wp-json/talenttrack/v1/`) plus optional `data-rest-method`
 * (default POST). Inline goal status / delete handlers hit
 * `/goals/{id}/status` (PATCH) and `/goals/{id}` (DELETE).
 */
(function(){
    'use strict';

    var i18n = (window.TT && TT.i18n) ? TT.i18n : {
        saving: 'Saving...',
        saved: 'Saved.',
        error_generic: 'Error.',
        network_error: 'Network error.',
        confirm_delete_goal: 'Delete this goal?',
        save_evaluation: 'Save Evaluation',
        save_activity: 'Save Activity',
        add_goal: 'Add Goal',
        save: 'Save'
    };

    /**
     * Turn a submitted <form> into a plain object, expanding bracketed
     * names (`ratings[12]=4.5`, `att[7][status]=Present`) into nested
     * objects. The REST controllers read these as sub-resources, matching
     * the shape the legacy admin-ajax handlers accepted.
     */
    function formToJSON(form) {
        var out = {};
        var fd = new FormData(form);
        fd.forEach(function(value, key) {
            // Skip the legacy hidden fields — REST uses headers + path.
            if (key === 'action' || key === 'nonce' || key === '_wpnonce') return;
            var match = key.match(/^([^\[]+)((?:\[[^\]]*\])*)$/);
            if (!match) { out[key] = value; return; }
            var base = match[1];
            var rest = match[2];
            if (!rest) { out[base] = value; return; }
            var keys = [];
            rest.replace(/\[([^\]]*)\]/g, function(_m, k) { keys.push(k); return ''; });
            // name[] case — push to array on the base key.
            if (keys.length === 1 && keys[0] === '') {
                if (!Array.isArray(out[base])) out[base] = [];
                out[base].push(value);
                return;
            }
            var cursor = out[base] = out[base] || {};
            for (var i = 0; i < keys.length - 1; i++) {
                var k = keys[i];
                cursor[k] = cursor[k] || {};
                cursor = cursor[k];
            }
            cursor[keys[keys.length - 1]] = value;
        });
        return out;
    }

    function restRequest(path, method, body) {
        var base = (window.TT && TT.rest_url) ? TT.rest_url : '/wp-json/talenttrack/v1/';
        var url = base.replace(/\/+$/, '/') + path.replace(/^\/+/, '');
        var headers = { 'Content-Type': 'application/json', 'Accept': 'application/json' };
        if (window.TT && TT.rest_nonce) headers['X-WP-Nonce'] = TT.rest_nonce;
        return fetch(url, {
            method: method,
            credentials: 'same-origin',
            headers: headers,
            body: body ? JSON.stringify(body) : undefined
        }).then(function(res) {
            return res.json().then(function(json) { return { status: res.status, ok: res.ok, json: json }; });
        });
    }

    function firstErrorMessage(json) {
        if (json && Array.isArray(json.errors) && json.errors.length > 0 && json.errors[0].message) {
            return json.errors[0].message;
        }
        if (json && json.message) return json.message;
        return i18n.error_generic;
    }

    function showMsg(form, type, text) {
        var el = form.querySelector('.tt-form-msg');
        if (!el) return;
        el.classList.remove('tt-success', 'tt-error');
        el.classList.add(type === 'success' ? 'tt-success' : 'tt-error');
        el.textContent = text;
        el.style.display = '';
    }

    function clearMsg(form) {
        var el = form.querySelector('.tt-form-msg');
        if (!el) return;
        el.classList.remove('tt-success', 'tt-error');
        el.textContent = '';
        el.style.display = 'none';
    }

    function defaultButtonLabel(formId) {
        switch (formId) {
            case 'tt-eval-form':    return i18n.save_evaluation;
            case 'tt-activity-form': return i18n.save_activity;
            case 'tt-goal-form':    return i18n.add_goal;
            default:                return i18n.save;
        }
    }

    /**
     * Drive a FormSaveButton through its idle/saving/saved/error states.
     * Falls back to the old behaviour (simple text swap) for plain
     * `<button type="submit">` buttons without the component class.
     */
    function setSaveBtnState(btn, state) {
        if (!btn) return;
        if (!btn.classList.contains('tt-save-btn')) {
            // Legacy button — just flip text + disabled.
            if (state === 'saving') { btn.disabled = true; btn.textContent = i18n.saving; }
            else { btn.disabled = false; btn.textContent = defaultButtonLabel(btn.form ? btn.form.id : ''); }
            return;
        }
        btn.setAttribute('data-state', state);
        btn.disabled = (state === 'saving');
        var labelEl = btn.querySelector('.tt-save-btn-label');
        if (!labelEl) return;
        var key = 'data-label-' + state;
        var label = btn.getAttribute(key);
        if (label) labelEl.textContent = label;
    }

    function on(selector, event, handler) {
        document.addEventListener(event, function(e) {
            var target = e.target.closest(selector);
            if (target) handler.call(target, e);
        });
    }

    document.addEventListener('DOMContentLoaded', function() {

        // Tab switching — legacy `data-tab` pattern (tabs share a page,
        // each one matched to a `.tt-tab-content[data-tab="..."]` pane).
        // Modern surfaces (CustomCss editor, Trial-case detail) use real
        // <a href> links that should navigate — they don't carry
        // `data-tab`, so we let those through unmolested. Without this
        // guard the delegated handler would `preventDefault` every
        // `.tt-tab` click and then fail silently because no content pane
        // matches.
        on('.tt-dashboard .tt-tab', 'click', function(e) {
            var tab = this.getAttribute('data-tab');
            if (!tab) return; // real <a href> link — let the browser navigate
            e.preventDefault();
            var root = this.closest('.tt-dashboard');
            if (!root) return;
            root.querySelectorAll('.tt-tab').forEach(function(t) { t.classList.remove('tt-tab-active'); });
            this.classList.add('tt-tab-active');
            root.querySelectorAll('.tt-tab-content').forEach(function(c) { c.classList.remove('tt-tab-content-active'); });
            var active = root.querySelector('.tt-tab-content[data-tab="' + tab + '"]');
            if (active) active.classList.add('tt-tab-content-active');
            if (history.replaceState) {
                var url = new URL(window.location.href);
                url.searchParams.set('tt_view', tab);
                history.replaceState({}, '', url);
            }
        });

        // REST form submission
        on('.tt-ajax-form', 'submit', function(e) {
            e.preventDefault();
            var form = this;
            var path = form.getAttribute('data-rest-path');
            if (!path) {
                showMsg(form, 'error', i18n.error_generic);
                return;
            }
            var method = (form.getAttribute('data-rest-method') || 'POST').toUpperCase();
            var btn = form.querySelector('button[type="submit"]');
            clearMsg(form);
            setSaveBtnState(btn, 'saving');

            restRequest(path, method, formToJSON(form)).then(function(res) {
                if (res.ok && res.json && res.json.success) {
                    showMsg(form, 'success', i18n.saved);
                    setSaveBtnState(btn, 'saved');
                    // Drafts module listens for this to clear its stored snapshot.
                    form.dispatchEvent(new CustomEvent('tt:form-saved', { bubbles: true }));
                    var redirect = form.getAttribute('data-redirect-after-save');
                    var redirectUrl = form.getAttribute('data-redirect-after-save-url');
                    if (redirect === '1' || redirect === 'list') {
                        // Briefly show success, then drop the form-mode query
                        // params so the user lands back on the section's list
                        // view. `tt_view` is preserved so they don't get
                        // bounced to the tile grid (which was the old "1"
                        // behaviour and meant "save & lose context").
                        setTimeout(function() {
                            try {
                                var url = new URL(window.location.href);
                                url.searchParams.delete('action');
                                url.searchParams.delete('id');
                                url.searchParams.delete('edit');
                                window.location.href = url.toString();
                            } catch (e) {
                                window.location.href = window.location.pathname;
                            }
                        }, 1200);
                    } else if (redirect === 'reload') {
                        // v3.92.5 — reload the current URL in place so the
                        // page re-renders with the saved state. Used by
                        // surfaces where the form lives inside a card on
                        // the same page as the data it edits (e.g. PDP
                        // conversation save/sign cards on the file view).
                        setTimeout(function() { window.location.reload(); }, 1200);
                    } else if (redirectUrl) {
                        // v3.92.5 — explicit URL redirect. Used by the
                        // coach-side PDP conversation form to land back on
                        // the parent file view after save / sign.
                        setTimeout(function() { window.location.href = redirectUrl; }, 1200);
                    } else {
                        form.reset();
                        setTimeout(function() { setSaveBtnState(btn, 'idle'); }, 1500);
                    }
                } else {
                    showMsg(form, 'error', firstErrorMessage(res.json));
                    setSaveBtnState(btn, 'error');
                    setTimeout(function() { setSaveBtnState(btn, 'idle'); }, 2500);
                }
            }).catch(function() {
                showMsg(form, 'error', i18n.network_error);
                setSaveBtnState(btn, 'error');
                setTimeout(function() { setSaveBtnState(btn, 'idle'); }, 2500);
            });
        });

        // Goal status inline update — PATCH /goals/{id}/status
        on('.tt-dashboard .tt-goal-status-select', 'change', function() {
            var id = this.getAttribute('data-goal-id');
            if (!id) return;
            restRequest('goals/' + encodeURIComponent(id) + '/status', 'PATCH', { status: this.value });
        });

        // Goal delete — DELETE /goals/{id}
        on('.tt-dashboard .tt-goal-delete', 'click', function(e) {
            e.preventDefault();
            var btn = this;
            var id = btn.getAttribute('data-goal-id');
            if (!id) return;
            var doDelete = function() {
                restRequest('goals/' + encodeURIComponent(id), 'DELETE', null).then(function(res) {
                    if (res.ok && res.json && res.json.success) {
                        if (window.ttFlash && window.ttFlash.addNear) {
                            window.ttFlash.addNear(btn, 'success', i18n.deleted_goal || 'Goal deleted.');
                        }
                        var row = btn.closest('tr');
                        if (row) {
                            row.style.transition = 'opacity 0.25s';
                            row.style.opacity = '0';
                            setTimeout(function() { if (row.parentNode) row.parentNode.removeChild(row); }, 260);
                        }
                    }
                });
            };
            if (typeof window.ttConfirm === 'function') {
                window.ttConfirm({
                    title:        i18n.confirm_delete_goal_title || 'Delete goal?',
                    message:      i18n.confirm_delete_goal || 'Are you sure you want to delete this goal?',
                    confirmLabel: i18n.delete_label || 'Delete',
                    danger:       true
                }).then(function(ok) { if (ok) doDelete(); });
            } else if (window.confirm(i18n.confirm_delete_goal || 'Delete this goal?')) {
                doDelete();
            }
        });
    });

    // ─── Generic dropdown dependency — `data-tt-depends-on` ──────
    //
    // Any <select> can declare `data-tt-depends-on="other_field_name"`
    // plus either:
    //   - `data-tt-options-source="rest:path/with/{value}"` for a REST
    //     fetch keyed on the new parent value, or
    //   - `data-tt-options-map='{"a":[["1","X"]],"b":[["2","Y"]]}'` for
    //     a static client-side mapping (no REST call).
    //
    // When the parent <select> changes, the dependent select rebuilds
    // its options. The previously-selected value is preserved if it
    // still exists in the new option set; otherwise the dependent
    // resets to its placeholder option (or first option).
    //
    // Sweep target: any cascading dropdown (new-goal wizard's
    // link_type → link_id, position → preferred_action, team → player
    // picker, etc.). Each form opts in via the data-attribute pair.
    document.addEventListener('change', function(e) {
        var dependents = document.querySelectorAll('select[data-tt-depends-on]');
        if (!dependents.length) return;
        var changedName = e.target && e.target.name;
        if (!changedName) return;
        dependents.forEach(function(dep) {
            if (dep.dataset.ttDependsOn !== changedName) return;
            var prevValue = dep.value;
            // Static-map mode: instant client-side rebuild.
            if (dep.dataset.ttOptionsMap) {
                try {
                    var map = JSON.parse(dep.dataset.ttOptionsMap);
                    var rows = (map && map[e.target.value]) || [];
                    rebuildSelect(dep, rows, prevValue);
                } catch (err) { /* malformed JSON — leave alone */ }
                return;
            }
            // REST mode: fetch options keyed on the new parent value.
            var src = dep.dataset.ttOptionsSource || '';
            if (src.indexOf('rest:') !== 0) return;
            var path = src.substring(5).replace('{value}', encodeURIComponent(e.target.value));
            var base = (window.TT && TT.rest_url) ? TT.rest_url : '/wp-json/talenttrack/v1/';
            dep.disabled = true;
            fetch(base + path, {
                credentials: 'same-origin',
                headers: { 'X-WP-Nonce': (window.TT && TT.rest_nonce) || '' }
            })
            .then(function(r) { return r.ok ? r.json() : []; })
            .then(function(data) {
                // Accept either [["value","label"], …] or [{value, label}, …].
                var rows = Array.isArray(data) ? data.map(function(row) {
                    if (Array.isArray(row)) return row;
                    return [row.value, row.label != null ? row.label : row.value];
                }) : [];
                rebuildSelect(dep, rows, prevValue);
            })
            .catch(function() { /* leave existing options in place */ })
            .finally(function() { dep.disabled = false; });
        });
    });

    function rebuildSelect(select, rows, prevValue) {
        // Preserve a placeholder option (first option with empty value).
        var placeholder = select.options.length > 0 && select.options[0].value === ''
            ? select.options[0].cloneNode(true)
            : null;
        select.innerHTML = '';
        if (placeholder) select.appendChild(placeholder);
        var matched = false;
        rows.forEach(function(row) {
            var opt = document.createElement('option');
            opt.value = String(row[0]);
            opt.textContent = String(row[1] != null ? row[1] : row[0]);
            if (opt.value === prevValue) { opt.selected = true; matched = true; }
            select.appendChild(opt);
        });
        if (!matched && placeholder) placeholder.selected = true;
        // Fire change so any cascade further down the chain refreshes too.
        select.dispatchEvent(new Event('change', { bubbles: true }));
    }
})();
