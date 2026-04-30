<?php
namespace TT\Modules\AdminCenterClient\Hooks;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\AdminCenterClient\PayloadBuilder;
use TT\Modules\AdminCenterClient\Sender;

/**
 * VersionChangeHook — fires a phone-home with
 * `trigger: "version_changed"` on the first request after a plugin
 * update (#0065 / TTA #0001).
 *
 * Detection: compare the running `TT_VERSION` constant against the
 * persisted `tt_last_phoned_version` in `wp_options`. When they
 * differ, schedule a single-shot cron event 5 seconds out so the
 * page request that triggered the detection isn't slowed down.
 */
final class VersionChangeHook {

    public const OPTION       = 'tt_last_phoned_version';
    public const SINGLE_HOOK  = 'tt_admin_center_version_changed_send';

    public static function init(): void {
        add_action( self::SINGLE_HOOK, [ self::class, 'send' ] );
        add_action( 'init',           [ self::class, 'check' ] );
    }

    public static function check(): void {
        if ( ! defined( 'TT_VERSION' ) ) return;

        $current = (string) TT_VERSION;
        $last    = (string) get_option( self::OPTION, '' );

        if ( $last === $current ) return;

        update_option( self::OPTION, $current, false );

        if ( $last === '' ) {
            return;
        }

        if ( ! wp_next_scheduled( self::SINGLE_HOOK ) ) {
            wp_schedule_single_event( time() + 5, self::SINGLE_HOOK );
        }
    }

    public static function send(): void {
        Sender::send( PayloadBuilder::TRIGGER_VERSION_CHANGED );
    }
}
