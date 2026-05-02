/**
 * TalentTrack — MultiSelectTagComponent JS enhancement
 * #0019 Sprint 1 session 3
 *
 * The component server-renders a native <select multiple> PLUS a row
 * of option buttons. This module keeps the two in sync so the user
 * clicks pill buttons and the hidden select follows along. No-JS
 * users still get the native multi-select.
 */
(function(){
    'use strict';

    function renderTags(root) {
        var select = root.querySelector('select');
        var tags = root.querySelector('.tt-multitag-tags');
        if (!select || !tags) return;
        tags.innerHTML = '';
        Array.prototype.forEach.call(select.selectedOptions, function(opt) {
            var tag = document.createElement('span');
            tag.className = 'tt-multitag-tag';
            tag.setAttribute('role', 'listitem');
            tag.dataset.value = opt.value;
            var removeLabel = (window.TT && window.TT.i18n && window.TT.i18n.remove) || 'Remove';
            tag.innerHTML = '<span></span><button type="button" class="tt-multitag-tag-remove" aria-label="' + removeLabel.replace(/"/g, '&quot;') + '">×</button>';
            tag.querySelector('span').textContent = opt.textContent;
            tag.querySelector('button').addEventListener('click', function() { setSelected(root, opt.value, false); });
            tags.appendChild(tag);
        });
    }

    function setSelected(root, value, on) {
        var select = root.querySelector('select');
        Array.prototype.forEach.call(select.options, function(opt) {
            if (opt.value === value) opt.selected = on;
        });
        root.querySelectorAll('.tt-multitag-option').forEach(function(btn) {
            if (btn.getAttribute('data-value') === value) {
                btn.classList.toggle('is-selected', on);
                btn.setAttribute('aria-selected', on ? 'true' : 'false');
            }
        });
        renderTags(root);
        select.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function wire(root) {
        root.querySelectorAll('.tt-multitag-option').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var value = btn.getAttribute('data-value');
                var currentlyOn = btn.classList.contains('is-selected');
                setSelected(root, value, !currentlyOn);
            });
        });
        renderTags(root);
    }

    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('.tt-dashboard .tt-multitag').forEach(wire);
    });
})();
