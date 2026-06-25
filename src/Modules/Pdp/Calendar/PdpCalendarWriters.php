<?php
namespace TT\Modules\Pdp\Calendar;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * PdpCalendarWriters — factory + filter seam. Callers don't `new` the
 * writer directly; they ask for the default. #0031 (Spond) plugs in via
 * `tt_pdp_calendar_writer` and returns its own implementation.
 */
class PdpCalendarWriters {

    public static function default(): PdpCalendarWriter {
        // #1538 — when the calendar-integration sub-feature is off, hand
        // back a no-op writer so every caller (REST create, season
        // carryover, future hooks) skips the calendar-feed write without
        // each having to guard the flag itself.
        if ( ! \TT\Core\FeatureRegistry::isEnabled( 'pdp_calendar_integration' ) ) {
            return new NullCalendarWriter();
        }
        $writer = apply_filters( 'tt_pdp_calendar_writer', null );
        if ( $writer instanceof PdpCalendarWriter ) return $writer;
        return new NativeCalendarWriter();
    }
}
