<?php
namespace TT\Modules\Alerts\Cron;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Config\ConfigService;
use TT\Modules\Alerts\AlertEvaluator;
use TT\Modules\Alerts\Domain\AlertContext;
use TT\Modules\Workflow\Dispatchers\CronDispatcher;

/**
 * AlertSweepCron (#2631, epic #2629) — the hourly reconcile.
 *
 * Scheduling per CLAUDE.md §4: background work rides the existing engine
 * heartbeat, not its own `wp_schedule_event`. Worth being precise about
 * what that means here, because the epic originally said something wrong:
 * `Workflow\CronDispatcher` is a *task-template* scheduler — it walks
 * `tt_workflow_triggers` rows and dispatches templates. There is no generic
 * "register a job" API to call. What it does provide is `TICK_HOOK`, an
 * hourly heartbeat, and subscribing to that is the one chokepoint a future
 * SaaS scheduler replaces. `Infrastructure\Archive\AutoPurgeCron` does
 * exactly this; so does this class.
 *
 * Epic decision 2: the dashboard never evaluates. Surfaces read persisted
 * occurrences only, and this sweep is what keeps them true. The cost is up
 * to an hour of staleness after a coach fixes something, accepted for wave
 * 1 in exchange for a login that does no work. Event-driven invalidation
 * (decision 6) is deferred; `AlertContext` already carries the seam.
 *
 * Per-club iteration, unauthenticated: the tick fires with no logged-in
 * user, so `CurrentClub::id()` cannot be trusted to resolve. Every club is
 * enumerated and pinned explicitly for its iteration, exactly as the purge
 * cron does, so occurrences are written strictly within their own tenant.
 */
final class AlertSweepCron {

    /** tt_config key holding the last sweep's UTC timestamp, per club. */
    public const LAST_RUN_CONFIG_KEY = 'tt_alerts_last_sweep_at';

    /** tt_config key holding the last sweep's per-definition stats, per club. */
    public const LAST_STATS_CONFIG_KEY = 'tt_alerts_last_sweep_stats';

    /** @var ConfigService */
    private $config;

    public function __construct( ?ConfigService $config = null ) {
        $this->config = $config ?? new ConfigService();
    }

    /**
     * Subscribe to the engine heartbeat. Priority 25 puts the sweep after
     * the recycle-bin purge (20) — a purged record should not produce an
     * occurrence that resolves itself an hour later.
     */
    public static function init(): void {
        add_action( CronDispatcher::TICK_HOOK, [ self::class, 'onTick' ], 25 );
    }

    public static function onTick(): void {
        ( new self() )->maybeRun();
    }

    /**
     * Run the sweep at most once an hour per club. The heartbeat is already
     * hourly, so this guard is really a defence against a manual tick or a
     * second registration doubling the work.
     */
    public function maybeRun(): void {
        $now = time();
        foreach ( $this->clubIds() as $club_id ) {
            $this->withClub( $club_id, function () use ( $now ) {
                $last = (int) $this->config->get( self::LAST_RUN_CONFIG_KEY, '0' );
                if ( $last > 0 && ( $now - $last ) < ( HOUR_IN_SECONDS - MINUTE_IN_SECONDS ) ) {
                    return;
                }
                $this->runForCurrentClub();
                $this->config->set( self::LAST_RUN_CONFIG_KEY, (string) $now );
            } );
        }
    }

    /**
     * Sweep every club regardless of the hourly stamp. Used on activation
     * (so a fresh install has a truthful banner before the first tick), by
     * the diagnostic REST route, and by tests.
     *
     * @return array<int,array<string,mixed>> club_id => per-definition stats
     */
    public function runAllClubs(): array {
        $out = [];
        foreach ( $this->clubIds() as $club_id ) {
            $out[ $club_id ] = $this->withClub( $club_id, function () {
                return $this->runForCurrentClub();
            } );
        }
        return $out;
    }

    /**
     * Sweep the pinned club. Stats are stored so the timing diagnostic in
     * #2634 has something to read without re-running anything.
     *
     * @return array<string,mixed>
     */
    public function runForCurrentClub(): array {
        $stats = ( new AlertEvaluator() )->runAll( new AlertContext() );
        $this->config->set(
            self::LAST_STATS_CONFIG_KEY,
            (string) wp_json_encode( $stats, JSON_UNESCAPED_SLASHES )
        );
        return $stats;
    }

    /**
     * Every club that has config rows, club 1 always included. Mirrors the
     * enumeration `AutoPurgeCron` uses, so both sweeps cover the same set.
     *
     * @return int[]
     */
    private function clubIds(): array {
        global $wpdb;
        $ids = $wpdb->get_col( "SELECT DISTINCT club_id FROM {$wpdb->prefix}tt_config" );
        $ids = array_values( array_unique( array_map( 'intval', is_array( $ids ) ? $ids : [] ) ) );
        if ( ! in_array( 1, $ids, true ) ) {
            $ids[] = 1;
        }
        return array_filter( $ids, static function ( $id ) { return $id > 0; } );
    }

    /**
     * Run `$fn` with `tt_current_club_id` pinned, then restore. A fresh
     * ConfigService is taken for the iteration so its per-club read cache
     * cannot hand back another tenant's value.
     *
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
