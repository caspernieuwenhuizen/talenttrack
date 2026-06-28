<?php
namespace TT\Infrastructure\Archive;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Config\ConfigService;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\RecycleBin\RecycleBinEntities;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Workflow\Dispatchers\CronDispatcher;

/**
 * AutoPurgeCron (#2025, epic #2018) — the 30-day recycle-bin auto-purge.
 *
 * Security-critical: this is the only unattended code path that erases a
 * minor's PII. Every safeguard the manual bin enforces is enforced here too.
 *
 * Scheduling (CLAUDE.md §4 — background work runs on the existing workflow
 * engine, NOT ad-hoc wp_cron). The job does NOT register its own
 * `wp_schedule_event`; it subscribes to the workflow engine's existing
 * heartbeat (`tt_workflow_cron_tick`, the one chokepoint a future SaaS
 * scheduler will replace) and self-throttles to once per calendar day via a
 * per-install last-run stamp. Fifty ad-hoc cron registrations are not
 * replaceable; one engine heartbeat is.
 *
 * Per-club + system actor (#2025 security #8). The tick fires with no logged
 * in user, so the job must not assume `CurrentClub::id()` resolves to a real
 * tenant. It enumerates every club that has config rows (the same
 * `SELECT DISTINCT club_id` the retention seed in migration 0186 uses, club 1
 * always included) and runs the sweep once per club with `tt_current_club_id`
 * pinned to that club for the iteration — so trashed rows are selected and
 * purged strictly within their own tenant. Audit rows are written with a
 * system actor (`user_id = 0`) so the audit viewer never implies a human
 * pressed "Delete now".
 *
 * Cascade eraser, never a raw DELETE (#2025 security #5). Every purge routes
 * through `ArchiveRepository::purge()` → `deletePermanently()` →
 * PlayerDeletionCascade / PersonDeletionCascade / GenericCascadeDeleter. A
 * player/person purge therefore erases the minor's child PII across every
 * keyed table rather than stranding it.
 *
 * Audit (#2025 security #4). Each successful purge writes `{entity}.purged`
 * to tt_audit_log via ArchiveRepository::purge().
 *
 * Fail-closed on blocked rows. A `DeleteBlockedException` (an undeclared
 * reference the cascade plan does not own) is caught per record: the row is
 * left in the bin, counted, and the per-club blocked count is persisted to
 * tt_config so the bin view (#2024) can flag "N records couldn't be
 * auto-deleted — still referenced". Blocked rows are NEVER force-deleted.
 *
 * Some entities can never auto-purge by design: the `block_only` entities in
 * CascadeRegistry (`trial_track`, `measurement_definition` after #2027
 * completed team + activity cascades) block on any dependent. Their trashed
 * instances persist in the bin indefinitely, flagged, never silently lost.
 * Documented in docs/recycle-bin.md.
 *
 * Idempotent + transactional per record (the cascade services wrap each in
 * START TRANSACTION). A purge interrupted mid-sweep resumes cleanly next tick.
 */
final class AutoPurgeCron {

    /**
     * tt_config key holding the YYYY-MM-DD of the last day the sweep ran
     * (per club). The throttle that keeps the engine heartbeat — which ticks
     * hourly — from purging more than once a day.
     */
    public const LAST_RUN_CONFIG_KEY = 'tt_recycle_bin_last_purge_date';

    /**
     * tt_config key holding the count of records skipped on the most recent
     * sweep because the cascade blocked them (per club). The bin view reads
     * this to surface the "N couldn't be auto-deleted" notice.
     */
    public const BLOCKED_COUNT_CONFIG_KEY = 'tt_recycle_bin_blocked_count';

    /** @var ArchiveRepository */
    private $repo;

    /** @var ConfigService */
    private $config;

    public function __construct( ?ArchiveRepository $repo = null, ?ConfigService $config = null ) {
        $this->repo   = $repo ?? new ArchiveRepository();
        $this->config = $config ?? new ConfigService();
    }

    /**
     * Subscribe to the workflow engine heartbeat. No own schedule — the
     * engine's `ensureScheduled()` already keeps `tt_workflow_cron_tick`
     * registered (CronDispatcher), so the purge piggybacks on that one
     * chokepoint.
     */
    public static function init(): void {
        add_action( CronDispatcher::TICK_HOOK, [ self::class, 'onTick' ], 20 );
    }

    /**
     * Heartbeat handler. Self-throttles to once per calendar day, then runs
     * the per-club sweep. Cheap on the 23 ticks a day where it's already run.
     */
    public static function onTick(): void {
        ( new self() )->maybeRun();
    }

    /**
     * Run the sweep at most once per calendar day across all clubs. The
     * once-a-day guard is evaluated per club (a club whose stamp is today is
     * skipped) so a club added mid-day still gets its first sweep.
     */
    public function maybeRun(): void {
        $today = current_time( 'Y-m-d' );
        foreach ( $this->clubIds() as $club_id ) {
            $this->withClub( $club_id, function () use ( $today ) {
                if ( $this->config->get( self::LAST_RUN_CONFIG_KEY, '' ) === $today ) {
                    return; // already swept this club today
                }
                $this->runForCurrentClub();
                $this->config->set( self::LAST_RUN_CONFIG_KEY, $today );
            } );
        }
    }

    /**
     * Force a sweep across all clubs regardless of the daily stamp. Used by
     * tests and by a future "run now" admin affordance; the daily throttle
     * lives in maybeRun(), not here.
     *
     * @return array<int,array{purged:int,blocked:int}>  club_id => totals
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
     * Sweep one club (the current `CurrentClub::id()`): for every archivable
     * entity, purge rows trashed before the club's retention cutoff, skipping
     * any the cascade blocks. Records the blocked count for the bin view.
     *
     * @return array{purged:int,blocked:int}
     */
    public function runForCurrentClub(): array {
        $retention = $this->repo->retentionDays();
        $purged    = 0;
        $blocked   = 0;

        foreach ( RecycleBinEntities::keys() as $entity ) {
            foreach ( $this->expiredIds( $entity, $retention ) as $id ) {
                try {
                    // Cascade eraser, NOT a raw DELETE. by_user_id = 0 is the
                    // system actor so the audit row reads as an automated purge.
                    $purged += $this->repo->purge( $entity, [ $id ], 0 );
                } catch ( DeleteBlockedException $e ) {
                    // Undeclared reference — leave the row in the bin, count it,
                    // never force-delete. Surfaced in the bin view.
                    $blocked++;
                }
            }
        }

        $this->config->set( self::BLOCKED_COUNT_CONFIG_KEY, (string) $blocked );

        return [ 'purged' => $purged, 'blocked' => $blocked ];
    }

    /**
     * Ids of one entity's rows that are in THIS club's bin and whose
     * `trashed_at` is older than the retention cutoff. Club-scoped via the
     * shared QueryHelpers fragment so the tenant clause can't be dropped, and
     * `trashed_at` is qualified with the table alias (ArchiveRepository
     * §filterClause warns bare `trashed_at` is ambiguous across the 20
     * archivable tables — here there's a single FROM table, but the alias
     * keeps it unambiguous and consistent).
     *
     * @return int[]
     */
    private function expiredIds( string $entity, int $retention_days ): array {
        $table = $this->resolveTable( $entity );
        if ( $table === null ) return [];

        $cutoff = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp', true ) - $retention_days * DAY_IN_SECONDS );

        global $wpdb;
        $sql = "SELECT t.id FROM {$table} t
                WHERE t.trashed_at IS NOT NULL
                  AND t.trashed_at < %s
                  AND " . QueryHelpers::clubScopeWhere( 't' ) . "
                ORDER BY t.trashed_at ASC, t.id ASC";
        $rows = $wpdb->get_col( $wpdb->prepare( $sql, $cutoff ) );
        return array_map( 'intval', is_array( $rows ) ? $rows : [] );
    }

    /**
     * Every club that has config rows, club 1 always included. Mirrors the
     * club enumeration migration 0186 uses to seed the retention window, so
     * the sweep covers exactly the clubs that have a retention setting.
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
     * Run $fn with `tt_current_club_id` pinned to $club_id, then restore the
     * filter. The pin is what makes ConfigService + ArchiveRepository read and
     * write the right tenant during an unauthenticated tick. A fresh
     * ConfigService is taken for the iteration so its per-club read cache
     * can't return another club's retention value.
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

    private function resolveTable( string $entity ): ?string {
        $map = ArchiveRepository::entityMap();
        if ( ! isset( $map[ $entity ] ) ) return null;
        global $wpdb;
        return $wpdb->prefix . $map[ $entity ];
    }
}
