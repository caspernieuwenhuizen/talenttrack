<?php
namespace TT\Modules\AdminCenterClient\Hooks;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\AdminCenterClient\PayloadBuilder;
use TT\Modules\AdminCenterClient\Sender;

/**
 * DeactivationHook — best-effort sync phone-home on plugin
 * deactivation (#0065 / TTA #0001).
 *
 * Synchronous so it gets one chance to fire before the cron
 * scheduler is detached. Never blocks the deactivation —
 * `Sender` itself swallows network errors silently.
 */
final class DeactivationHook {

    /** Called from talenttrack.php's register_deactivation_hook. */
    public static function fire(): void {
        Sender::send( PayloadBuilder::TRIGGER_DEACTIVATED );
    }
}
