/*
 * frontend-threads.js (#0028) — vanilla-JS hydrator for the goal
 * conversation thread.
 *
 * Behaviour:
 *   - POSTs new messages via REST with X-WP-Nonce.
 *   - Polls GET /threads/.../{since=N} every 30s while
 *     document.visibilityState === 'visible'. Pauses when hidden.
 *   - Renders new messages without a full reload.
 *   - Per-message edit + delete actions (5-min edit window enforced
 *     server-side; client UI matches).
 */
(function () {
    'use strict';

    var POLL_INTERVAL_MS = 30000;

    var hosts = document.querySelectorAll('[data-tt-thread]');
    Array.prototype.forEach.call(hosts, function (host) { initThread(host); });

    function initThread(host) {
        var bootEl = host.querySelector('[data-tt-thread-bootstrap]');
        if (!bootEl) return;
        var cfg;
        try { cfg = JSON.parse(bootEl.textContent || '{}'); } catch (e) { return; }
        if (!cfg.rest_url || !cfg.rest_nonce) return;

        var listEl = host.querySelector('[data-tt-thread-list]');
        var compose = host.querySelector('[data-tt-thread-compose]');
        var lastId = parseInt(host.dataset.lastId || '0', 10) || 0;
        var pollTimer = null;

        // Per-message actions wired via delegation.
        listEl.addEventListener('click', function (ev) {
            var btn = ev.target && ev.target.closest && ev.target.closest('[data-tt-thread-action]');
            if (!btn) return;
            var msgEl = btn.closest('[data-tt-thread-msg]');
            if (!msgEl) return;
            var msgId = parseInt(msgEl.getAttribute('data-tt-thread-msg'), 10);
            var action = btn.getAttribute('data-tt-thread-action');
            if (action === 'edit') beginEdit(msgEl, msgId);
            if (action === 'delete') doDelete(msgEl, msgId);
        });

        if (compose) {
            compose.addEventListener('submit', function (ev) {
                ev.preventDefault();
                var ta = compose.querySelector('textarea');
                var pv = compose.querySelector('input[name="visibility"]');
                var btn = compose.querySelector('button.tt-thread-send');
                var body = (ta && ta.value || '').trim();
                if (!body) return;
                var visibility = pv && pv.checked ? pv.value : 'public';
                btn.disabled = true;
                var prev = btn.textContent;
                btn.textContent = cfg.i18n.sending || 'Sending…';
                postMessage(body, visibility).then(function (msg) {
                    if (msg) {
                        appendMessage(msg);
                        if (msg.id > lastId) lastId = msg.id;
                        if (ta) ta.value = '';
                        if (pv) pv.checked = false;
                    }
                }).catch(function () {
                    alert(cfg.i18n.failed || 'Could not send message.');
                }).finally(function () {
                    btn.disabled = false;
                    btn.textContent = prev;
                    ta && ta.focus();
                });
            });
        }

        // Render edit + delete affordances on existing self-authored messages
        // that are still within the edit window.
        Array.prototype.forEach.call(listEl.querySelectorAll('.tt-thread-msg.is-self'), function (msgEl) {
            ensureSelfActions(msgEl);
        });

        // Polling lifecycle.
        startPolling();
        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'visible') startPolling();
            else stopPolling();
        });

        function startPolling() {
            stopPolling();
            pollTimer = window.setInterval(pollOnce, POLL_INTERVAL_MS);
        }
        function stopPolling() {
            if (pollTimer) { window.clearInterval(pollTimer); pollTimer = null; }
        }
        function pollOnce() {
            fetch(cfg.rest_url + '?since=' + encodeURIComponent(lastId), {
                credentials: 'same-origin',
                headers: { 'X-WP-Nonce': cfg.rest_nonce }
            }).then(function (r) { return r.ok ? r.json() : null; })
              .then(function (payload) {
                  if (!payload || !Array.isArray(payload.messages)) return;
                  payload.messages.forEach(function (msg) {
                      if (msg.id <= lastId) return;
                      appendMessage(msg);
                      if (msg.id > lastId) lastId = msg.id;
                  });
              })
              .catch(function () { /* swallow */ });
        }

        function postMessage(body, visibility) {
            return fetch(cfg.rest_url + '/messages', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': cfg.rest_nonce
                },
                body: JSON.stringify({ body: body, visibility: visibility })
            }).then(function (r) { if (!r.ok) throw new Error('http'); return r.json(); });
        }

        function appendMessage(msg) {
            var li = renderMessage(msg);
            // Drop the empty-state placeholder if present.
            var empty = listEl.querySelector('.tt-thread-empty');
            if (empty) empty.remove();
            listEl.appendChild(li);
            listEl.scrollTop = listEl.scrollHeight;
            if (msg.author_user_id === cfg.current_user_id) ensureSelfActions(li);
        }

        function renderMessage(msg) {
            var li = document.createElement('li');
            var cls = 'tt-thread-msg';
            if (msg.author_user_id === cfg.current_user_id) cls += ' is-self';
            if (msg.is_system) cls += ' is-system';
            if (msg.visibility === 'private_to_coach') cls += ' is-private';
            if (msg.deleted_at) cls += ' is-deleted';
            li.className = cls;
            li.setAttribute('data-tt-thread-msg', String(msg.id));

            var head = '';
            if (!msg.is_system) {
                head = '<div class="tt-thread-msg-head">'
                    + '<span class="tt-thread-msg-author">' + escapeHtml(msg.author_name || '') + '</span>'
                    + '<span class="tt-thread-msg-when">' + (cfg.i18n.just_now || 'just now') + '</span>'
                    + (msg.visibility === 'private_to_coach'
                        ? '<span class="tt-thread-msg-private">' + (cfg.i18n.coaches_only || 'Coaches only') + '</span>'
                        : '')
                    + '</div>';
            }
            li.innerHTML = head
                + '<div class="tt-thread-msg-body">' + escapeHtml(msg.body || '') + '</div>'
                + (msg.edited_at ? '<div class="tt-thread-msg-edited">' + (cfg.i18n.edited || '(edited)') + '</div>' : '');
            return li;
        }

        function ensureSelfActions(msgEl) {
            if (msgEl.classList.contains('is-deleted')) return;
            if (msgEl.querySelector('.tt-thread-msg-actions')) return;
            var actions = document.createElement('div');
            actions.className = 'tt-thread-msg-actions';
            actions.innerHTML =
                '<button type="button" class="tt-thread-msg-action" data-tt-thread-action="edit">' + (cfg.i18n.edit || 'Edit') + '</button>' +
                '<button type="button" class="tt-thread-msg-action" data-tt-thread-action="delete">' + (cfg.i18n.delete || 'Delete') + '</button>';
            msgEl.appendChild(actions);
        }

        function beginEdit(msgEl, msgId) {
            var bodyEl = msgEl.querySelector('.tt-thread-msg-body');
            if (!bodyEl) return;
            var current = bodyEl.textContent || '';
            var ta = document.createElement('textarea');
            ta.value = current;
            ta.rows = 3;
            ta.style.width = '100%';
            ta.style.fontSize = '1rem';
            bodyEl.replaceWith(ta);
            var save = document.createElement('button');
            save.type = 'button'; save.className = 'tt-thread-msg-action'; save.textContent = cfg.i18n.save || 'Save';
            var cancel = document.createElement('button');
            cancel.type = 'button'; cancel.className = 'tt-thread-msg-action'; cancel.textContent = cfg.i18n.cancel || 'Cancel';
            var actions = msgEl.querySelector('.tt-thread-msg-actions');
            if (actions) actions.innerHTML = '';
            if (actions) { actions.appendChild(save); actions.appendChild(cancel); }
            ta.focus();

            cancel.addEventListener('click', function () { restore(current); });
            save.addEventListener('click', function () {
                var next = (ta.value || '').trim();
                if (!next) return;
                fetch(cfg.rest_url + '/messages/' + msgId, {
                    method: 'PUT',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.rest_nonce },
                    body: JSON.stringify({ body: next })
                }).then(function (r) {
                    if (!r.ok) {
                        alert(cfg.i18n.edit_window_expired || 'Edit window has expired.');
                        restore(current);
                        return;
                    }
                    return r.json().then(function (m) {
                        var newBody = document.createElement('div');
                        newBody.className = 'tt-thread-msg-body';
                        newBody.textContent = m.body;
                        ta.replaceWith(newBody);
                        if (!msgEl.querySelector('.tt-thread-msg-edited')) {
                            var e = document.createElement('div');
                            e.className = 'tt-thread-msg-edited';
                            e.textContent = cfg.i18n.edited || '(edited)';
                            msgEl.appendChild(e);
                        }
                        ensureSelfActions(msgEl);
                    });
                });
            });

            function restore(text) {
                var d = document.createElement('div');
                d.className = 'tt-thread-msg-body'; d.textContent = text;
                ta.replaceWith(d);
                ensureSelfActions(msgEl);
            }
        }

        function doDelete(msgEl, msgId) {
            if (!window.confirm(cfg.i18n.confirm_delete || 'Delete this message?')) return;
            fetch(cfg.rest_url + '/messages/' + msgId, {
                method: 'DELETE',
                credentials: 'same-origin',
                headers: { 'X-WP-Nonce': cfg.rest_nonce }
            }).then(function (r) {
                if (!r.ok) return;
                msgEl.classList.add('is-deleted');
                var bodyEl = msgEl.querySelector('.tt-thread-msg-body');
                if (bodyEl) bodyEl.textContent = cfg.i18n.message_deleted || 'Message deleted.';
                var actions = msgEl.querySelector('.tt-thread-msg-actions');
                if (actions) actions.remove();
            });
        }

        function escapeHtml(s) {
            return String(s == null ? '' : s)
                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }
    }
})();
