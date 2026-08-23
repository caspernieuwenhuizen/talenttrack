<?php
namespace TT\Modules\Alerts\Invalidation;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Alerts\AlertEvaluator;
use TT\Modules\Alerts\Domain\AlertContext;
use TT\Modules\Alerts\Repositories\AlertOccurrencesRepository;

/**
 * AlertInvalidationBuffer (#2731, epic #2629) — collect during the request,
 * reconcile after the response.
 *
 * Wave 1 accepted up to an hour of staleness after a coach fixed something,
 * because the hourly sweep was the only thing that reconciled. In use that
 * reads as the product being wrong about the data rather than as staleness:
 * the coach marks the session complete, the banner still says it is not,
 * and the one promise alerts make over a task — that they resolve
 * themselves — is the promise that appears broken.
 *
 * ## Why `shutdown` rather than inline
 *
 * Re-evaluating inline would put a definition's query on the critical path
 * of every save, which is the login/save-latency risk the epic lists and
 * the reason surfaces read persisted rows in the first place. Collecting
 * ids during the request and reconciling on `shutdown` costs the save
 * nothing, and the redirect or next render — which is where the user looks
 * — already reads the resolved rows.
 *
 * It also collapses volume. An attendance grid save touching forty players
 * on one activity buffers one activity id, not forty events' worth of
 * work, and a bulk operation that blows past the ceiling degrades to
 * "the hourly sweep will get it" instead of stalling the request.
 *
 * ## What it deliberately does not do
 *
 * It does not make surfaces evaluate (epic decision 2 stands — they still
 * read persisted rows), it does not replace the sweep, and it does not
 * shorten it. The sweep remains the backstop for everything time-driven: a
 * certificate does not fire an event when it expires, and a session does
 * not fire one when it becomes overdue.
 */
final class AlertInvalidationBuffer {

    /**
     * Ceiling on subjects buffered per type, per club, per request.
     *
     * A bulk import or a season rollover can touch hundreds of records in
     * one request. Reconciling all of them after the response would turn a
     * one-off admin action into the most expensive request on the site, so
     * past this point the type is abandoned for the request and left to the
     * hourly sweep. Degrading is the point: an alert an hour stale is the
     * behaviour we already shipped, a request that never returns is not.
     */
    private const MAX_SUBJECTS_PER_TYPE = 50;

    /**
     * Buffered work: club id => subject type => list of subject ids.
     *
     * @var array<int,array<string,list<int>>>
     */
    private static $pending = [];

    /**
     * Types abandoned this request because they passed the ceiling.
     *
     * @var array<int,array<string,bool>>
     */
    private static $overflowed = [];

    /** @var bool */
    private static $booted = false;

    /**
     * Guards against a reconcile that somehow re-fires a mapped hook
     * buffering more work into the run that is already draining.
     *
     * @var bool
     */
    private static $draining = false;

    /**
     * Arrange to subscribe once every module has booted.
     *
     * The deferral to late `init` is not incidental. Modules boot at `init`
     * priority 1 and a module may add its own entries to
     * `tt_alert_invalidation_map` while doing so; resolving the map inside
     * the Alerts module's own `boot()` would freeze it at whatever had
     * registered by then, which depends on module order. `AlertRegistry`
     * dodges the same problem by resolving on first access — this class
     * cannot, because it has to have subscribed before anything fires.
     *
     * Cron is excluded: `AlertSweepCron` already owns that request and runs
     * the full catalogue, so buffering there would be a narrowed run
     * chasing a full one that is about to make it redundant.
     */
    public static function init(): void {
        if ( self::$booted ) return;
        if ( wp_installing() ) return;
        if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) return;

        self::$booted = true;
        add_action( 'init', [ self::class, 'subscribe' ], 99 );
    }

    /**
     * Bind every mapped hook to the buffer, and the buffer to `shutdown`.
     */
    public static function subscribe(): void {
        foreach ( array_keys( AlertInvalidationMap::all() ) as $hook ) {
            add_action( $hook, [ self::class, 'capture' ], 10, 10 );
        }

        // Late, so anything else hooked to shutdown that might itself write
        // (an audit logger, a deferred save) has already run and its own
        // events are in the buffer.
        add_action( 'shutdown', [ self::class, 'drain' ], 100 );
    }

    /**
     * Record what a domain event touched. Runs inside the save request, so
     * it does no more than name ids.
     *
     * @param mixed ...$args The hook's own arguments.
     */
    public static function capture( ...$args ): void {
        if ( self::$draining ) return;

        $hook = current_action();
        $map  = AlertInvalidationMap::all();
        if ( ! isset( $map[ $hook ] ) ) return;

        try {
            $pairs = $map[ $hook ]( ...$args );
        } catch ( \Throwable $e ) {
            // A bad extractor must never be able to break the save it was
            // listening to. Losing the invalidation costs an hour of
            // staleness; letting this bubble costs the user their write.
            error_log( sprintf(
                '[TalentTrack alerts] invalidation extractor for "%s" failed: %s',
                $hook,
                $e->getMessage()
            ) );
            return;
        }

        if ( ! is_array( $pairs ) ) return;

        // One pair or a list of them — a hook that changes two kinds of
        // record at once (an evaluation and the player it is about) is
        // normal, so both shapes are accepted.
        if ( isset( $pairs[0] ) && is_string( $pairs[0] ) ) {
            $pairs = [ $pairs ];
        }

        $club = CurrentClub::id();

        foreach ( $pairs as $pair ) {
            if ( ! is_array( $pair ) || count( $pair ) < 2 ) continue;

            $type = (string) $pair[0];
            $ids  = is_array( $pair[1] ) ? $pair[1] : [ $pair[1] ];
            if ( $type === '' ) continue;

            if ( ! empty( self::$overflowed[ $club ][ $type ] ) ) continue;

            foreach ( $ids as $id ) {
                $id = (int) $id;
                if ( $id <= 0 ) continue;

                $bucket = self::$pending[ $club ][ $type ] ?? [];
                if ( in_array( $id, $bucket, true ) ) continue;

                if ( count( $bucket ) >= self::MAX_SUBJECTS_PER_TYPE ) {
                    self::$overflowed[ $club ][ $type ] = true;
                    unset( self::$pending[ $club ][ $type ] );
                    break;
                }

                $bucket[]                          = $id;
                self::$pending[ $club ][ $type ]   = $bucket;
            }
        }
    }

    /**
     * Reconcile everything buffered, then forget it.
     *
     * Runs after the response. Everything in here is wrapped: a failure
     * during shutdown has no user-visible recovery path, and the honest
     * outcome of one is a log line plus an alert that stays stale until the
     * next sweep — never a fatal on a request whose work already succeeded.
     */
    public static function drain(): void {
        if ( self::$draining ) return;
        if ( empty( self::$pending ) ) {
            self::$overflowed = [];
            return;
        }

        $pending          = self::$pending;
        self::$pending    = [];
        self::$draining   = true;

        try {
            if ( ! ( new AlertOccurrencesRepository() )->tableExists() ) return;

            foreach ( $pending as $club_id => $byType ) {
                self::withClub( (int) $club_id, static function () use ( $club_id, $byType ): void {
                    // Built inside the pin, once per club. The evaluator's
                    // policy resolver caches config per club on construction,
                    // so an instance made before pinning would answer
                    // "is this alert switched off?" for the wrong tenant —
                    // the same trap `AlertSweepCron::withClub()` takes a
                    // fresh `ConfigService` to avoid.
                    $evaluator = new AlertEvaluator();

                    foreach ( $byType as $type => $ids ) {
                        if ( empty( $ids ) ) continue;
                        $evaluator->runForSubject(
                            new AlertContext( (int) $club_id, (string) $type, $ids )
                        );
                    }
                } );
            }
        } catch ( \Throwable $e ) {
            error_log( '[TalentTrack alerts] invalidation drain failed: ' . $e->getMessage() );
        } finally {
            self::$draining   = false;
            self::$overflowed = [];
        }
    }

    /**
     * Whether anything is waiting to be reconciled. Tests read this; so
     * does anyone debugging why a fix did not clear an alert.
     *
     * @return array<int,array<string,list<int>>>
     */
    public static function peek(): array {
        return self::$pending;
    }

    /** Drop buffered work without reconciling it. Tests use this. */
    public static function reset(): void {
        self::$pending    = [];
        self::$overflowed = [];
        self::$draining   = false;
    }

    /**
     * Run `$fn` with `tt_current_club_id` pinned. Mirrors
     * `AlertSweepCron::withClub()` — the drain usually runs with the
     * saving user's club already current, but pinning explicitly is what
     * stops the two reconcile paths from drifting apart.
     */
    private static function withClub( int $club_id, callable $fn ): void {
        $filter = static function () use ( $club_id ) { return $club_id; };
        add_filter( 'tt_current_club_id', $filter, 9999 );
        try {
            $fn();
        } finally {
            remove_filter( 'tt_current_club_id', $filter, 9999 );
        }
    }
}
