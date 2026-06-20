/*
 * tt-image-pdf.js (#1475) — pixel-faithful image-capture PDF.
 *
 * A reusable, framework-free print module. On the print/export action
 * it captures a live DOM node with html2canvas, then assembles an A4
 * landscape PDF (jsPDF) scaled to width with multi-page slicing on
 * overflow, and triggers a download. No server round-trip for the
 * render — the PDF mirrors exactly what the coach sees on screen.
 *
 * The heavy vendor libraries (html2canvas + jsPDF) are LAZY-LOADED:
 * they are injected only when the user first clicks a capture trigger,
 * so they never weigh on the always-loaded front-end bundle.
 *
 * Configuration ships server-side via wp_localize_script into
 * window.TT_IMAGE_PDF:
 *   {
 *     vendor: { html2canvas: <url>, jspdf: <url> },
 *     i18n:   { working, failed, capture_action },
 *   }
 *
 * A trigger is any element carrying data-tt-image-pdf with:
 *   data-target    CSS selector of the node to capture (required)
 *   data-filename  download filename (optional, defaults to tt-export.pdf)
 *
 * Translatable strings come from window.TT_IMAGE_PDF.i18n — never
 * hardcoded English (the fallbacks below are last-resort only).
 */
(function () {
    'use strict';

    var cfg = window.TT_IMAGE_PDF || {};
    var vendor = cfg.vendor || {};

    function i18n(key, fallback) {
        var t = cfg.i18n || {};
        return t[key] != null ? t[key] : fallback;
    }

    // ---- lazy script loader (one network fetch per lib, memoised) -------

    var loaded = {};
    function loadScript(url) {
        if (!url) return Promise.reject(new Error('missing vendor url'));
        if (loaded[url]) return loaded[url];
        loaded[url] = new Promise(function (resolve, reject) {
            var s = document.createElement('script');
            s.src = url;
            s.async = true;
            s.onload = function () { resolve(); };
            s.onerror = function () { reject(new Error('failed to load ' + url)); };
            document.head.appendChild(s);
        });
        return loaded[url];
    }

    function ensureLibs() {
        return loadScript(vendor.html2canvas)
            .then(function () { return loadScript(vendor.jspdf); })
            .then(function () {
                var h2c = window.html2canvas;
                var jsPDF = window.jspdf && window.jspdf.jsPDF;
                if (typeof h2c !== 'function' || typeof jsPDF !== 'function') {
                    throw new Error('capture libraries unavailable');
                }
                return { html2canvas: h2c, jsPDF: jsPDF };
            });
    }

    // ---- on-screen notice (no hover, accessible, tt- prefixed) ----------

    function notice(node, message, isError) {
        var box = node.querySelector('.tt-image-pdf-notice');
        if (!box) {
            box = document.createElement('div');
            box.className = 'tt-image-pdf-notice';
            box.setAttribute('role', 'status');
            box.setAttribute('aria-live', 'polite');
            box.style.cssText =
                'position:fixed;left:50%;bottom:16px;transform:translateX(-50%);' +
                'z-index:99999;max-width:90%;padding:10px 16px;border-radius:6px;' +
                'font:14px/1.4 system-ui,sans-serif;box-shadow:0 2px 8px rgba(0,0,0,0.2);';
            document.body.appendChild(box);
        }
        box.style.background = isError ? '#b3261e' : '#1d7874';
        box.style.color = '#fff';
        box.textContent = message;
        box.hidden = false;
        if (!isError) {
            window.setTimeout(function () { box.hidden = true; }, 2500);
        }
        return box;
    }

    function clearNotice() {
        var box = document.querySelector('.tt-image-pdf-notice');
        if (box) box.hidden = true;
    }

    // ---- A4 landscape multi-page assembly -------------------------------

    // A4 landscape in mm; the capture is scaled to page width and sliced
    // vertically across as many pages as the content height needs.
    function buildPdf(jsPDF, canvas) {
        var pageW = 297; // A4 landscape width (mm)
        var pageH = 210; // A4 landscape height (mm)
        var margin = 8;  // mm
        var usableW = pageW - margin * 2;
        var usableH = pageH - margin * 2;

        var pdf = new jsPDF({ orientation: 'landscape', unit: 'mm', format: 'a4' });

        // Pixels-per-mm at the captured resolution.
        var pxPerMm = canvas.width / usableW;
        // Height (px) of one printed page worth of content.
        var sliceHpx = Math.floor(usableH * pxPerMm);
        if (sliceHpx <= 0) sliceHpx = canvas.height;

        var renderedH = canvas.height / pxPerMm; // total content height in mm
        if (renderedH <= usableH) {
            // Single page — fits within one A4 landscape sheet.
            var imgData = canvas.toDataURL('image/jpeg', 0.92);
            pdf.addImage(imgData, 'JPEG', margin, margin, usableW, renderedH);
            return pdf;
        }

        // Multi-page: slice the source canvas into page-height strips.
        var offsetPx = 0;
        var first = true;
        while (offsetPx < canvas.height) {
            var stripHpx = Math.min(sliceHpx, canvas.height - offsetPx);
            var slice = document.createElement('canvas');
            slice.width = canvas.width;
            slice.height = stripHpx;
            var ctx = slice.getContext('2d');
            ctx.drawImage(
                canvas,
                0, offsetPx, canvas.width, stripHpx,
                0, 0, canvas.width, stripHpx
            );
            var stripData = slice.toDataURL('image/jpeg', 0.92);
            var stripHmm = stripHpx / pxPerMm;
            if (!first) pdf.addPage('a4', 'landscape');
            pdf.addImage(stripData, 'JPEG', margin, margin, usableW, stripHmm);
            first = false;
            offsetPx += stripHpx;
        }
        return pdf;
    }

    function capture(trigger) {
        var sel = trigger.getAttribute('data-target');
        var target = sel ? document.querySelector(sel) : null;
        if (!target) {
            notice(document.body, i18n('failed', 'Could not generate the PDF.'), true);
            return;
        }
        var filename = trigger.getAttribute('data-filename') || 'tt-export.pdf';

        trigger.disabled = true;
        notice(document.body, i18n('working', 'Preparing PDF…'), false);

        ensureLibs().then(function (libs) {
            return libs.html2canvas(target, {
                backgroundColor: '#ffffff',
                scale: Math.min(2, window.devicePixelRatio || 1),
                useCORS: true,
                logging: false,
                scrollX: 0,
                scrollY: -window.scrollY
            }).then(function (canvas) {
                var pdf = buildPdf(libs.jsPDF, canvas);
                pdf.save(filename);
                clearNotice();
            });
        }).catch(function () {
            // Graceful fallback: a clear, translatable failure notice.
            // The server-side DomPDF export remains reachable as the
            // documented fallback path (see docs/match-prep.md).
            notice(document.body, i18n('failed', 'Could not generate the PDF. Try the print dialog instead.'), true);
        }).then(function () {
            trigger.disabled = false;
        });
    }

    // ---- wire triggers ---------------------------------------------------

    function bind() {
        var triggers = document.querySelectorAll('[data-tt-image-pdf]');
        Array.prototype.forEach.call(triggers, function (t) {
            if (t.getAttribute('data-tt-image-pdf-bound')) return;
            t.setAttribute('data-tt-image-pdf-bound', '1');
            t.addEventListener('click', function (e) {
                e.preventDefault();
                capture(t);
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bind);
    } else {
        bind();
    }

    // Expose a tiny namespaced API so other surfaces can trigger a
    // capture programmatically without re-binding DOM.
    window.TT = window.TT || {};
    window.TT.imagePdf = {
        capture: function (selector, filename) {
            var fake = document.createElement('button');
            fake.setAttribute('data-target', selector);
            if (filename) fake.setAttribute('data-filename', filename);
            capture(fake);
        }
    };
})();
