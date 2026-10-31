<?php
namespace TT\Modules\Journey\Workflow;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Archive\ArchiveRepository;
use TT\Infrastructure\Config\ConfigService;
use TT\Infrastructure\Journey\InjuryRepository;
use TT\Infrastructure\Journey\InjuryStatus;
use TT\Modules\Activities\Services\AttendanceDateRule;
use TT\Modules\Workflow\Dispatchers\CronDispatcher;
use TT\Modules\Workflow\TaskContext;
use TT\Modules\Workflow\TaskSupersede;
use TT\Modules\Workflow\WorkflowModule;

/**
 * InjuryRecoveryTasks (#4246, #4269) — keeps the one `injury_recovery_due`
 * reminder per player in step with their injury records.
 *
 * A record asks for a decision on its check date (`InjuryStatus::checkDate()`):
 * the expected return, or the day it becomes incomplete when it has none. On
 * that day the team's physio and head coach get a task, due that day. When
 * the date moves, the old task is skipped; when the record is closed, the
 * open task is skipped. A player with several open records gets one task, for
 * the earliest date that has arrived.
 *
 * Three ways in, all through the workflow engine so the template's enabled
 * switch and the e-mail apply: every create and edit of an injury (`sync`),
 * every archive, restore, trash or delete from the archive layer
 * (`tt_archive_rows_changed`), and a daily sweep for the records whose date
 * arrives with no edit at all.
 * Dispatch is idempotent, so the two never double up.
 */
final class InjuryRecoveryTasks {

    /** `tt_config` key: the day the sweep last ran, per club. */
    public const LAST_SWEEP_KEY = 'injury_recovery_last_sweep';

    /** #4289 — why a date cannot be a record's new expected return. */
    public const NEW_RETURN_INVALID         = 'invalid';
    public const NEW_RETURN_NOT_AFTER_TODAY = 'not_after_today';
    public const NEW_RETURN_BEFORE_START    = 'before_start';
    public const NEW_RETURN_BEFORE_CURRENT  = 'before_current';

    public static function init(): void {
        add_action( 'tt_journey_injury_logged', [ self::class, 'onInjuryChanged' ], 20 );
        add_action( 'tt_journey_injury_updated', [ self::class, 'onInjuryChanged' ], 20 );
        add_action( ArchiveRepository::ROWS_CHANGED_ACTION, [ self::class, 'onArchiveRowsChanged' ], 20, 3 );
        add_action( CronDispatcher::TICK_HOOK, [ self::class, 'onTick' ], 24 );
    }

    /** @param mixed $payload The repository's event payload. */
    public static function onInjuryChanged( $payload ): void {
        $player_id = is_array( $payload ) ? (int) ( $payload['player_id'] ?? 0 ) : 0;
        if ( $player_id > 0 ) self::syncPlayer( $player_id );
    }

    /**
     * #4399 — the recycle bin and the archive write injury rows without
     * `InjuryRepository`, so they announce the change instead: a deleted,
     * trashed or restored record moves its player's reminder in the same
     * request rather than at the next sweep, or never.
     *
     * #4436 — for entity `player` the ids are the players themselves:
     * archiving or trashing one skips their reminder, restoring one with a
     * record still due brings it back.
     *
     * @param mixed $entity
     * @param mixed $ids
     * @param mixed $player_ids
     */
    public static function onArchiveRowsChanged( $entity, $ids = [], $player_ids = [] ): void {
        if ( $entity === 'player' ) $player_ids = $ids;
        if ( ( $entity !== 'injury' && $entity !== 'player' ) || ! is_array( $player_ids ) ) return;
        foreach ( $player_ids as $player_id ) {
            self::syncPlayer( (int) $player_id );
        }
    }

    /**
     * Bring the player's reminder in line with their records: skip a task
     * whose date no longer holds, and create the one that is due now.
     */
    public static function syncPlayer( int $player_id ): void {
        if ( $player_id <= 0 ) return;

        $due = self::dueDateForPlayer( $player_id );
        ( new TaskSupersede() )->forPlayer( InjuryRecoveryDueTemplate::KEY, $player_id, $due );
        if ( $due === null ) return;

        WorkflowModule::engine()->dispatch( InjuryRecoveryDueTemplate::KEY, new TaskContext( $player_id ) );
    }

    /**
     * The earliest check date among the player's records that has arrived by
     * `$today`, Y-m-d; null when no record asks for a decision yet.
     *
     * #4436 — also null for an archived player (or one no longer there): a
     * player who has left the academy gets no reminders. Checked with the
     * sweep's own rule (`InjuryRepository::hasCheckDue()`), club-scoped and
     * independent of demo mode.
     */
    public static function dueDateForPlayer( int $player_id, ?string $today = null ): ?string {
        $today = $today ?? AttendanceDateRule::today();
        if ( ! ( new InjuryRepository() )->hasCheckDue( $player_id, $today ) ) return null;

        $due = null;
        foreach ( self::dueRecordsForPlayer( $player_id, $today ) as $row ) {
            $date = (string) InjuryStatus::checkDate( $row );
            if ( $due === null || $date < $due ) $due = $date;
        }
        return $due;
    }

    /**
     * #4289 — the player's records whose check date has arrived by `$today`:
     * the ones the reminder is asking about, earliest check first.
     *
     * @return list<object>
     */
    public static function dueRecordsForPlayer( int $player_id, ?string $today = null ): array {
        $today = $today ?? AttendanceDateRule::today();
        $due   = [];
        foreach ( ( new InjuryRepository() )->listForPlayer( $player_id ) as $row ) {
            $date = InjuryStatus::checkDate( $row );
            if ( $date === null || $date > $today ) continue;
            $due[] = $row;
        }
        usort( $due, static fn ( object $a, object $b ): int =>
            [ (string) InjuryStatus::checkDate( $a ), (int) ( $a->id ?? 0 ) ] <=> [ (string) InjuryStatus::checkDate( $b ), (int) ( $b->id ?? 0 ) ]
        );
        return $due;
    }

    /**
     * #4289 — the record an "extend" answer may re-date: one of this
     * player's, in this club, not archived or trashed, and not closed.
     * Null otherwise.
     */
    public static function extendableRecord( int $injury_id, int $player_id ): ?object {
        if ( $injury_id <= 0 || $player_id <= 0 ) return null;
        $row = ( new InjuryRepository() )->find( $injury_id );
        if ( ! $row || (int) ( $row->player_id ?? 0 ) !== $player_id ) return null;
        if ( ! empty( $row->archived_at ) || ! empty( $row->trashed_at ) ) return null;
        if ( ! empty( $row->actual_return ) ) return null;
        return $row;
    }

    /**
     * #4289 — null when `$date` (Y-m-d) may become the record's expected
     * return; otherwise one of the `NEW_RETURN_*` reasons. It must be a real
     * date, later than today, and not before the injury started or before
     * the return the record already expects.
     */
    public static function newReturnProblem( object $row, string $date, ?string $today = null ): ?string {
        $parsed = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date );
        if ( $parsed === false || $parsed->format( 'Y-m-d' ) !== $date ) return self::NEW_RETURN_INVALID;

        $today = $today ?? AttendanceDateRule::today();
        if ( $date <= $today ) return self::NEW_RETURN_NOT_AFTER_TODAY;
        if ( InjuryRepository::expectedReturnBeforeStart( (string) ( $row->started_on ?? '' ), $date ) ) {
            return self::NEW_RETURN_BEFORE_START;
        }
        $current = substr( (string) ( $row->expected_return ?? '' ), 0, 10 );
        if ( $current !== '' && $date < $current ) return self::NEW_RETURN_BEFORE_CURRENT;
        return null;
    }

    /**
     * The player's team, for the assignees. #4446 — read without the demo
     * filter: a reminder falling due for a real player during demo mode
     * still reaches that player's team staff.
     */
    public static function teamOf( int $player_id ): int {
        return ( new InjuryRepository() )->teamIdOf( $player_id );
    }

    public static function onTick(): void {
        self::sweep();
    }

    /**
     * Once per day per club: every player with a record whose check date has
     * arrived. Returns how many players were synced in the current club.
     */
    public static function sweep(): int {
        $config = new ConfigService();
        $today  = AttendanceDateRule::today();
        if ( $config->get( self::LAST_SWEEP_KEY, '' ) === $today ) return 0;
        $config->set( self::LAST_SWEEP_KEY, $today );

        $players = ( new InjuryRepository() )->playersWithCheckDue( $today );
        foreach ( $players as $player_id ) {
            self::syncPlayer( $player_id );
        }
        return count( $players );
    }
}
