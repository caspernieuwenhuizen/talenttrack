/* ---------------------------------------------------------------------------
 * Player photo picker (#1665).
 *
 * Opens the WordPress media frame to choose a player photo. Enqueued with a
 * `media-editor` dependency so `wp.media` is guaranteed loaded before this
 * runs — the previous inline version bound its click handler at body-parse
 * time, before wp_enqueue_media() printed wp.media in the footer, so the
 * button was inert. Config (titles) comes from wp_localize_script.
 * --------------------------------------------------------------------------- */

(function () {
    'use strict';

    function init() {
        var cfg     = window.TTPlayerPhoto || {};
        var pickBtn = document.getElementById('tt-player-photo-pick');
        if (!pickBtn || typeof wp === 'undefined' || !wp.media) return;

        var clearBtn = document.getElementById('tt-player-photo-clear');
        var hidden   = document.getElementById('tt-player-photo-url');
        var preview  = document.getElementById('tt-player-photo-preview');
        var frame;

        pickBtn.addEventListener('click', function () {
            if (!frame) {
                frame = wp.media({
                    title: cfg.selectTitle || 'Select photo',
                    button: { text: cfg.useText || 'Use' },
                    library: { type: 'image' },
                    multiple: false
                });
                frame.on('select', function () {
                    var att = frame.state().get('selection').first().toJSON();
                    if (hidden) {
                        hidden.value = att.url;
                        hidden.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                    if (preview) {
                        var img = document.createElement('img');
                        img.src = att.url;
                        img.alt = '';
                        img.style.cssText = 'max-height:120px; border-radius:6px; border:1px solid var(--tt-line);';
                        preview.innerHTML = '';
                        preview.appendChild(img);
                    }
                });
            }
            frame.open();
        });

        if (clearBtn) {
            clearBtn.addEventListener('click', function () {
                if (hidden) {
                    hidden.value = '';
                    hidden.dispatchEvent(new Event('change', { bubbles: true }));
                }
                if (preview) preview.innerHTML = '';
            });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}());
