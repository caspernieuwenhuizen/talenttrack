<?php
namespace TT\Modules\AdminCenterClient;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\Container;
use TT\Core\ModuleInterface;
use TT\Modules\AdminCenterClient\Cron\DailyCron;
use TT\Modules\AdminCenterClient\Hooks\ActivationHook;
use TT\Modules\AdminCenterClient\Hooks\VersionChangeHook;

/**
 * AdminCenterClientModule (#0065 / TTA #0001) — phone-home client.
 *
 * Owns:
 *   - Daily cron (DailyCron::HOOK)
 *   - Single-shot activation cron (ActivationHook)
 *   - Version-change detection on init (VersionChangeHook)
 *   - InstallId persistence (lazy on first read)
 *   - Payload assembly (PayloadBuilder)
 *   - Canonical-JSON HMAC signing (Signer)
 *   - Fire-and-forget HTTPS send (Sender)
 *
 * Deactivation send is wired from `talenttrack.php`'s
 * `register_deactivation_hook` because by the time a Module's
 * `boot()` runs on the deactivating request, hooks have already
 * been detached.
 */
class AdminCenterClientModule implements ModuleInterface {

    public function getName(): string { return 'admin-center-client'; }

    public function register( Container $container ): void {}

    public function boot( Container $container ): void {
        DailyCron::init();
        ActivationHook::init();
        VersionChangeHook::init();
    }
}
