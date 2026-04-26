<?php
namespace TT\Modules\Workflow\Templates;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Workflow\Contracts\AssigneeResolver;
use TT\Modules\Workflow\Forms\GoalApprovalForm;
use TT\Modules\Workflow\Resolvers\TeamHeadCoachResolver;
use TT\Modules\Workflow\TaskTemplate;

/**
 * GoalApprovalTemplate — only ever spawned by
 * QuarterlyGoalSettingTemplate::onComplete. Never fires from a manual,
 * cron, or event trigger. Carries `parent_task_id` pointing back to
 * the goal-setting task so the approval form can show the player's
 * goals for accept / amend.
 *
 * 7-day deadline so coaches see + act before the next round.
 */
class GoalApprovalTemplate extends TaskTemplate {

    public const KEY = 'goal_approval';

    public function key(): string { return self::KEY; }

    public function name(): string {
        return __( 'Goal approval', 'talenttrack' );
    }

    public function description(): string {
        return __( 'Approve or comment on a player\'s draft quarterly goals. Spawned automatically when the player submits their goals.', 'talenttrack' );
    }

    public function defaultSchedule(): array {
        return [ 'type' => 'manual' ];
    }

    public function defaultDeadlineOffset(): string {
        return '+7 days';
    }

    public function defaultAssignee(): AssigneeResolver {
        return new TeamHeadCoachResolver();
    }

    public function formClass(): string {
        return GoalApprovalForm::class;
    }

    public function entityLinks(): array {
        return [ 'player_id', 'team_id' ];
    }
}
