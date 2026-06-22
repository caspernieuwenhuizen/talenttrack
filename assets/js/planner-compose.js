/* Team planner — "compose weekly PDF" dialog open/close (#1631).
 * Native <dialog>; document-delegated so it survives re-render. */
(function () {
    'use strict';
    function dlg() { return document.getElementById('tt-planner-compose'); }
    document.addEventListener('click', function (e) {
        var t = e.target;
        if (t.closest && t.closest('[data-tt-open-compose]')) {
            var d = dlg();
            if (!d) return;
            if (typeof d.showModal === 'function') d.showModal();
            else d.setAttribute('open', '');
            return;
        }
        if (t.closest && t.closest('[data-tt-close-compose]')) {
            var c = dlg();
            if (!c) return;
            if (typeof c.close === 'function') c.close();
            else c.removeAttribute('open');
        }
    });
}());
