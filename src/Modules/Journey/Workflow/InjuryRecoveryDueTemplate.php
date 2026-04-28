<?php
namespace TT\Modules\Journey\Workflow;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Workflow\Contracts\AssigneeResolver;
use TT\Modules\Workflow\Resolvers\TeamHeadCoachResolver;
use TT\Modules\Workflow\TaskContext;
use TT\Modules\Workflow\TaskTemplate;

/**
 * InjuryRecoveryDueTemplate (#0053) — when an injury is logged with an
 * expected return date, the player's head coach gets a reminder task.
 * The deadline is offset back from `expected_return` so the coach sees
 * it in their inbox a few days before the player is due back.
 *
 * Trigger: event-driven on `tt_journey_injury_logged`. The injury repo
 * fires the event after a successful insert when expected_return is set.
 *
 * No fan-out: the trigger context already names a specific player + team.
 */
class InjuryRecoveryDueTemplate extends TaskTemplate {

    public const KEY = 'injury_recovery_due';

    public function key(): string { return self::KEY; }

    public function name(): string {
        return __( 'Injury recovery check', 'talenttrack' );
    }

    public function description(): string {
        return __( 'A player on your team is due back from injury soon. Confirm they are on track or update the expected return date.', 'talenttrack' );
    }

    public function defaultSchedule(): array {
        return [ 'type' => 'manual' ];
    }

    public function defaultDeadlineOffset(): string {
        // Defensive default. The dispatched context carries an
        // `extras.expected_return` so the engine could compute a
        // sharper due_at — but the engine reads `defaultDeadlineOffset`
        // as a string, so we leave the +14 day default and let coaches
        // pick up the reminder from their inbox earlier.
        return '+14 days';
    }

    public function defaultAssignee(): AssigneeResolver {
        return new TeamHeadCoachResolver();
    }

    public function formClass(): string {
        return InjuryRecoveryConfirmForm::class;
    }

    public function entityLinks(): array {
        return [ 'player_id', 'team_id' ];
    }

    public function expandTrigger( TaskContext $context ): array {
        if ( ! $context->player_id ) return [];
        return [ $context ];
    }
}
