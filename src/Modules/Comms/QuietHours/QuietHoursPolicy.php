<?php
namespace TT\Modules\Comms\QuietHours;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Comms\Domain\CommsRequest;
use TT\Modules\Comms\Domain\MessageType;

/**
 * QuietHoursPolicy (#0066) — never send a non-emergency message at
 * 21:00–07:00 local time.
 *
 * Per spec § "Cross-cutting concerns": "never send a non-emergency
 * message between 21:00 and 07:00 local; emergencies (safeguarding,
 * cancellation within 12h) bypass". Implementation:
 *
 *   - Operational message types bypass unconditionally
 *     (`MessageType::isOperational`).
 *   - Non-operational types with `urgent = true` on the request bypass.
 *   - Everything else: send only when `wp_timezone()` local time is
 *     in the 07:00–21:00 window.
 *
 * Per-club override via `tt_config` keys
 * `comms_quiet_hours_start` / `comms_quiet_hours_end` (HH:MM strings).
 * Defaults match the spec.
 *
 * The dispatcher consults this before invoking the channel adapter.
 * Quiet-hours skip is logged with `status = 'quiet_hours'` so the
 * operator can see what got deferred.
 */
final class QuietHoursPolicy {

    private const DEFAULT_START = '21:00';
    private const DEFAULT_END   = '07:00';

    public function shouldDefer( CommsRequest $request ): bool {
        if ( MessageType::isOperational( $request->messageType ) ) return false;
        if ( $request->urgent ) return false;
        if ( MessageType::bypassesQuietHours( $request->messageType ) ) return false;

        $tz   = wp_timezone();
        $now  = new \DateTimeImmutable( 'now', $tz );
        $hh   = (int) $now->format( 'H' );
        $mm   = (int) $now->format( 'i' );
        $minutes = $hh * 60 + $mm;

        $start = self::parseHHMM( self::configOrDefault( 'comms_quiet_hours_start', self::DEFAULT_START ) );
        $end   = self::parseHHMM( self::configOrDefault( 'comms_quiet_hours_end',   self::DEFAULT_END ) );

        // Quiet hours wrap midnight (21:00 → 07:00 spans 21:00-23:59 + 00:00-06:59).
        if ( $start > $end ) {
            return $minutes >= $start || $minutes < $end;
        }
        // Non-wrapping (e.g. 22:00 → 23:30).
        return $minutes >= $start && $minutes < $end;
    }

    private static function configOrDefault( string $configKey, string $default ): string {
        if ( ! class_exists( '\\TT\\Infrastructure\\Query\\QueryHelpers' ) ) return $default;
        $value = (string) \TT\Infrastructure\Query\QueryHelpers::get_config( $configKey, $default );
        return $value !== '' ? $value : $default;
    }

    private static function parseHHMM( string $hhmm ): int {
        if ( ! preg_match( '/^(\d{1,2}):(\d{2})$/', $hhmm, $m ) ) return 0;
        $h = max( 0, min( 23, (int) $m[1] ) );
        $mn = max( 0, min( 59, (int) $m[2] ) );
        return $h * 60 + $mn;
    }
}
