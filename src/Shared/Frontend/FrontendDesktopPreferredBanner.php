<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Shared\Tiles\TileRegistry;

/**
 * FrontendDesktopPreferredBanner (#0056) — non-blocking notice on
 * surfaces flagged `desktop_preferred = true` in TileRegistry.
 *
 * The banner renders inside a CSS-gated wrapper so it ONLY shows up on
 * `(pointer: coarse) and (max-width: 767px)` — phones in coarse-pointer
 * mode. Tablets, desktops, and Bluetooth-mouse setups never see it.
 *
 * Dismiss is per-device (localStorage); reappearance suppressed until
 * the user clears that flag. "Continue" hides for the page session
 * only.
 */
final class FrontendDesktopPreferredBanner {

    public static function render( string $view_slug ): void {
        if ( ! TileRegistry::isDesktopPreferred( $view_slug ) ) return;

        $key = 'tt_dpb_dismissed_' . preg_replace( '/[^a-z0-9_-]/', '_', strtolower( $view_slug ) );
        ?>
        <div class="tt-dpb-wrap" data-tt-dpb-key="<?php echo esc_attr( $key ); ?>">
            <div class="tt-notice tt-dpb">
                <p style="margin:0 0 8px;"><strong>
                    <?php esc_html_e( 'This page works best on a tablet or laptop.', 'talenttrack' ); ?>
                </strong></p>
                <p style="margin:0 0 8px;color:#5b6e75;font-size:13px;">
                    <?php esc_html_e( 'You can keep going on your phone, but a bigger screen makes editing easier.', 'talenttrack' ); ?>
                </p>
                <p style="margin:0;display:flex;gap:8px;">
                    <button type="button" class="tt-btn tt-btn-secondary" data-tt-dpb-action="continue">
                        <?php esc_html_e( 'Continue', 'talenttrack' ); ?>
                    </button>
                    <button type="button" class="tt-btn tt-btn-secondary" data-tt-dpb-action="dismiss">
                        <?php esc_html_e( 'Dismiss for now', 'talenttrack' ); ?>
                    </button>
                </p>
            </div>
        </div>
        <style>
            .tt-dpb-wrap { display: none; margin: 12px 0; }
            @media (pointer: coarse) and (max-width: 767px) {
                .tt-dpb-wrap[data-tt-dpb-visible="1"] { display: block; }
            }
            .tt-dpb {
                background: #fef3c7;
                border-left: 4px solid #d97706;
                padding: 12px 14px;
                border-radius: 6px;
            }
            .tt-dpb .tt-btn { min-height: 48px; padding: 0 16px; }
        </style>
        <script>
        (function(){
            var wrap = document.querySelector('.tt-dpb-wrap[data-tt-dpb-key="<?php echo esc_js( $key ); ?>"]');
            if ( ! wrap ) return;
            try {
                if ( window.localStorage && window.localStorage.getItem( wrap.dataset.ttDpbKey ) === '1' ) return;
            } catch ( e ) {}
            wrap.setAttribute('data-tt-dpb-visible', '1');
            wrap.querySelector('[data-tt-dpb-action="continue"]').addEventListener('click', function(){
                wrap.removeAttribute('data-tt-dpb-visible');
            });
            wrap.querySelector('[data-tt-dpb-action="dismiss"]').addEventListener('click', function(){
                try { window.localStorage.setItem( wrap.dataset.ttDpbKey, '1' ); } catch ( e ) {}
                wrap.removeAttribute('data-tt-dpb-visible');
            });
        })();
        </script>
        <?php
    }
}
