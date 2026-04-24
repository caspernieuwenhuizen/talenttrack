<?php
namespace TT\Modules\DemoData;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * DemoMode — read/write the site-level tt_demo_mode toggle.
 *
 * Values:
 *   'off'     — normal operation. Demo-tagged rows hidden from every
 *               read path the plugin owns. (Default.)
 *   'on'      — demo mode. ONLY demo-tagged rows are visible. Real
 *               club data is invisible but untouched on disk.
 *   'neutral' — special: used by the demo admin page itself so it
 *               can always see all demo rows regardless of toggle
 *               state. Never set site-wide.
 */
class DemoMode {

    public const OFF     = 'off';
    public const ON      = 'on';
    public const NEUTRAL = 'neutral';

    private const OPTION = 'tt_demo_mode';

    public static function current(): string {
        $value = (string) get_option( self::OPTION, self::OFF );
        return in_array( $value, [ self::OFF, self::ON ], true ) ? $value : self::OFF;
    }

    public static function set( string $mode ): void {
        if ( ! in_array( $mode, [ self::OFF, self::ON ], true ) ) {
            return;
        }
        update_option( self::OPTION, $mode );
    }

    public static function isOn(): bool {
        return self::current() === self::ON;
    }

    /**
     * Short-lived request-scoped override: force neutral so the demo
     * admin page sees the full demo dataset even when the site toggle
     * is off. Set by DemoDataPage at render time, consumed by
     * apply_demo_scope().
     */
    private static ?string $request_override = null;

    public static function overrideForRequest( string $mode ): void {
        self::$request_override = in_array( $mode, [ self::OFF, self::ON, self::NEUTRAL ], true )
            ? $mode
            : null;
    }

    public static function clearOverride(): void {
        self::$request_override = null;
    }

    public static function effective(): string {
        return self::$request_override ?? self::current();
    }
}
