/*
 * frontend-backups.js — FrontendBackupsView (#1937).
 *
 * Wires the frontend Backups view to the REST surface:
 *   POST   /wp-json/talenttrack/v1/backups/settings
 *   POST   /wp-json/talenttrack/v1/backups/run
 *   DELETE /wp-json/talenttrack/v1/backups/{id}
 *   GET    /wp-json/talenttrack/v1/backups/{id}/preview
 *   POST   /wp-json/talenttrack/v1/backups/{id}/restore        (typed-confirm RESTORE)
 *   POST   /wp-json/talenttrack/v1/backups/migration/preview   (multipart upload)
 *   POST   /wp-json/talenttrack/v1/backups/migration/dry-run
 *   POST   /wp-json/talenttrack/v1/backups/migration/commit    (typed-confirm IMPORT)
 *
 * The view composes the payload; every gate (cap, nonce, typed-confirm,
 * impersonation, audit) is enforced server-side in BackupRestController.
 * The .ttmig export + per-backup download are plain browser navigations
 * (binary streams, not JSON), so they stay as <a>/<form> in the view.
 * Strings come from the localised TT_Backups object — no hard-coded
 * English.
 */
(function () {
    'use strict';

    var root = document.querySelector('[data-tt-backups]');
    if (!root) return;

    var cfg = window.TT_Backups || {};
    var i18n = cfg.i18n || {};
    var rest = ((window.TT && window.TT.rest_url) || '/wp-json/talenttrack/v1/').replace(/\/+$/, '/');
    var nonce = (window.TT && window.TT.rest_nonce) || (window.wpApiSettings && window.wpApiSettings.nonce) || '';

    var msg = root.querySelector('[data-tt-backups-msg]');

    function t(key, fallback) { return i18n[key] || fallback || ''; }

    function headers(json) {
        var h = { 'Accept': 'application/json' };
        if (json) h['Content-Type'] = 'application/json';
        if (nonce) h['X-WP-Nonce'] = nonce;
        return h;
    }

    function firstError(j) {
        return (j && j.errors && j.errors[0] && j.errors[0].message) || '';
    }

    function setMsg(text, kind) {
        if (!msg) return;
        msg.className = 'tt-backups__msg' + (kind ? ' tt-' + kind : '');
        msg.textContent = text || '';
        if (text) msg.scrollIntoView({ block: 'nearest' });
    }

    function reqJson(path, body, method) {
        return fetch(rest + path, {
            method: method || 'POST',
            credentials: 'same-origin',
            headers: headers(true),
            body: body ? JSON.stringify(body) : undefined
        }).then(function (res) {
            return res.json().then(function (j) { return { ok: res.ok, json: j }; });
        });
    }

    function reqForm(path, formData) {
        return fetch(rest + path, {
            method: 'POST',
            credentials: 'same-origin',
            headers: headers(false),
            body: formData
        }).then(function (res) {
            return res.json().then(function (j) { return { ok: res.ok, json: j }; });
        });
    }

    function reloadSoon() {
        setTimeout(function () { window.location.reload(); }, 900);
    }

    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) n.className = cls;
        if (text != null) n.textContent = text;
        return n;
    }

    function esc(s) { return String(s == null ? '' : s); }

    // ---- Preset description swap ---------------------------------------
    var presetSel = root.querySelector('[data-tt-backups-preset]');
    var presetDesc = root.querySelector('[data-tt-backups-preset-desc]');
    if (presetSel && presetDesc) {
        var descriptions = {};
        try { descriptions = JSON.parse(presetSel.getAttribute('data-descriptions') || '{}'); } catch (e) { descriptions = {}; }
        presetSel.addEventListener('change', function () {
            presetDesc.textContent = descriptions[presetSel.value] || '';
        });
    }

    // ---- Save settings -------------------------------------------------
    var settingsForm = root.querySelector('[data-tt-backups-settings-form]');
    if (settingsForm) {
        settingsForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var btn = settingsForm.querySelector('.tt-save-btn');
            if (btn) btn.setAttribute('data-state', 'saving');
            setMsg('', '');
            var fd = new FormData(settingsForm);
            reqJson('backups/settings', {
                preset: String(fd.get('preset') || ''),
                schedule: String(fd.get('schedule') || ''),
                retention: parseInt(fd.get('retention') || '30', 10),
                selected_tables: String(fd.get('selected_tables') || ''),
                dest_local: fd.get('dest_local') ? 1 : 0,
                dest_email: fd.get('dest_email') ? 1 : 0,
                email_recipients: String(fd.get('email_recipients') || '')
            }).then(function (r) {
                if (r.ok && r.json && r.json.success) {
                    if (btn) btn.setAttribute('data-state', 'saved');
                    setMsg(t('settings_saved'), 'success');
                    setTimeout(function () { if (btn) btn.setAttribute('data-state', 'idle'); }, 2000);
                } else {
                    if (btn) btn.setAttribute('data-state', 'error');
                    setMsg(firstError(r.json) || t('error'), 'error');
                }
            }).catch(function () {
                if (btn) btn.setAttribute('data-state', 'error');
                setMsg(t('network_error'), 'error');
            });
        });
    }

    // ---- Run now -------------------------------------------------------
    var runBtn = root.querySelector('[data-tt-backups-run]');
    if (runBtn) {
        runBtn.addEventListener('click', function () {
            runBtn.disabled = true;
            setMsg(t('running'), '');
            reqJson('backups/run', {}).then(function (r) {
                runBtn.disabled = false;
                if (r.ok && r.json && r.json.success) {
                    setMsg(t('run_ok'), 'success');
                    reloadSoon();
                } else {
                    setMsg(firstError(r.json) || t('error'), 'error');
                }
            }).catch(function () {
                runBtn.disabled = false;
                setMsg(t('network_error'), 'error');
            });
        });
    }

    // ---- Delete --------------------------------------------------------
    root.querySelectorAll('[data-tt-backups-delete]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id = btn.getAttribute('data-id') || '';
            if (!id) return;
            if (!window.confirm(t('delete_confirm'))) return;
            btn.disabled = true;
            setMsg('', '');
            reqJson('backups/' + encodeURIComponent(id), null, 'DELETE').then(function (r) {
                if (r.ok && r.json && r.json.success) {
                    setMsg(t('deleted'), 'success');
                    var tr = root.querySelector('tr[data-backup-id="' + (window.CSS && CSS.escape ? CSS.escape(id) : id) + '"]');
                    if (tr && tr.parentNode) tr.parentNode.removeChild(tr);
                } else {
                    btn.disabled = false;
                    setMsg(firstError(r.json) || t('error'), 'error');
                }
            }).catch(function () {
                btn.disabled = false;
                setMsg(t('network_error'), 'error');
            });
        });
    });

    // ---- Restore: preview then typed-confirm ---------------------------
    var restorePanel = root.querySelector('[data-tt-backups-restore-panel]');

    root.querySelectorAll('[data-tt-backups-restore]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id = btn.getAttribute('data-id') || '';
            if (!id || !restorePanel) return;
            setMsg('', '');
            restorePanel.hidden = false;
            restorePanel.textContent = t('previewing');
            reqJson('backups/' + encodeURIComponent(id) + '/preview', null, 'GET').then(function (r) {
                if (r.ok && r.json && r.json.success) {
                    renderRestorePanel(id, r.json.data);
                } else {
                    restorePanel.hidden = true;
                    setMsg(firstError(r.json) || t('error'), 'error');
                }
            }).catch(function () {
                restorePanel.hidden = true;
                setMsg(t('network_error'), 'error');
            });
        });
    });

    function renderRestorePanel(id, data) {
        restorePanel.textContent = '';
        var h = el('h3', null, t('restore_confirm'));
        restorePanel.appendChild(h);

        var p = el('p', 'tt-backups__danger-note');
        p.appendChild(el('strong', null, t('restore_intro')));
        var meta = (t('restore_meta') || '%1$s on %2$s')
            .replace('%1$s', esc(data.created_at) || '?')
            .replace('%2$s', esc(data.plugin_version) || '?');
        p.appendChild(document.createTextNode(' ' + meta));
        restorePanel.appendChild(p);

        var table = el('table', 'tt-backups__table');
        var thead = el('thead');
        var htr = el('tr');
        htr.appendChild(el('th', null, t('table')));
        htr.appendChild(el('th', null, t('rows')));
        thead.appendChild(htr);
        table.appendChild(thead);
        var tbody = el('tbody');
        (data.summary || []).forEach(function (row) {
            var tr = el('tr');
            var c1 = el('td'); c1.appendChild(el('code', null, esc(row.table))); tr.appendChild(c1);
            tr.appendChild(el('td', null, String(row.rows)));
            tbody.appendChild(tr);
        });
        table.appendChild(tbody);
        restorePanel.appendChild(table);

        var form = el('form', 'tt-backups__confirm-form');
        var field = el('div', 'tt-backups__field');
        var lab = el('label', 'tt-backups__legend', t('restore_type'));
        lab.setAttribute('for', 'tt-bk-restore-confirm');
        var input = el('input', 'tt-backups__input tt-backups__input--narrow');
        input.type = 'text';
        input.id = 'tt-bk-restore-confirm';
        input.name = 'confirm_text';
        input.placeholder = 'RESTORE';
        input.setAttribute('autocomplete', 'off');
        field.appendChild(lab);
        field.appendChild(input);
        form.appendChild(field);

        var actions = el('div', 'tt-form-actions');
        var cancel = el('button', 'tt-btn tt-btn-secondary', t('cancel'));
        cancel.type = 'button';
        cancel.addEventListener('click', function () { restorePanel.hidden = true; restorePanel.textContent = ''; });
        var submit = el('button', 'tt-btn tt-btn-danger', t('restore_confirm'));
        submit.type = 'submit';
        actions.appendChild(cancel);
        actions.appendChild(submit);
        form.appendChild(actions);

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            submit.disabled = true;
            setMsg(t('restoring'), '');
            reqJson('backups/' + encodeURIComponent(id) + '/restore', {
                confirm_text: String(input.value || '')
            }).then(function (r) {
                if (r.ok && r.json && r.json.success) {
                    setMsg(t('restored'), 'success');
                    restorePanel.hidden = true;
                    reloadSoon();
                } else {
                    submit.disabled = false;
                    setMsg(firstError(r.json) || t('error'), 'error');
                }
            }).catch(function () {
                submit.disabled = false;
                setMsg(t('network_error'), 'error');
            });
        });

        restorePanel.appendChild(form);
        restorePanel.scrollIntoView({ block: 'nearest' });
    }

    // ---- Migration import: upload → preview → dry-run → commit ---------
    var importForm = root.querySelector('[data-tt-backups-import-form]');
    var importPanel = root.querySelector('[data-tt-backups-import-panel]');

    if (importForm && importPanel) {
        importForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var fileInput = importForm.querySelector('input[type="file"]');
            if (!fileInput || !fileInput.files || !fileInput.files.length) return;
            var submitBtn = importForm.querySelector('button[type="submit"]');
            if (submitBtn) submitBtn.disabled = true;
            setMsg('', '');
            importPanel.hidden = false;
            importPanel.textContent = t('previewing');

            var fd = new FormData();
            fd.append('migration_file', fileInput.files[0]);
            reqForm('backups/migration/preview', fd).then(function (r) {
                if (submitBtn) submitBtn.disabled = false;
                if (r.ok && r.json && r.json.success) {
                    renderImportPreview(r.json.data);
                } else {
                    importPanel.hidden = true;
                    setMsg(firstError(r.json) || t('error'), 'error');
                }
            }).catch(function () {
                if (submitBtn) submitBtn.disabled = false;
                importPanel.hidden = true;
                setMsg(t('network_error'), 'error');
            });
        });
    }

    function simpleTable(headers, rows) {
        var table = el('table', 'tt-backups__table');
        var thead = el('thead');
        var htr = el('tr');
        headers.forEach(function (h) { htr.appendChild(el('th', null, h)); });
        thead.appendChild(htr);
        table.appendChild(thead);
        var tbody = el('tbody');
        rows.forEach(function (cells) {
            var tr = el('tr');
            cells.forEach(function (c) {
                var td = el('td');
                if (c && c.code) { td.appendChild(el('code', null, esc(c.code))); }
                else { td.textContent = String(c == null ? '' : c); }
                tr.appendChild(td);
            });
            tbody.appendChild(tr);
        });
        table.appendChild(tbody);
        return table;
    }

    function renderImportPreview(data) {
        importPanel.textContent = '';

        (data.warnings || []).forEach(function (w) {
            importPanel.appendChild(el('p', 'tt-backups__warn', esc(w)));
        });

        // Contents.
        importPanel.appendChild(el('h3', null, t('import_contents')));
        importPanel.appendChild(simpleTable(
            [t('import_dataset'), t('rows')],
            (data.summary || []).map(function (s) { return [esc(s.label), String(s.total)]; })
        ));

        // Conflict analysis.
        if (data.conflicts && data.conflicts.length) {
            importPanel.appendChild(el('h3', null, t('import_would')));
            importPanel.appendChild(simpleTable(
                [t('import_dataset'), t('import_incoming'), t('import_match'), t('import_new'), t('import_matched')],
                data.conflicts.map(function (c) {
                    return [esc(c.label), String(c.incoming), String(c.conflicts), String(c.new), { code: c.key }];
                })
            ));
        }

        renderImportConfig(data);
        importPanel.scrollIntoView({ block: 'nearest' });
    }

    // Build the configuration form (entities, conflict strategy, user
    // mapping) then dry-run, then commit. Mirrors the wp-admin two-step
    // confirm without a re-upload (the archive is staged server-side).
    function renderImportConfig(data) {
        var importable = data.importable || [];
        if (!importable.length) {
            importPanel.appendChild(el('p', 'tt-backups__hint', t('no_importable')));
            return;
        }

        var form = el('form', 'tt-backups__import-config');

        // Data sets.
        form.appendChild(el('h3', null, t('import_choose')));
        var ds = el('fieldset', 'tt-backups__entities');
        importable.forEach(function (g) {
            var lab = el('label', 'tt-backups__check');
            var cb = el('input');
            cb.type = 'checkbox';
            cb.name = 'entities';
            cb.value = g.key;
            cb.checked = true;
            lab.appendChild(cb);
            lab.appendChild(el('span', null, esc(g.label) + ' (' + g.total + ')'));
            ds.appendChild(lab);
        });
        form.appendChild(ds);

        // Conflict strategy (only entities with matches).
        var conflicting = (data.conflicts || []).filter(function (c) { return c.conflicts > 0; });
        if (conflicting.length) {
            form.appendChild(el('h3', null, t('import_existing')));
            conflicting.forEach(function (c) {
                var block = el('div', 'tt-backups__conflict');
                block.appendChild(el('strong', null, esc(c.label)));
                ['insert', 'update'].forEach(function (mode) {
                    var lab = el('label', 'tt-backups__radio');
                    var rb = el('input');
                    rb.type = 'radio';
                    rb.name = 'conflict__' + c.entity;
                    rb.value = mode;
                    if (mode === 'insert') rb.checked = true;
                    lab.appendChild(rb);
                    lab.appendChild(el('span', null, mode === 'insert' ? t('import_insert') : t('import_update')));
                    block.appendChild(lab);
                });
                form.appendChild(block);
            });
        }

        // WordPress-user mapping (numeric target user id; suggestion prefilled).
        if (data.user_refs && data.user_refs.length) {
            form.appendChild(el('h3', null, t('import_users')));
            data.user_refs.forEach(function (ref) {
                var field = el('div', 'tt-backups__field');
                var lab = el('label', 'tt-backups__legend', esc(ref.hint) || ('#' + ref.source_id));
                var input = el('input', 'tt-backups__input tt-backups__input--narrow');
                input.type = 'number';
                input.setAttribute('inputmode', 'numeric');
                input.min = '0';
                input.name = 'user_map__' + ref.source_id;
                input.value = String(ref.suggested_user_id || 0);
                lab.setAttribute('for', input.name);
                field.appendChild(lab);
                field.appendChild(input);
                form.appendChild(field);
            });
        }

        var dryBtn = el('button', 'tt-btn tt-btn-primary', t('import_dryrun'));
        dryBtn.type = 'submit';
        form.appendChild(dryBtn);

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            dryBtn.disabled = true;
            setMsg(t('import_dryrunning'), '');
            var opts = collectImportOpts(form);
            reqJson('backups/migration/dry-run', opts).then(function (r) {
                dryBtn.disabled = false;
                if (r.ok && r.json && r.json.success) {
                    setMsg('', '');
                    renderImportResult(r.json.data, opts);
                } else {
                    setMsg(firstError(r.json) || t('error'), 'error');
                }
            }).catch(function () {
                dryBtn.disabled = false;
                setMsg(t('network_error'), 'error');
            });
        });

        importPanel.appendChild(form);
    }

    function collectImportOpts(form) {
        var fd = new FormData(form);
        var entities = fd.getAll('entities').map(String);
        var conflict = {};
        var user_map = {};
        Array.prototype.forEach.call(form.elements, function (input) {
            if (input.name && input.name.indexOf('conflict__') === 0 && input.checked) {
                conflict[input.name.slice('conflict__'.length)] = input.value;
            }
            if (input.name && input.name.indexOf('user_map__') === 0) {
                user_map[input.name.slice('user_map__'.length)] = parseInt(input.value || '0', 10);
            }
        });
        return { entities: entities, conflict: conflict, user_map: user_map };
    }

    function renderImportResult(data, opts) {
        importPanel.textContent = '';
        importPanel.appendChild(el('p', 'tt-backups__hint', t('import_dry_note')));
        (data.warnings || []).forEach(function (w) {
            importPanel.appendChild(el('p', 'tt-backups__warn', esc(w)));
        });
        importPanel.appendChild(simpleTable(
            [t('table'), t('import_insert_c'), t('import_update_c'), t('import_skip_c')],
            (data.tables || []).map(function (row) {
                return [{ code: row.table }, String(row.insert), String(row.update), String(row.skip)];
            })
        ));

        // Commit form (typed-confirm IMPORT).
        var form = el('form', 'tt-backups__confirm-form');
        form.appendChild(el('p', 'tt-backups__danger-note', t('import_warn')));

        var field = el('div', 'tt-backups__field');
        var lab = el('label', 'tt-backups__legend', t('import_type'));
        var input = el('input', 'tt-backups__input tt-backups__input--narrow');
        input.type = 'text';
        input.name = 'confirm_text';
        input.placeholder = 'IMPORT';
        input.id = 'tt-bk-import-confirm';
        input.setAttribute('autocomplete', 'off');
        lab.setAttribute('for', input.id);
        field.appendChild(lab);
        field.appendChild(input);
        form.appendChild(field);

        var actions = el('div', 'tt-form-actions');
        var cancel = el('button', 'tt-btn tt-btn-secondary', t('cancel'));
        cancel.type = 'button';
        cancel.addEventListener('click', function () { importPanel.hidden = true; importPanel.textContent = ''; });
        var commit = el('button', 'tt-btn tt-btn-danger', t('import_now'));
        commit.type = 'submit';
        actions.appendChild(cancel);
        actions.appendChild(commit);
        form.appendChild(actions);

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            commit.disabled = true;
            setMsg(t('import_committing'), '');
            var body = {
                confirm_text: String(input.value || ''),
                entities: opts.entities,
                conflict: opts.conflict,
                user_map: opts.user_map
            };
            reqJson('backups/migration/commit', body).then(function (r) {
                if (r.ok && r.json && r.json.success) {
                    importPanel.textContent = '';
                    importPanel.appendChild(el('p', 'tt-success', t('import_done')));
                    setMsg(t('import_done'), 'success');
                    reloadSoon();
                } else {
                    commit.disabled = false;
                    setMsg(firstError(r.json) || t('error'), 'error');
                }
            }).catch(function () {
                commit.disabled = false;
                setMsg(t('network_error'), 'error');
            });
        });

        importPanel.appendChild(form);
        importPanel.scrollIntoView({ block: 'nearest' });
    }
})();
