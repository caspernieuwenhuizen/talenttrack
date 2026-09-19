<?php
namespace TT\Modules\Comms\Queue;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Logging\Logger;
use TT\Modules\Comms\CommsAuditLogger;
use TT\Modules\Comms\CommsService;
use TT\Modules\Comms\Domain\CommsResult;
use TT\Modules\Comms\Domain\Recipient;
use TT\Modules\Comms\QuietHours\QuietHoursPolicy;
use TT\Modules\Workflow\Dispatchers\CronDispatcher;

/**
 * DeferredSendSweep (#3646) — sends what quiet hours held, once they end.
 *
 * Scheduling per CLAUDE.md §4: this rides the workflow engine's hourly
 * heartbeat (`CronDispatcher::TICK_HOOK`) rather than registering a cron
 * event of its own, exactly as `Alerts\Cron\AlertSweepCron` and
 * `Infrastructure\Archive\AutoPurgeCron` do. A held message therefore goes
 * out in the first hour after the window closes, not on the minute.
 *
 * Per club, pinned: the tick fires with nobody logged in, so each club is
 * enumerated and pinned for its iteration, and the template switch, the
 * opt-outs and the quiet-hours window all read that club's config.
 *
 * For each held message, oldest first, at most {@see self::BATCH} per club
 * per tick:
 *   - past `expires_at` → the log row becomes `failed` /
 *     `deferral_expired` and the queue row goes. Yesterday's news is not
 *     sent, and a heartbeat that stopped for a day is made visible.
 *   - still inside the window (a club with a different window, or a tick
 *     that fired early) → left for the next tick.
 *   - otherwise → taken off the queue, then sent through
 *     `CommsService::deliverDeferred()` against the same log row.
 *
 * Taking the row off first is deliberate. The delete is the claim: a second
 * sweep running at the same moment finds nothing to delete and moves on, so
 * a family is written to at most once. A send that throws is still recorded
 * on the log row, so the message cannot sit at "Held until morning" again.
 */
final class DeferredSendSweep {

    /** Held messages sent per club per tick. The rest wait an hour. */
    public const BATCH = 100;

    private DeferredSendQueue $queue;
    private CommsService $service;
    private QuietHoursPolicy $quietHours;
    private CommsAuditLogger $auditLogger;

    public function __construct(
        ?DeferredSendQueue $queue = null,
        ?CommsService $service = null,
        ?QuietHoursPolicy $quietHours = null,
        ?CommsAuditLogger $auditLogger = null
    ) {
        $this->queue       = $queue       ?? new DeferredSendQueue();
        $this->quietHours  = $quietHours  ?? new QuietHoursPolicy();
        $this->auditLogger = $auditLogger ?? new CommsAuditLogger();
        $this->service     = $service     ?? new CommsService( null, $this->quietHours, null, $this->auditLogger, $this->queue );
    }

    /**
     * Priority 22: after the recycle-bin purge (20), before the alert
     * sweep (25). Nothing here depends on either; the number only keeps
     * the heartbeat's subscribers in a stated order.
     */
    public static function init(): void {
        add_action( CronDispatcher::TICK_HOOK, [ self::class, 'onTick' ], 22 );
    }

    public static function onTick(): void {
        ( new self() )->runAllClubs();
    }

    /**
     * @return array<int, array{sent:int, held:int, expired:int, unreadable:int}> club_id => counts
     */
    public function runAllClubs(): array {
        $out = [];
        foreach ( $this->clubIds() as $club_id ) {
            $out[ $club_id ] = $this->withClub( $club_id, function (): array {
                return $this->runForCurrentClub();
            } );
        }
        return $out;
    }

    /**
     * @return array{sent:int, held:int, expired:int, unreadable:int}
     */
    public function runForCurrentClub(): array {
        $counts = [ 'sent' => 0, 'held' => 0, 'expired' => 0, 'unreadable' => 0 ];
        $now    = $this->queue->now();

        foreach ( $this->queue->pending( self::BATCH ) as $row ) {
            $id   = $row['id'];
            $uuid = $row['log_uuid'];

            if ( $row['expires_at'] > 0 && $row['expires_at'] <= $now ) {
                if ( $this->queue->claim( $id ) ) {
                    $this->auditLogger->recordAttempt(
                        $uuid,
                        '',
                        '',
                        new CommsResult( $uuid, CommsResult::STATUS_FAILED, '', Recipient::none(), DeferredSendQueue::ERROR_EXPIRED ),
                        false
                    );
                    Logger::warning( 'Comms: a message held for quiet hours expired unsent. Is the hourly workflow heartbeat running?', [
                        'uuid' => $uuid,
                    ] );
                    $counts['expired']++;
                }
                continue;
            }

            $request = DeferredSendQueue::hydrate( $row['request_json'], $row['recipient_json'] );
            if ( $request === null ) {
                if ( $this->queue->claim( $id ) ) {
                    $this->auditLogger->recordAttempt(
                        $uuid,
                        '',
                        '',
                        new CommsResult( $uuid, CommsResult::STATUS_FAILED, '', Recipient::none(), DeferredSendQueue::ERROR_UNREADABLE ),
                        false
                    );
                    Logger::error( 'Comms: a held message could not be read back from the queue', [ 'uuid' => $uuid ] );
                    $counts['unreadable']++;
                }
                continue;
            }

            if ( $this->quietHours->shouldDefer( $request ) ) {
                $this->queue->touch( $id );
                $counts['held']++;
                continue;
            }

            if ( ! $this->queue->claim( $id ) ) {
                continue;
            }

            $recipient = $request->recipients[0];
            try {
                $this->service->deliverDeferred( $request, $recipient, $uuid );
            } catch ( \Throwable $e ) {
                Logger::error( 'Comms: sending a held message threw', [
                    'uuid'      => $uuid,
                    'exception' => $e->getMessage(),
                ] );
                $this->auditLogger->recordAttempt(
                    $uuid,
                    '',
                    '',
                    new CommsResult( $uuid, CommsResult::STATUS_EXCEPTION, '', $recipient, 'dispatch_exception' )
                );
            }
            $counts['sent']++;
        }

        return $counts;
    }

    /**
     * Every club that has config rows, club 1 always included — the same
     * enumeration `AlertSweepCron` and `AutoPurgeCron` use.
     *
     * @return list<int>
     */
    private function clubIds(): array {
        global $wpdb;
        $ids = $wpdb->get_col( "SELECT DISTINCT club_id FROM {$wpdb->prefix}tt_config" );
        $ids = array_values( array_unique( array_map( 'intval', is_array( $ids ) ? $ids : [] ) ) );
        if ( ! in_array( 1, $ids, true ) ) {
            $ids[] = 1;
        }
        return array_values( array_filter( $ids, static function ( int $id ): bool { return $id > 0; } ) );
    }

    /**
     * Run `$fn` with `tt_current_club_id` pinned, then restore.
     *
     * @template T
     * @param callable():T $fn
     * @return T
     */
    private function withClub( int $club_id, callable $fn ) {
        $filter = static function () use ( $club_id ) { return $club_id; };
        add_filter( 'tt_current_club_id', $filter, 9999 );
        try {
            return $fn();
        } finally {
            remove_filter( 'tt_current_club_id', $filter, 9999 );
        }
    }
}
