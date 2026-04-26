<?php
namespace TT\Modules\Workflow\Templates;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Workflow\Contracts\AssigneeResolver;
use TT\Modules\Workflow\Forms\GoalSettingForm;
use TT\Modules\Workflow\Resolvers\PlayerOrParentResolver;
use TT\Modules\Workflow\TaskContext;
use TT\Modules\Workflow\TaskTemplate;
use TT\Modules\Workflow\WorkflowModule;

/**
 * QuarterlyGoalSettingTemplate — fans out one task per active rostered
 * player at the start of each quarter (cron at 00:00 on the 1st of
 * every 3rd month). The player (or parent — minors policy) drafts up
 * to three goals for the next quarter; on completion, the template
 * spawns a follow-up GoalApproval task on the team's head coach.
 *
 * The "spawn on complete" mechanic is the tactical Phase 1 hack flagged
 * in the spec: rather than inventing a `spawns_on_complete` first-class
 * primitive, this template hand-rolls the chain via onComplete(). Phase 2
 * generalises it.
 *
 * Default deadline: 14 days from creation. Coaches can disable the
 * template entirely from the Sprint 5 config UI (drops to 0 quarterly
 * fan-out).
 */
class QuarterlyGoalSettingTemplate extends TaskTemplate {

    public const KEY = 'quarterly_goal_setting';

    public function key(): string { return self::KEY; }

    public function name(): string {
        return __( 'Quarterly goal-setting', 'talenttrack' );
    }

    public function description(): string {
        return __( 'Draft up to three development goals for the next quarter. Your coach reviews and approves them after submission.', 'talenttrack' );
    }

    public function defaultSchedule(): array {
        // Start of every 3rd month at 00:00. CronDispatcher's evaluator
        // resolves this to Jan/Apr/Jul/Oct 1st at midnight.
        return [ 'type' => 'cron', 'expression' => '0 0 1 */3 *' ];
    }

    public function defaultDeadlineOffset(): string {
        return '+14 days';
    }

    public function defaultAssignee(): AssigneeResolver {
        return new PlayerOrParentResolver();
    }

    public function formClass(): string {
        return GoalSettingForm::class;
    }

    public function entityLinks(): array {
        return [ 'player_id' ];
    }

    /**
     * Fan-out: one task per active rostered player.
     */
    public function expandTrigger( TaskContext $context ): array {
        global $wpdb;
        $player_ids = $wpdb->get_col(
            "SELECT id FROM {$wpdb->prefix}tt_players
             WHERE archived_at IS NULL
               AND ( status IS NULL OR status = '' OR status = 'active' )
               AND team_id IS NOT NULL AND team_id > 0
             ORDER BY id ASC"
        );
        if ( ! is_array( $player_ids ) || empty( $player_ids ) ) return [];

        $contexts = [];
        foreach ( $player_ids as $pid ) {
            $contexts[] = $context->with( [ 'player_id' => (int) $pid ] );
        }
        return $contexts;
    }

    /**
     * After the player submits goals, spawn a GoalApproval task for
     * the team's head coach. Carries the player_id + parent_task_id
     * so the approval form can read the original goals from the
     * parent task's response_json.
     */
    public function onComplete( array $task, array $response ): void {
        $player_id = (int) ( $task['player_id'] ?? 0 );
        if ( $player_id <= 0 ) return;

        global $wpdb;
        $team_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT team_id FROM {$wpdb->prefix}tt_players WHERE id = %d LIMIT 1",
            $player_id
        ) );
        if ( $team_id <= 0 ) return;

        $approval_context = new TaskContext(
            $player_id,
            $team_id,
            null,
            null,
            null,
            null,
            (int) ( $task['id'] ?? 0 )
        );
        WorkflowModule::engine()->dispatch(
            GoalApprovalTemplate::KEY,
            $approval_context
        );
    }
}
