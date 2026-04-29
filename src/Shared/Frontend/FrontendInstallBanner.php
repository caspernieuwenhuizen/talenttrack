<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Identity\PhoneMeta;
use TT\Infrastructure\Security\RoleResolver;
use TT\Modules\Push\PushSubscriptionsRepository;

/**
 * FrontendInstallBanner (#0042) — post-login nudge for players +
 * parents to install the PWA and accept push notifications.
 *
 * Only renders when:
 *   1. Viewer holds `tt_player` or `tt_parent`.
 *   2. They have no active push subscription on this device-class.
 *   3. They haven't dismissed the banner on this device (per-device
 *      localStorage flag — separate from per-user dismissal so a
 *      shared family device can still be set up later for a sibling).
 *
 * The banner links to the user-agent-matched KB article — iOS Safari
 * lands on `install-on-iphone`, Android Chrome on `install-on-android`,
 * everything else on the generic `notifications-setup` walkthrough.
 *
 * "Enable notifications" wires through to `window.TT.push.subscribe()`
 * exposed by tt-push-client.js. If the SW can subscribe right away
 * (we're inside the installed PWA), the banner self-dismisses on
 * success. Otherwise it falls through to the install article.
 */
final class FrontendInstallBanner {

    public static function render(): void {
        $user_id = get_current_user_id();
        if ( $user_id <= 0 ) return;
        if ( ! self::eligibleViewer( $user_id ) ) return;
        if ( ! self::hasPhoneOnFile( $user_id ) && ! self::isParent( $user_id ) ) {
            // Players without a phone can still install + receive pushes
            // on this browser, but the verify-on-push loop won't anchor
            // to anything. Skip the banner; surface lives in the
            // profile editor instead (Sprint 2 form field).
            return;
        }
        if ( self::hasActiveSubscription( $user_id ) ) return;

        $base_url    = home_url( '/' );
        $docs_slug   = self::pickArticleForUserAgent();
        $article_url = add_query_arg(
            [ 'tt_view' => 'docs', 'topic' => $docs_slug ],
            $base_url
        );
        ?>
        <div class="tt-install-banner-wrap" data-tt-install-key="tt_install_dismissed">
            <div class="tt-notice tt-install-banner" role="status">
                <p style="margin:0 0 8px;"><strong>
                    <?php esc_html_e( 'Get TalentTrack on your phone', 'talenttrack' ); ?>
                </strong></p>
                <p style="margin:0 0 8px;color:#5b6e75;font-size:13px;">
                    <?php esc_html_e( 'Install the app to see new tasks, evaluations, and goal updates the moment they happen.', 'talenttrack' ); ?>
                </p>
                <p style="margin:0;display:flex;flex-wrap:wrap;gap:8px;">
                    <button type="button" class="tt-btn tt-btn-primary" data-tt-install-action="enable">
                        <?php esc_html_e( 'Enable notifications', 'talenttrack' ); ?>
                    </button>
                    <a class="tt-btn tt-btn-secondary" href="<?php echo esc_url( $article_url ); ?>">
                        <?php esc_html_e( 'Install instructions', 'talenttrack' ); ?>
                    </a>
                    <button type="button" class="tt-btn tt-btn-link" data-tt-install-action="dismiss">
                        <?php esc_html_e( 'Not now', 'talenttrack' ); ?>
                    </button>
                </p>
            </div>
        </div>
        <style>
            .tt-install-banner-wrap { margin: 12px 0; }
            .tt-install-banner {
                background: #ecfdf5;
                border-left: 4px solid #059669;
                padding: 12px 14px;
                border-radius: 6px;
            }
            .tt-install-banner .tt-btn { min-height: 48px; padding: 0 16px; }
            .tt-install-banner-wrap[hidden] { display: none; }
        </style>
        <script>
        (function(){
            var wrap = document.querySelector('.tt-install-banner-wrap[data-tt-install-key]');
            if ( ! wrap ) return;
            try {
                if ( window.localStorage && window.localStorage.getItem( wrap.dataset.ttInstallKey ) === '1' ) {
                    wrap.hidden = true;
                    return;
                }
            } catch ( e ) {}
            var dismiss = function(){
                try { window.localStorage.setItem( wrap.dataset.ttInstallKey, '1' ); } catch ( e ) {}
                wrap.hidden = true;
            };
            var enableBtn = wrap.querySelector('[data-tt-install-action="enable"]');
            var dismissBtn = wrap.querySelector('[data-tt-install-action="dismiss"]');
            if ( dismissBtn ) dismissBtn.addEventListener('click', dismiss);
            if ( enableBtn ) enableBtn.addEventListener('click', function(){
                if ( window.TT && window.TT.push && typeof window.TT.push.subscribe === 'function' ) {
                    window.TT.push.subscribe().then(function(){ dismiss(); }).catch(function(){});
                }
            });
        })();
        </script>
        <?php
    }

    private static function eligibleViewer( int $user_id ): bool {
        return RoleResolver::userHasRole( $user_id, 'tt_player' )
            || RoleResolver::userHasRole( $user_id, 'tt_parent' );
    }

    private static function isParent( int $user_id ): bool {
        return RoleResolver::userHasRole( $user_id, 'tt_parent' );
    }

    private static function hasPhoneOnFile( int $user_id ): bool {
        return PhoneMeta::exists( $user_id );
    }

    private static function hasActiveSubscription( int $user_id ): bool {
        if ( ! class_exists( PushSubscriptionsRepository::class ) ) return false;
        return ! empty( ( new PushSubscriptionsRepository() )->activeForUser( $user_id ) );
    }

    /**
     * Pick the KB slug that matches the viewer's user-agent. Best-effort
     * — failure to detect just lands on the generic notifications-setup
     * walkthrough rather than guessing wrong.
     */
    private static function pickArticleForUserAgent(): string {
        $ua = (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' );
        if ( $ua === '' ) return 'notifications-setup';
        if ( preg_match( '#iPhone|iPad|iPod#i', $ua ) ) return 'install-on-iphone';
        if ( preg_match( '#Android#i', $ua ) )         return 'install-on-android';
        return 'notifications-setup';
    }
}
