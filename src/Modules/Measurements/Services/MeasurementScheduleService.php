<?php
namespace TT\Modules\Measurements\Services;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * MeasurementScheduleService (#1882) — pure recurrence logic. Turns a
 * test's frequency + a player's last result date into a coverage status,
 * so the insights layer can answer "who is due / overdue". No DB access;
 * injectable "now" for tests.
 *
 * Frequencies are season-aligned (annual / biannual / quarterly / monthly);
 * `adhoc` (and anything unknown) has no cadence and is never "due".
 */
class MeasurementScheduleService {

    const UP_TO_DATE    = 'up_to_date';
    const DUE_SOON      = 'due_soon';
    const OVERDUE       = 'overdue';
    const NEVER         = 'never';          // scheduled, but no result ever recorded
    const NOT_SCHEDULED = 'not_scheduled';  // adhoc / unknown frequency

    /** Statuses that represent a coverage gap (a player who needs testing). */
    const GAP_STATUSES = [ self::OVERDUE, self::NEVER, self::DUE_SOON ];

    /** Interval in days for a frequency, or null when there is no cadence. */
    public static function intervalDays( string $frequency ): ?int {
        switch ( $frequency ) {
            case 'annual':    return 365;
            case 'biannual':  return 182;
            case 'quarterly': return 91;
            case 'monthly':   return 30;
            default:          return null; // adhoc / unknown
        }
    }

    /**
     * Coverage status for one player + one definition.
     *
     * @param string      $frequency the definition's frequency
     * @param string|null $last_date the player's most recent result date (Y-m-d), or null
     * @param int|null    $now_ts    Unix timestamp for "now"; defaults to current_time
     */
    public static function status( string $frequency, ?string $last_date, ?int $now_ts = null ): string {
        $interval = self::intervalDays( $frequency );
        if ( $interval === null ) return self::NOT_SCHEDULED;

        $last = is_string( $last_date ) && $last_date !== '' ? strtotime( $last_date . ' 00:00:00 UTC' ) : false;
        if ( $last === false ) return self::NEVER;

        $now  = $now_ts !== null ? $now_ts : (int) current_time( 'timestamp', true );
        $days = (int) floor( ( $now - $last ) / DAY_IN_SECONDS );

        if ( $days > $interval )            return self::OVERDUE;
        if ( $days >= (int) ( $interval * 0.8 ) ) return self::DUE_SOON;
        return self::UP_TO_DATE;
    }
}
