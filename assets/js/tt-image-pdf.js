/*
 * tt-image-pdf.js (#1475) — pixel-faithful image-capture PDF.
 *
 * A reusable, framework-free print module. On the print/export action
 * it captures a live DOM node with html2canvas, then assembles an A4 PDF
 * (jsPDF) — landscape or portrait per the trigger — scaled to width with
 * multi-page slicing on overflow, and triggers a download. No server
 * round-trip for the render — the PDF mirrors exactly what the coach sees
 * on screen.
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
 *   data-target       CSS selector of the node to capture (required)
 *   data-filename     download filename (optional, defaults to tt-export.pdf)
 *   data-orientation  'portrait' | 'landscape' (optional, default landscape)
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

    // ---- A4 multi-page assembly -----------------------------------------

    // A4 in mm. The capture is fitted to the page in BOTH dimensions and
    // centred; it is only sliced across pages when fitting it whole would
    // take the type below the readability floor.
    //
    // #3272 — this used to fit to page WIDTH alone. A wide, short capture —
    // which is what a landscape-shaped sheet is — printed as a band across
    // the top with the rest of the paper blank, and could never scale up to
    // claim the space it was given. Fitting to width also meant a capture
    // wider than the page shrank without limit: the match-prep grid at
    // ~1320px on portrait A4 came out at a 0.56 scale, which puts body text
    // at about 5pt. Both symptoms, one cause.
    var MAX_UPSCALE = 1.25;   // a sparse sheet must not become poster type
    var MIN_LEGIBLE = 0.62;   // below this, paginate rather than shrink on
    var PX_PER_MM   = 96 / 25.4; // CSS pixels per mm at 96dpi

    // `cssWidth` is the captured node's width in CSS pixels. It is passed in
    // rather than read off the canvas because html2canvas renders at a
    // device-pixel `scale`, so `canvas.width` is that many times larger and
    // says nothing about the size the sheet was laid out at.
    function buildPdf(jsPDF, canvas, orientation, cssWidth) {
        var portrait = orientation === 'portrait';
        var pageW = portrait ? 210 : 297; // A4 width (mm)
        var pageH = portrait ? 297 : 210; // A4 height (mm)
        var margin = 8;  // mm
        var usableW = pageW - margin * 2;
        var usableH = pageH - margin * 2;

        var ori = portrait ? 'portrait' : 'landscape';
        var pdf = new jsPDF({ orientation: ori, unit: 'mm', format: 'a4' });

        // The capture's natural size on paper: the CSS pixels it was laid
        // out at, read as millimetres at 96dpi. Height follows from the
        // canvas's own aspect ratio, so a device-pixel scale cancels out.
        var cssW = cssWidth || (canvas.width / (window.devicePixelRatio || 1));
        var natW = cssW / PX_PER_MM;
        var natH = natW * (canvas.height / canvas.width);
        if (!isFinite(natW) || !isFinite(natH) || natW <= 0 || natH <= 0) return pdf;

        var fit = Math.min(usableW / natW, usableH / natH);

        if (fit >= MIN_LEGIBLE) {
            // One page. Cap the upscale so a half-empty sheet is not blown
            // up into something that reads like signage, and centre what is
            // left over rather than pinning it to the top-left corner.
            var scale = Math.min(fit, MAX_UPSCALE);
            var drawW = natW * scale;
            var drawH = natH * scale;
            var x = margin + (usableW - drawW) / 2;
            var y = margin + (usableH - drawH) / 2;
            pdf.addImage(canvas.toDataURL('image/jpeg', 0.92), 'JPEG', x, y, drawW, drawH);
            return pdf;
        }

        // Too tall to fit legibly: fall back to fitting the WIDTH and
        // slicing vertically, which is the old behaviour and the right one
        // once a single page is off the table.
        var pxPerMm = canvas.width / usableW;
        var sliceHpx = Math.floor(usableH * pxPerMm);
        if (sliceHpx <= 0) sliceHpx = canvas.height;

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
            if (!first) pdf.addPage('a4', ori);
            pdf.addImage(stripData, 'JPEG', margin, margin, usableW, stripHmm);
            first = false;
            offsetPx += stripHpx;
        }
        return pdf;
    }

    // ---- capture-clone cleanup -------------------------------------------

    // An empty field's placeholder is a hint, not content — it has no business
    // on an exported page. CSS can't take it out: html2canvas ignores
    // ::placeholder entirely and, when an input's value is empty, paints the
    // `placeholder` attribute as ordinary text in the input's own ink colour
    // (so it comes out DARKER on paper than it looks on screen). Removing the
    // attribute in the clone leaves it nothing to substitute. The live DOM is
    // untouched — this only ever runs on html2canvas's cloned node.
    function stripPlaceholders(root) {
        var fields = root.querySelectorAll('input[placeholder], textarea[placeholder]');
        Array.prototype.forEach.call(fields, function (el) {
            el.removeAttribute('placeholder');
        });
    }

    // ---- fixed-width staging -------------------------------------------

    // A4 at 96dpi, less the 8mm margins buildPdf() reserves: the width the
    // sheet is composed at, whatever the coach's window happens to be.
    var PAGE_CSS_W = { landscape: 1062, portrait: 733 };

    // #3272 — capture a copy of the node laid out at a fixed page width
    // instead of the live one.
    //
    // The live node inherits the viewport: from a phone the match-prep grid
    // is a one-column stack, from a desktop a three-column spreadsheet. Same
    // button, same match, two different documents — and neither was composed
    // for paper. Staging a clone at the page's own width makes the output a
    // property of the sheet rather than of the window that asked for it.
    //
    // The stage is on-screen but off-canvas (not `display:none`), because
    // html2canvas measures what it is given: a hidden subtree has no layout
    // and paints as an empty box.
    function stage(target, cssWidth) {
        var host = document.createElement('div');
        host.className = 'tt-image-pdf-stage';
        host.style.cssText =
            'position:fixed;left:-10000px;top:0;z-index:-1;' +
            'width:' + cssWidth + 'px;background:#fff;pointer-events:none;';

        var clone = target.cloneNode(true);
        clone.style.width = cssWidth + 'px';
        clone.style.maxWidth = 'none';
        clone.style.margin = '0';
        // html2canvas clones the document again before painting, so `onclone`
        // has to find THIS node inside that second copy. Searching by the
        // caller's selector would match the live node — it comes first in the
        // document — and dress the wrong one. A unique marker is unambiguous.
        clone.setAttribute('data-tt-pdf-stage', '1');
        host.appendChild(clone);
        document.body.appendChild(host);
        return { host: host, node: clone };
    }

    function capture(trigger) {
        var sel = trigger.getAttribute('data-target');
        var target = sel ? document.querySelector(sel) : null;
        if (!target) {
            notice(document.body, i18n('failed', 'Could not generate the PDF.'), true);
            return;
        }
        var filename = trigger.getAttribute('data-filename') || 'tt-export.pdf';
        var orientation = trigger.getAttribute('data-orientation') === 'portrait' ? 'portrait' : 'landscape';
        var cssWidth = PAGE_CSS_W[orientation];

        trigger.disabled = true;
        notice(document.body, i18n('working', 'Preparing PDF…'), false);

        var staged = stage(target, cssWidth);

        function cleanUp() {
            if (staged && staged.host && staged.host.parentNode) {
                staged.host.parentNode.removeChild(staged.host);
            }
        }

        ensureLibs().then(function (libs) {
            return libs.html2canvas(staged.node, {
                // The stage is what gets measured, so html2canvas is told the
                // window is that wide too — otherwise media queries in the
                // clone resolve against the coach's real viewport and undo
                // the point of staging.
                windowWidth: cssWidth,
                width: cssWidth,
                backgroundColor: '#ffffff',
                scale: Math.min(2, window.devicePixelRatio || 1),
                useCORS: true,
                logging: false,
                // #2756 — the page's real scroll offsets. html2canvas paints
                // against a viewport rectangle derived from these; a zero or
                // negated offset shifts that rectangle, so every box-shadow in
                // the capture lands somewhere other than around its element.
                // On a surface that clips its children (.tt-mp-pitch is
                // overflow:hidden) the misplaced shadow gets clipped INTO the
                // element as a hard-edged dark block. These are html2canvas's
                // own defaults; passed explicitly so the intent is visible.
                scrollX: window.scrollX,
                scrollY: window.scrollY,
                // Prepare the cloned node: tag it so surfaces can supply
                // capture-only CSS (e.g. force opaque fills where html2canvas
                // can't resolve a nested CSS custom property), and drop the
                // placeholder hints that CSS alone can't suppress. Applied to
                // the clone only, so the on-screen page never changes.
                onclone: function (clonedDoc) {
                    var c = clonedDoc.querySelector('[data-tt-pdf-stage]');
                    if (!c) return;
                    c.classList.add('tt-image-pdf-capture');
                    stripPlaceholders(c);
                }
            }).then(function (canvas) {
                var pdf = buildPdf(libs.jsPDF, canvas, orientation, cssWidth);
                pdf.save(filename);
                clearNotice();
            });
        }).catch(function () {
            // Graceful fallback: a clear, translatable failure notice.
            // The server-side DomPDF export remains reachable as the
            // documented fallback path (see docs/match-prep.md).
            notice(document.body, i18n('failed', 'Could not generate the PDF. Try the print dialog instead.'), true);
        }).then(function () {
            cleanUp();
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
        capture: function (selector, filename, orientation) {
            var fake = document.createElement('button');
            fake.setAttribute('data-target', selector);
            if (filename) fake.setAttribute('data-filename', filename);
            if (orientation) fake.setAttribute('data-orientation', orientation);
            capture(fake);
        }
    };
})();
