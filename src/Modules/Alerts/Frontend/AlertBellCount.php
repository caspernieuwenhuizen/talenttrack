<?php
namespace TT\Modules\Alerts\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Alerts\Domain\Surface;
use TT\Modules\Alerts\Policy\AlertPolicyResolver;
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

    /**
     * Ceiling on occurrences inspected for the badge count. A bell showing
     * "50+" is as actionable as one showing the true 137, and this runs on
     * every page render that includes the admin bar.
     */
    private const MAX_COUNTED = 50;

    public static function init(): void {
        add_filter( 'tt_notification_bell_count', [ self::class, 'add' ], 10, 2 );
    }

    public static function add( int $count, int $user_id ): int {
        if ( $user_id <= 0 ) return $count;

        $repo = new AlertOccurrencesRepository();
        if ( ! $repo->tableExists() ) return $count;

        // #2632 — count only what this user's preferences let the badge
        // show. A muted alert that still moved the bell would be the most
        // annoying possible outcome: the number goes up, the user clicks,
        // and there is nothing there.
        //
        // `openForUser` rather than `openCountForUser`: the filter is
        // per-alert-key, so the rows have to be inspected. It is capped, and
        // a user with more open alerts than the cap sees the cap — a bell
        // reading "50+" is as actionable as one reading the true 137.
        $policy = new AlertPolicyResolver();
        $rows   = $repo->openForUser( $user_id, self::MAX_COUNTED );

        $counted = 0;
        foreach ( $rows as $row ) {
            if ( $policy->allows( $user_id, (string) ( $row->alert_key ?? '' ), Surface::BADGE ) ) {
                $counted++;
            }
        }

        return $count + $counted;
    }
}
