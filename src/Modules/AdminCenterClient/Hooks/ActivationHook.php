<?php
namespace TT\Modules\AdminCenterClient\Hooks;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\AdminCenterClient\PayloadBuilder;
use TT\Modules\AdminCenterClient\Sender;

/**
 * ActivationHook — fires a single phone-home with `trigger: "activated"`
 * shortly after plugin activation (#0065 / TTA #0001).
 *
 * Deliberately fire-and-forget via a 30-second-out single-shot
 * wp-cron event so the activation request itself never waits on
 * the HTTPS round-trip.
 */
final class ActivationHook {

    public const SINGLE_HOOK = 'tt_admin_center_activated_send';

    public static function init(): void {
        add_action( self::SINGLE_HOOK, [ self::class, 'send' ] );
    }

    /** Called from talenttrack.php's register_activation_hook. */
    public static function schedule(): void {
        if ( ! wp_next_scheduled( self::SINGLE_HOOK ) ) {
            wp_schedule_single_event( time() + 30, self::SINGLE_HOOK );
        }
    }

    public static function send(): void {
        Sender::send( PayloadBuilder::TRIGGER_ACTIVATED );
    }
}
