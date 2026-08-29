/**
 * match-analysis-tally.js (#2726) — turns the match-analysis roster into a
 * tally grid.
 *
 * The server renders the honest form: one block per player with a real
 * radio group and its note + phase fields. This script reads that markup,
 * builds a grid of name buttons above it, and hides the blocks of players
 * nobody marked. Tapping a name opens a three-way picker; choosing sets the
 * underlying radio, which is what actually posts.
 *
 * Consequences of doing it this way, all deliberate:
 *
 *   - there is one set of inputs, so nothing can post twice or drift;
 *   - with no JS the coach gets the plain form — slower, but complete;
 *   - the script owns presentation only. It never writes anything the form
 *     would not have posted on its own.
 */
(function () {
    'use strict';

    var S = window.TT_MatchAnalysisTally || {};

    var GLYPHS = { '': '—', stood_out: '▲', as_expected: '●', below_par: '▼' };

    function t(key, fallback) {
        return (S[key] !== undefined && S[key] !== '') ? S[key] : fallback;
    }

    function sprintf(template, values) {
        var i = 0;
        return String(template)
            .replace(/%(\d+)\$[sd]/g, function (_m, n) { return values[parseInt(n, 10) - 1]; })
            .replace(/%[sd]/g, function () { return values[i++]; });
    }

    function markerOf(player) {
        var checked = player.querySelector('.tt-ma__marker-input:checked');
        return checked ? checked.value : '';
    }

    function labelFor(player, value) {
        var input = player.querySelector('.tt-ma__marker-input[value="' + value + '"]');
        if (!input) return value;
        var label = player.querySelector('label[for="' + input.id + '"]');
        if (!label) return value;
        // The label carries the glyph as a decorative span; the text after
        // it is the human name of the state.
        return label.textContent.replace(GLYPHS[value] || '', '').trim();
    }

    function enhance(root) {
        var players = Array.prototype.slice.call(root.querySelectorAll('.tt-ma__player'));
        if (!players.length) return;

        var grid = document.createElement('ul');
        grid.className = 'tt-ma__tally';

        var head = document.createElement('div');
        head.className = 'tt-ma__tagged-head';
        var headTitle = document.createElement('strong');
        headTitle.textContent = t('notesTitle', 'Notes');
        var headCount = document.createElement('span');
        headCount.className = 'tt-ma__tagged-count';
        head.appendChild(headTitle);
        head.appendChild(headCount);

        var empty = document.createElement('p');
        empty.className = 'tt-ma__hint tt-ma__tagged-empty';
        empty.textContent = t('emptyState', 'Nobody marked yet.');

        var list = root.querySelector('.tt-ma__players');
        root.insertBefore(grid, list);
        root.insertBefore(head, list);
        root.insertBefore(empty, list);

        var buttons = {};
        var open = null;

        function closePicker() {
            if (!open) return;
            var picker = root.querySelector('.tt-ma__picker');
            if (picker) picker.parentNode.removeChild(picker);
            if (buttons[open]) buttons[open].setAttribute('aria-expanded', 'false');
            open = null;
        }

        function paint(player) {
            var id     = player.getAttribute('data-player-id');
            var marker = markerOf(player);
            var btn    = buttons[id];

            player.setAttribute('data-marker', marker);
            btn.setAttribute('data-marker', marker);
            btn.querySelector('.tt-ma__tag-mark').textContent = GLYPHS[marker] || GLYPHS[''];
            btn.setAttribute('aria-label',
                player.getAttribute('data-name') + ' — ' + labelFor(player, marker));
        }

        function repaintCount() {
            var marked = players.filter(function (p) { return markerOf(p) !== ''; }).length;
            headCount.textContent = sprintf(t('counted', '%1$d of %2$d marked'), [ marked, players.length ]);
            empty.hidden = marked > 0;
        }

        function choose(player, value) {
            var input = player.querySelector('.tt-ma__marker-input[value="' + value + '"]');
            if (!input) return;
            input.checked = true;
            // Let anything listening for a change (drafts, dirty-state
            // guards) hear it — the click happened on a button, not on the
            // input the form cares about.
            input.dispatchEvent(new Event('change', { bubbles: true }));
            paint(player);
            repaintCount();
        }

        function openPicker(player, btn) {
            closePicker();

            var picker = document.createElement('div');
            picker.className = 'tt-ma__picker';
            picker.setAttribute('role', 'menu');
            picker.setAttribute('aria-label',
                sprintf(t('chooseFor', 'How did %s do?'), [ player.getAttribute('data-name') ]));

            var values = Array.prototype.slice
                .call(player.querySelectorAll('.tt-ma__marker-input'))
                .map(function (i) { return i.value; })
                .filter(function (v) { return v !== ''; });

            values.forEach(function (value) {
                var item = document.createElement('button');
                item.type = 'button';
                item.setAttribute('role', 'menuitem');
                item.setAttribute('data-marker', value);
                item.innerHTML = '<span class="tt-ma__glyph" aria-hidden="true">' + (GLYPHS[value] || '') + '</span>';
                item.appendChild(document.createTextNode(' ' + labelFor(player, value)));
                item.addEventListener('click', function () {
                    choose(player, value);
                    closePicker();
                    btn.focus();
                });
                picker.appendChild(item);
            });

            if (markerOf(player) !== '') {
                var clear = document.createElement('button');
                clear.type = 'button';
                clear.setAttribute('role', 'menuitem');
                clear.className = 'tt-ma__picker-clear';
                clear.textContent = t('clear', 'Clear');
                clear.addEventListener('click', function () {
                    choose(player, '');
                    closePicker();
                    btn.focus();
                });
                picker.appendChild(clear);
            }

            btn.parentNode.appendChild(picker);
            btn.setAttribute('aria-expanded', 'true');
            open = player.getAttribute('data-player-id');

            var first = picker.querySelector('button');
            if (first) first.focus();
        }

        players.forEach(function (player) {
            var id   = player.getAttribute('data-player-id');
            var cell = document.createElement('li');

            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'tt-ma__tag-btn';
            btn.setAttribute('data-player-id', id);
            btn.setAttribute('aria-haspopup', 'true');
            btn.setAttribute('aria-expanded', 'false');

            var name = document.createElement('span');
            name.className = 'tt-ma__tag-name';
            name.textContent = player.getAttribute('data-name');

            var mark = document.createElement('span');
            mark.className = 'tt-ma__tag-mark';
            mark.setAttribute('aria-hidden', 'true');

            btn.appendChild(name);
            btn.appendChild(mark);
            btn.addEventListener('click', function (e) {
                e.stopPropagation();
                if (open === id) { closePicker(); return; }
                openPicker(player, btn);
            });

            cell.appendChild(btn);
            grid.appendChild(cell);
            buttons[id] = btn;

            paint(player);
        });

        repaintCount();

        document.addEventListener('click', closePicker);
        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape' || !open) return;
            var btn = buttons[open];
            closePicker();
            if (btn) btn.focus();
        });

        // #3007 — undo and revert put old values straight back into the
        // radios, which the chips above are drawn from. Without this the
        // radios would be right and the chips would still show the marker
        // the coach just took back.
        document.addEventListener('tt:ma-remounted', function () {
            closePicker();
            players.forEach(paint);
            repaintCount();
        });

        // Last: the class that swaps the presentation. Set only once the
        // grid exists, so a script that dies halfway leaves the plain form
        // standing rather than a page with no controls at all.
        root.classList.add('tt-ma__roster--tally');
    }

    function init() {
        Array.prototype.slice.call(document.querySelectorAll('[data-tt-tally]')).forEach(enhance);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}());
