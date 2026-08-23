<?php
namespace TT\Modules\Alerts\Cron;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Config\ConfigService;
use TT\Modules\Alerts\Escalation\AlertEscalator;
use TT\Modules\Workflow\Dispatchers\CronDispatcher;

/**
 * AlertEscalationCron (#2635, epic #2629) — runs the escalator.
 *
 * Same heartbeat as the rest of the module, at priority 28: after the sweep
 * (25) so escalation judges the newest reconcile, and before the digest (30)
 * so an occurrence that just became a task can say so in the same digest
 * rather than being announced as an unowned alert one last time.
 *
 * Daily rather than hourly. Escalation creates assigned work for a person,
 * and the threshold it enforces is measured in days — checking twenty-four
 * times a day to see whether something has aged past seven days is work
 * without a purpose.
 */
final class AlertEscalationCron {

    /** tt_config key: date of the last escalation run, per club. */
    public const LAST_RUN_CONFIG_KEY = 'tt_alerts_last_escalation_date';

    /** @var ConfigService */
    private $config;

    public function __construct( ?ConfigService $config = null ) {
        $this->config = $config ?? new ConfigService();
    }

    public static function init(): void {
        add_action( CronDispatcher::TICK_HOOK, [ self::class, 'onTick' ], 28 );
    }

    public static function onTick(): void {
        ( new self() )->maybeRun();
    }

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

    /** @return array<string,int> alert key => tasks created */
    public function runForCurrentClub(): array {
        try {
            return ( new AlertEscalator() )->runForCurrentClub();
        } catch ( \Throwable $e ) {
            error_log( '[TalentTrack alerts] escalation run failed: ' . $e->getMessage() );
            return [];
        }
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
