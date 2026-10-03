<?php
namespace TT\Modules\Alerts\Services;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Alerts\Domain\Severity;
use TT\Modules\Alerts\Domain\Surface;
use TT\Modules\Alerts\Policy\AlertPolicyResolver;
use TT\Modules\Alerts\Repositories\AlertOccurrencesRepository;

/**
 * AlertCounter (#4222) — the one place that counts a user's open alerts.
 *
 * The bell and the phone dashboard's summary line used to count on their
 * own: the bell read 50 rows and showed "50" with no "+", the summary read
 * 23 rows and showed "23+". Two numbers for the same alerts, one screen
 * apart. Both now ask this class, so they read the same rows, apply the
 * same per-user surface policy and stop at the same ceiling.
 *
 * Reads persisted rows only, like every alert surface; it never evaluates.
 * The policy filter runs in PHP (`AlertPolicyResolver`), which is why this
 * reads rows instead of running a COUNT.
 */
final class AlertCounter {

    /**
     * Ceiling on counted occurrences. "50+" is as actionable as the true
     * 137, and this runs on every render that shows the bell.
     */
    public const CEILING = 50;

    /**
     * Open alerts for one user that the given surface may show.
     *
     * `count` never exceeds CEILING. `capped` is true when there were more
     * rows than the ceiling, so the number is a floor and reads with "+".
     * `urgent` counts urgent rows among the counted ones; rows arrive
     * loudest first, so it is exact unless every counted row was urgent and
     * the count was capped, which `urgent_capped` reports.
     *
     * @return array{count:int,capped:bool,urgent:int,urgent_capped:bool}
     */
    public static function forUser( int $user_id, string $surface = Surface::BADGE ): array {
        $empty = [ 'count' => 0, 'capped' => false, 'urgent' => 0, 'urgent_capped' => false ];
        if ( $user_id <= 0 ) return $empty;

        $repo = new AlertOccurrencesRepository();
        if ( ! $repo->tableExists() ) return $empty;

        // One row past the ceiling tells "exactly 50" apart from "more".
        $rows   = $repo->openForUser( $user_id, self::CEILING + 1 );
        $capped = count( $rows ) > self::CEILING;

        $policy = new AlertPolicyResolver();
        $count  = 0;
        $urgent = 0;
        foreach ( $rows as $row ) {
            if ( ! $policy->allows( $user_id, (string) ( $row->alert_key ?? '' ), $surface ) ) continue;
            if ( $count >= self::CEILING ) {
                $capped = true;
                break;
            }
            $count++;
            if ( Severity::normalise( (string) ( $row->severity ?? '' ) ) === Severity::URGENT ) {
                $urgent++;
            }
        }

        return [
            'count'         => $count,
            'capped'        => $capped,
            'urgent'        => $urgent,
            'urgent_capped' => $capped && $urgent > 0 && $urgent === $count,
        ];
    }

    /** "50+" when capped, "12" otherwise. */
    public static function display( int $count, bool $capped ): string {
        return $count . ( $capped ? '+' : '' );
    }
}
