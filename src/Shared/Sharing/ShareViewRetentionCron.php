<?php
namespace TT\Shared\Sharing;

use TT\Infrastructure\Config\ConfigService;
use TT\Modules\Workflow\Dispatchers\CronDispatcher;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * ShareViewRetentionCron (#3096) — folds share-view rows away after 90 days.
 *
 * `visitor_hash` is derived from a cookie id or, on the fallback path, from
 * an IP and a user-agent. Keeping it indefinitely would mean keeping a
 * durable handle on a person who only ever read a document, so the row has
 * a life span. The count survives it (see `ShareViewQuery::purgeOlderThan`);
 * the handle does not.
 *
 * Ninety days, matching `Alerts\Cron\AlertRetentionCron` — same posture,
 * same cadence, and one number for an operator to know rather than two.
 * Not configurable, for the reason that class already gives: shortening it
 * would be fine, lengthening it would quietly undo the decision, and a
 * setting invites the second.
 *
 * Rides the existing workflow tick rather than registering its own
 * `wp_cron` entry (CLAUDE.md §4) — one scheduler chokepoint is replaceable
 * on the way to SaaS; fifty registrations are not.
 */
final class ShareViewRetentionCron {

    public const RETENTION_DAYS = 90;

    /** tt_config key: date of the last purge, per club. */
    public const LAST_RUN_CONFIG_KEY = 'tt_share_views_last_purge_date';

    /** @var ConfigService */
    private $config;

    public function __construct( ?ConfigService $config = null ) {
        $this->config = $config ?? new ConfigService();
    }

    /** Priority 40: after the alert retention purge (35). */
    public static function init(): void {
        add_action( CronDispatcher::TICK_HOOK, [ self::class, 'onTick' ], 40 );
    }

    public static function onTick(): void {
        ( new self() )->maybeRun();
    }

    /** Once per calendar day per club. */
    public function maybeRun(): void {
        $today = current_time( 'Y-m-d' );
        foreach ( $this->clubIds() as $club_id ) {
            $this->withClub( $club_id, function () use ( $today ) {
                if ( $this->config->get( self::LAST_RUN_CONFIG_KEY, '' ) === $today ) return;
                $this->runForCurrentClub();
                $this->config->set( self::LAST_RUN_CONFIG_KEY, $today );
            } );
        }
    }

    /** @return int rows deleted */
    public function runForCurrentClub(): int {
        return ( new ShareViewQuery() )->purgeOlderThan(
            self::RETENTION_DAYS,
            current_time( 'mysql' )
        );
    }

    /** @return int[] */
    private function clubIds(): array {
        global $wpdb;
        $ids = $wpdb->get_col( "SELECT DISTINCT club_id FROM {$wpdb->prefix}tt_config" );
        $ids = array_values( array_unique( array_map( 'intval', is_array( $ids ) ? $ids : [] ) ) );
        if ( ! in_array( 1, $ids, true ) ) $ids[] = 1;
        return array_filter( $ids, static function ( $id ) { return $id > 0; } );
    }

    /**
     * @template T
     * @param callable():T $fn
     * @return T
     */
    private function withClub( int $club_id, callable $fn ) {
        $filter = static function () use ( $club_id ) { return $club_id; };
        add_filter( 'tt_current_club_id', $filter, 9999 );
        $this->config = new ConfigService();
        try {
            return $fn();
        } finally {
            remove_filter( 'tt_current_club_id', $filter, 9999 );
            $this->config = new ConfigService();
        }
    }
}
