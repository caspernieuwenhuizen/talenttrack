<?php
namespace TT\Modules\Journey\Workflow;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Workflow\Contracts\AssigneeResolver;
use TT\Modules\Workflow\TaskContext;
use TT\Modules\Workflow\TaskTemplate;

/**
 * InjuryRecoveryDueTemplate (#0053) — one reminder per player whose injury
 * record asks for a decision: the expected return has arrived, or the record
 * has gone too long without one (#4246, #4269).
 *
 * Due on the record's check date (`InjuryStatus::checkDate()`), addressed to
 * the team's physio and head coach, idempotent per player and date.
 * `InjuryRecoveryTasks` fires it from every injury write and a daily sweep;
 * the `tt_journey_injury_logged` trigger row still fires it too, harmlessly.
 * The title and e-mail name no injury detail.
 */
class InjuryRecoveryDueTemplate extends TaskTemplate {

    public const KEY = 'injury_recovery_due';

    public function key(): string { return self::KEY; }

    public function name(): string {
        return __( 'Injury recovery check', 'talenttrack' );
    }

    public function description(): string {
        return __( 'A player on your team has an injury record that needs a decision. Confirm they are on track or update the expected return date.', 'talenttrack' );
    }

    public function defaultSchedule(): array {
        return [ 'type' => 'manual' ];
    }

    /** Only used if a record ever reaches the engine without a check date. */
    public function defaultDeadlineOffset(): string {
        return '+14 days';
    }

    public function deadlineFor( TaskContext $context ): ?string {
        $date = (string) ( $context->extras['check_date'] ?? '' );
        return $date !== '' ? $date : null;
    }

    public function idempotentDispatch(): bool {
        return true;
    }

    public function defaultAssignee(): AssigneeResolver {
        return new InjuryRecoveryAssigneeResolver();
    }

    public function formClass(): string {
        return InjuryRecoveryConfirmForm::class;
    }

    public function entityLinks(): array {
        return [ 'player_id', 'team_id' ];
    }

    /**
     * Nothing to dispatch until one of the player's records asks for a
     * decision. The check date and the player's current team travel on the
     * context, whichever path fired the template.
     */
    public function expandTrigger( TaskContext $context ): array {
        $player_id = (int) ( $context->player_id ?? 0 );
        if ( $player_id <= 0 ) return [];

        $due = InjuryRecoveryTasks::dueDateForPlayer( $player_id );
        if ( $due === null ) return [];

        $team_id = (int) ( $context->team_id ?? 0 );
        if ( $team_id <= 0 ) $team_id = InjuryRecoveryTasks::teamOf( $player_id );
        if ( $team_id <= 0 ) return [];

        return [ $context->with( [
            'team_id' => $team_id,
            'extras'  => [ 'check_date' => $due ] + $context->extras,
        ] ) ];
    }
}
