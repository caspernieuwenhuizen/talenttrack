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
        $writer = apply_filters( 'tt_pdp_calendar_writer', null );
        if ( $writer instanceof PdpCalendarWriter ) return $writer;
        return new NativeCalendarWriter();
    }
}
