<?php
namespace TT\Modules\Spond;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * SpondFetchWindow (#4182) — the one definition of which events a sync
 * asks Spond about.
 *
 * The client fetches events starting between `startUtc()` and `endUtc()`.
 * The sync may only conclude that an event was removed in Spond when the
 * feed was actually asked about its date; an event that is simply older
 * than the window is absent from the response for that reason alone. So
 * the same instance drives both the fetch and the archive query, and the
 * archive query stays on the dates the window covers in full.
 *
 * The date bounds are exclusive on purpose. The window starts and ends at
 * a clock time, and `session_date` is a bare local date: an event on the
 * boundary day may sit on either side of that instant, so the boundary
 * day is never treated as covered.
 */
final class SpondFetchWindow {

    /** Days back from now the fetch reaches. */
    public const PAST_DAYS = 30;

    /** Days ahead of now the fetch reaches. */
    public const FUTURE_DAYS = 180;

    /** @var int */
    private $start;

    /** @var int */
    private $end;

    /** @var \DateTimeZone */
    private $tz;

    private function __construct( int $start, int $end, \DateTimeZone $tz ) {
        $this->start = $start;
        $this->end   = $end;
        $this->tz    = $tz;
    }

    /**
     * The window around `$now` (a Unix timestamp; defaults to the current
     * time), with dates read in `$tz` (defaults to the site timezone).
     */
    public static function around( ?int $now = null, ?\DateTimeZone $tz = null ): self {
        $now = $now ?? time();
        return new self(
            $now - self::PAST_DAYS * DAY_IN_SECONDS,
            $now + self::FUTURE_DAYS * DAY_IN_SECONDS,
            $tz ?? wp_timezone()
        );
    }

    /** Spond's `minStartTimestamp`. */
    public function startUtc(): string {
        return gmdate( 'Y-m-d\TH:i:s.000\Z', $this->start );
    }

    /** Spond's `maxStartTimestamp`. */
    public function endUtc(): string {
        return gmdate( 'Y-m-d\TH:i:s.000\Z', $this->end );
    }

    /**
     * Local date the window starts on. Rows dated strictly after it were
     * covered by the fetch.
     */
    public function firstDateExclusive(): string {
        return $this->localDate( $this->start );
    }

    /**
     * Local date the window ends on. Rows dated strictly before it were
     * covered by the fetch.
     */
    public function lastDateExclusive(): string {
        return $this->localDate( $this->end );
    }

    /** Is an activity on this `Y-m-d` date one the fetch was asked about? */
    public function covers( string $session_date ): bool {
        return $session_date > $this->firstDateExclusive()
            && $session_date < $this->lastDateExclusive();
    }

    private function localDate( int $ts ): string {
        return ( new \DateTimeImmutable( '@' . $ts ) )->setTimezone( $this->tz )->format( 'Y-m-d' );
    }
}
