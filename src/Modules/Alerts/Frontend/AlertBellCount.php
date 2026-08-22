<?php
namespace TT\Modules\Alerts\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Alerts\Repositories\AlertOccurrencesRepository;

/**
 * AlertBellCount (#2631, epic #2629) — the `badge` surface.
 *
 * Adds open alert occurrences to the notification bell's number via the
 * `tt_notification_bell_count` filter that #2631 added to
 * `Workflow\Frontend\NotificationBell`.
 *
 * Wave 1 contributes a number and nothing else: clicking the bell still
 * lands on the task inbox. That is a deliberate half-step — the bell
 * becomes an alerts surface proper in #2635, when tasks stop being a
 * hard-wired special case and start being one definition among many. Until
 * then a count that includes alerts is strictly better than one that
 * silently excludes them while the dashboard shows three alert banners.
 *
 * Reads persisted rows only. Like the banner, this never evaluates.
 */
final class AlertBellCount {

    public static function init(): void {
        add_filter( 'tt_notification_bell_count', [ self::class, 'add' ], 10, 2 );
    }

    public static function add( int $count, int $user_id ): int {
        if ( $user_id <= 0 ) return $count;

        $repo = new AlertOccurrencesRepository();
        if ( ! $repo->tableExists() ) return $count;

        return $count + $repo->openCountForUser( $user_id );
    }
}
