/* ═══════════════════════════════════════════════════════════════════════════
 * TalentTrack — admin-sortable.js
 *
 * Tiny vanilla-JS drag reorder for any <tbody data-sortable="1"> or any
 * element with [data-sortable="1"]. Uses HTML5 Drag and Drop.
 *
 * After a successful reorder, the container fires a bubbling
 *   'tt:sortable:end'
 * custom event. Consumers (e.g. OptionSetEditor) listen for this and
 * serialise their state.
 *
 * Zero dependencies. No jQuery.
 * ═══════════════════════════════════════════════════════════════════════════ */

(function(){
    'use strict';

    function init() {
        var containers = document.querySelectorAll('[data-sortable="1"]');
        containers.forEach(bindContainer);
    }

    function bindContainer(container) {
        if (container.__ttSortableBound) return;
        container.__ttSortableBound = true;

        var children = container.children;
        for (var i = 0; i < children.length; i++) {
            bindRow(children[i], container);
        }

        // MutationObserver so dynamically added rows also become draggable.
        var obs = new MutationObserver(function(mutations){
            mutations.forEach(function(m){
                m.addedNodes.forEach(function(node){
                    if (node.nodeType === 1) bindRow(node, container);
                });
            });
        });
        obs.observe(container, { childList: true });
    }

    function bindRow(row, container) {
        if (!row || row.nodeType !== 1) return;
        if (row.__ttRowBound) return;
        row.__ttRowBound = true;

        row.setAttribute('draggable', 'true');

        row.addEventListener('dragstart', function(e){
            row.classList.add('tt-dragging');
            e.dataTransfer.effectAllowed = 'move';
            // Firefox needs data set for drag to fire.
            try { e.dataTransfer.setData('text/plain', ''); } catch(_) {}
        });

        row.addEventListener('dragend', function(){
            row.classList.remove('tt-dragging');
            container.querySelectorAll('.tt-drag-over').forEach(function(el){
                el.classList.remove('tt-drag-over');
            });
            container.dispatchEvent(new CustomEvent('tt:sortable:end', { bubbles: true }));
        });

        row.addEventListener('dragover', function(e){
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';

            var dragging = container.querySelector('.tt-dragging');
            if (!dragging || dragging === row) return;

            var rect = row.getBoundingClientRect();
            var after = (e.clientY - rect.top) > (rect.height / 2);
            if (after) {
                row.parentNode.insertBefore(dragging, row.nextSibling);
            } else {
                row.parentNode.insertBefore(dragging, row);
            }
        });
    }

    // Run on DOMContentLoaded, plus expose a manual init for late-injected UI.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
    window.ttSortableInit = init;
})();
