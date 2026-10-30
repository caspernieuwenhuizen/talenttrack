<?php
namespace TT\Modules\Journey\Workflow;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Config\ConfigService;
use TT\Infrastructure\Journey\InjuryRepository;
use TT\Infrastructure\Journey\InjuryStatus;
use TT\Infrastructure\Query\QueryHelpers;
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
 * Two ways in, both through the workflow engine so the template's enabled
 * switch and the e-mail apply: every create and edit of an injury (`sync`),
 * and a daily sweep for the records whose date arrives with no edit at all.
 * Dispatch is idempotent, so the two never double up.
 */
final class InjuryRecoveryTasks {

    /** `tt_config` key: the day the sweep last ran, per club. */
    public const LAST_SWEEP_KEY = 'injury_recovery_last_sweep';

    public static function init(): void {
        add_action( 'tt_journey_injury_logged', [ self::class, 'onInjuryChanged' ], 20 );
        add_action( 'tt_journey_injury_updated', [ self::class, 'onInjuryChanged' ], 20 );
        add_action( CronDispatcher::TICK_HOOK, [ self::class, 'onTick' ], 24 );
    }

    /** @param mixed $payload The repository's event payload. */
    public static function onInjuryChanged( $payload ): void {
        $player_id = is_array( $payload ) ? (int) ( $payload['player_id'] ?? 0 ) : 0;
        if ( $player_id > 0 ) self::syncPlayer( $player_id );
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
     */
    public static function dueDateForPlayer( int $player_id, ?string $today = null ): ?string {
        $today = $today ?? AttendanceDateRule::today();
        $due   = null;
        foreach ( ( new InjuryRepository() )->listForPlayer( $player_id ) as $row ) {
            $date = InjuryStatus::checkDate( $row );
            if ( $date === null || $date > $today ) continue;
            if ( $due === null || $date < $due ) $due = $date;
        }
        return $due;
    }

    /** The player's team, for the assignees. */
    public static function teamOf( int $player_id ): int {
        $player = QueryHelpers::get_player( $player_id );
        return $player ? (int) ( $player->team_id ?? 0 ) : 0;
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
