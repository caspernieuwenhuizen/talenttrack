<?php
namespace TT\Modules\Push;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\Container;
use TT\Core\ModuleInterface;
use TT\Modules\Push\Cron\PrunePushSubscriptions;
use TT\Modules\Push\Rest\PushSubscriptionsRestController;

/**
 * PushModule (#0042) — youth-aware push notification channel.
 *
 * Owns:
 *   - Schema (migration 0046): tt_push_subscriptions.
 *   - VAPID key lifecycle (VapidKeyManager).
 *   - REST surface for register/list/revoke (PushSubscriptionsRestController).
 *   - Daily prune cron for stale subscriptions (PrunePushSubscriptions).
 *   - Service worker + client subscribe glue (assets/js/tt-sw.js,
 *     tt-push-client.js).
 *   - DispatcherChain — push / parent_email / email channel selection,
 *     consumed by the workflow engine when a template specifies
 *     a non-default `dispatcher_chain`.
 *
 * The module is self-contained: disabling it via config/modules.php
 * stops the cron and unloads the REST routes; the workflow engine
 * falls back to email-only dispatch.
 */
class PushModule implements ModuleInterface {

    public function getName(): string { return 'push'; }

    public function register( Container $container ): void {}

    public function boot( Container $container ): void {
        VapidKeyManager::ensureKeys();

        PushSubscriptionsRestController::init();
        PrunePushSubscriptions::init();

        // Client glue runs on the same surfaces the dashboard runs on.
        // tt-sw.js is registered from the page itself (not enqueued)
        // because service-worker scope is the URL the SW file lives at.
        add_action( 'wp_enqueue_scripts',    [ self::class, 'enqueueClient' ] );
        add_action( 'admin_enqueue_scripts', [ self::class, 'enqueueClient' ] );
    }

    /**
     * Enqueue the push-client glue + localize VAPID public key. The
     * service worker file at /wp-content/plugins/talenttrack/assets/js/tt-sw.js
     * is registered by the client-side script (not enqueued via
     * wp_enqueue_script — service workers are loaded by the browser
     * directly through `navigator.serviceWorker.register`).
     */
    public static function enqueueClient(): void {
        if ( ! is_user_logged_in() ) return;
        if ( ! VapidKeyManager::hasKeys() )  return;

        wp_enqueue_script(
            'tt-push-client',
            TT_PLUGIN_URL . 'assets/js/tt-push-client.js',
            [],
            TT_VERSION,
            true
        );
        wp_localize_script( 'tt-push-client', 'TT_PUSH', [
            'vapidPublic' => VapidKeyManager::publicKey(),
            'swUrl'       => TT_PLUGIN_URL . 'assets/js/tt-sw.js',
            'restUrl'     => esc_url_raw( rest_url( 'talenttrack/v1/push-subscriptions' ) ),
            'nonce'       => wp_create_nonce( 'wp_rest' ),
        ] );
    }
}
