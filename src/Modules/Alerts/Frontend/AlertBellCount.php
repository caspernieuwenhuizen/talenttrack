<?php
namespace TT\Modules\Alerts\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Alerts\Domain\Surface;
use TT\Modules\Alerts\Services\AlertCounter;

/**
 * AlertBellCount (#2631, epic #2629) — the `badge` surface.
 *
 * Adds open alert occurrences to the notification bell's number via the
 * `tt_notification_bell_count` filter that #2631 added to
 * `Workflow\Frontend\NotificationBell`.
 *
 * Reads persisted rows only. Like the banner, this never evaluates.
 */
final class AlertBellCount {

    public static function init(): void {
        add_filter( 'tt_notification_bell_count', [ self::class, 'add' ], 10, 2 );
    }

    public static function add( int $count, int $user_id ): int {
        if ( $user_id <= 0 ) return $count;

        // #2632 — count only what this user's preferences let the badge
        // show. A muted alert that still moved the bell would be the most
        // annoying possible outcome: the number goes up, the user clicks,
        // and there is nothing there.
        //
        // #4222 — counted by `AlertCounter`, the same helper the phone
        // dashboard's summary line uses, so both stop at the same ceiling.
        // The filter carries an int only; `AlertBell` asks the counter for
        // the capped flag to show "50+".
        $alerts = AlertCounter::forUser( $user_id, Surface::BADGE );

        return $count + $alerts['count'];
    }
}
