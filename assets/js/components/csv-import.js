/**
 * TalentTrack — players CSV import flow
 * #0019 Sprint 3 session 3.2
 *
 * Three steps backed by one REST endpoint (POST /players/import):
 *
 *   1. Upload — submit the form with dry_run=1; render preview.
 *   2. Preview — re-submit the same file with dry_run=0; render result.
 *   3. Result — show counts, optional error-rows download.
 *
 * Re-uploading the file on commit is fine for typical CSVs (≤1MB)
 * and keeps the endpoint stateless. If the user picks a different
 * file we restart from step 1.
 */
(function(){
    'use strict';

    function getRest() {
        var t = window.TT || {};
        return {
            url: (t.rest_url || '/wp-json/talenttrack/v1/').replace(/\/+$/, '/'),
            nonce: t.rest_nonce || ''
        };
    }

    function showStep(root, name) {
        root.querySelectorAll('[data-step]').forEach(function(el) {
            el.hidden = el.getAttribute('data-step') !== name;
        });
    }

    function setMsg(root, kind, text) {
        var el = root.querySelector('[data-tt-csv-msg="1"]');
        if (!el) return;
        el.classList.remove('tt-success', 'tt-error');
        if (kind) el.classList.add(kind === 'success' ? 'tt-success' : 'tt-error');
        el.textContent = text || '';
    }

    function escapeHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function buildFormData(form, dryRun) {
        var fd = new FormData();
        var fileInput = form.querySelector('input[type="file"]');
        var file = fileInput && fileInput.files && fileInput.files[0];
        if (!file) return null;
        fd.append('file', file);
        fd.append('dry_run', dryRun ? '1' : '0');
        var dupe = form.querySelector('input[name="dupe_strategy"]:checked');
        fd.append('dupe_strategy', dupe ? dupe.value : 'skip');
        return fd;
    }

    function send(fd) {
        var rest = getRest();
        var headers = { 'Accept': 'application/json' };
        if (rest.nonce) headers['X-WP-Nonce'] = rest.nonce;
        return fetch(rest.url + 'players/import', {
            method: 'POST',
            credentials: 'same-origin',
            headers: headers,
            body: fd
        }).then(function(res) {
            return res.json().then(function(json) { return { ok: res.ok, json: json }; });
        });
    }

    function renderPreview(root, data) {
        var summary = root.querySelector('[data-tt-csv-preview-summary="1"]');
        if (summary) {
            summary.textContent = (window.TT && TT.i18n && TT.i18n.csv_preview_summary)
                ? TT.i18n.csv_preview_summary.replace('%1$d', String((data.preview || []).length)).replace('%2$d', String(data.total || 0))
                : 'Showing ' + (data.preview || []).length + ' of ' + (data.total || 0) + ' rows.';
        }

        var warnings = root.querySelector('[data-tt-csv-header-warnings="1"]');
        if (warnings) {
            warnings.innerHTML = '';
            (data.header_warnings || []).forEach(function(w) {
                var div = document.createElement('div');
                div.className = 'tt-flash tt-flash-warning';
                div.style.marginBottom = '8px';
                div.innerHTML = '<span style="flex:1;"></span>';
                div.querySelector('span').textContent = w;
                warnings.appendChild(div);
            });
        }

        var tbody = root.querySelector('[data-tt-csv-preview-body="1"]');
        if (!tbody) return;
        tbody.innerHTML = '';
        (data.preview || []).forEach(function(p) {
            var tr = document.createElement('tr');
            var d = p.data || {};
            var note = '';
            if (p.errors && p.errors.length) note = p.errors.join('; ');
            else if (p.dupe_of) note = (window.TT && TT.i18n && TT.i18n.csv_dupe_of) ? TT.i18n.csv_dupe_of.replace('%d', String(p.dupe_of)) : 'Matches existing player #' + p.dupe_of;
            var i18n = (window.TT && TT.i18n) || {};
            var statusLabel = p.status === 'error' ? (i18n.csv_status_error || 'Error') : (p.status === 'warning' ? (i18n.csv_status_dupe || 'Dupe') : (i18n.csv_status_ok || 'OK'));
            var statusClass = p.status === 'error' ? 'tt-flash-error' : (p.status === 'warning' ? 'tt-flash-warning' : 'tt-flash-success');
            tr.innerHTML =
                '<td data-label="' + escapeHtml(i18n.csv_col_row    || 'Row')    + '">' + escapeHtml(p.row_number) + '</td>' +
                '<td data-label="' + escapeHtml(i18n.csv_col_status || 'Status') + '"><span class="tt-flash ' + statusClass + '" style="display:inline-block; padding:2px 8px;">' + escapeHtml(statusLabel) + '</span></td>' +
                '<td data-label="' + escapeHtml(i18n.csv_col_player || 'Player') + '">' + escapeHtml((d.first_name || '') + ' ' + (d.last_name || '')) + '</td>' +
                '<td data-label="' + escapeHtml(i18n.csv_col_dob    || 'DOB')    + '">' + escapeHtml(d.date_of_birth || '') + '</td>' +
                '<td data-label="' + escapeHtml(i18n.csv_col_team   || 'Team')   + '">' + escapeHtml(d.team_name || d.team_id || '') + '</td>' +
                '<td data-label="' + escapeHtml(i18n.csv_col_notes  || 'Notes')  + '">' + escapeHtml(note) + '</td>';
            tbody.appendChild(tr);
        });
    }

    function renderResult(root, data) {
        var ul = root.querySelector('[data-tt-csv-result-summary="1"]');
        if (ul) {
            ul.innerHTML = '';
            var i18n = (window.TT && window.TT.i18n) || {};
            var lines = [
                (i18n.csv_created  || 'Created: %d').replace('%d', String(data.created || 0)),
                (i18n.csv_updated  || 'Updated: %d').replace('%d', String(data.updated || 0)),
                (i18n.csv_skipped  || 'Skipped (dupes): %d').replace('%d', String(data.skipped || 0)),
                (i18n.csv_errored  || 'Errors: %d').replace('%d', String(data.errored || 0)),
            ];
            lines.forEach(function(t) {
                var li = document.createElement('li');
                li.textContent = t;
                ul.appendChild(li);
            });
        }

        var cta = root.querySelector('[data-tt-csv-result-error-cta="1"]');
        var dl  = root.querySelector('[data-tt-csv-error-download="1"]');
        if ((data.errored || 0) > 0 && data.error_csv && cta && dl) {
            cta.hidden = false;
            var blob = new Blob([data.error_csv], { type: 'text/csv;charset=utf-8' });
            dl.href = URL.createObjectURL(blob);
            dl.download = 'talenttrack-import-errors.csv';
        } else if (cta) {
            cta.hidden = true;
        }
    }

    function wire(root) {
        var form = root.querySelector('[data-tt-csv-form="1"]');
        if (!form) return;
        var lastResult = null;

        form.addEventListener('submit', function(e) {
            e.preventDefault();
            setMsg(root, '', '');
            var btn = form.querySelector('[data-tt-csv-preview="1"]');
            if (btn) btn.disabled = true;
            var i18n = (window.TT && TT.i18n) || {};
            var fd = buildFormData(form, true);
            if (!fd) {
                setMsg(root, 'error', i18n.csv_pick_file_first || 'Pick a CSV file first.');
                if (btn) btn.disabled = false;
                return;
            }
            send(fd).then(function(r) {
                if (btn) btn.disabled = false;
                if (!r.ok || !r.json || !r.json.success) {
                    var msg = (r.json && r.json.errors && r.json.errors[0] && r.json.errors[0].message) || i18n.csv_preview_failed || 'Could not preview the file.';
                    setMsg(root, 'error', msg);
                    return;
                }
                renderPreview(root, r.json.data);
                showStep(root, 'preview');
            }).catch(function() {
                if (btn) btn.disabled = false;
                setMsg(root, 'error', (window.TT && TT.i18n && TT.i18n.network_error) || 'Network error.');
            });
        });

        // Commit button — re-uploads with dry_run=0.
        root.addEventListener('click', function(e) {
            var commit = e.target.closest('[data-tt-csv-commit="1"]');
            if (commit) {
                e.preventDefault();
                commit.disabled = true;
                var fd = buildFormData(form, false);
                var i18n2 = (window.TT && TT.i18n) || {};
                send(fd).then(function(r) {
                    commit.disabled = false;
                    if (!r.ok || !r.json || !r.json.success) {
                        var msg = (r.json && r.json.errors && r.json.errors[0] && r.json.errors[0].message) || i18n2.csv_import_failed || 'Import failed.';
                        setMsg(root, 'error', msg);
                        return;
                    }
                    lastResult = r.json.data;
                    renderResult(root, lastResult);
                    showStep(root, 'result');
                }).catch(function() {
                    commit.disabled = false;
                    setMsg(root, 'error', (window.TT && TT.i18n && TT.i18n.network_error) || 'Network error.');
                });
                return;
            }

            var restart = e.target.closest('[data-tt-csv-restart="1"]');
            if (restart) {
                e.preventDefault();
                form.reset();
                showStep(root, 'upload');
            }
        });
    }

    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('.tt-dashboard [data-tt-csv-import="1"]').forEach(wire);
    });
})();
