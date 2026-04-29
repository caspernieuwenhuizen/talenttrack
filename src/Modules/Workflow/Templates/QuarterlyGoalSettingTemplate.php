<?php
namespace TT\Modules\Workflow\Templates;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Workflow\Chain\ChainStep;
use TT\Modules\Workflow\Contracts\AssigneeResolver;
use TT\Modules\Workflow\Forms\GoalSettingForm;
use TT\Modules\Workflow\Resolvers\PlayerOrParentResolver;
use TT\Modules\Workflow\TaskContext;
use TT\Modules\Workflow\TaskTemplate;

/**
 * QuarterlyGoalSettingTemplate — fans out one task per active rostered
 * player at the start of each quarter (cron at 00:00 on the 1st of
 * every 3rd month). The player (or parent — minors policy) drafts up
 * to three goals for the next quarter; on completion, the template
 * spawns a follow-up GoalApproval task on the team's head coach.
 *
 * Phase 2 — the "spawn on complete" mechanic now uses the first-class
 * `chainSteps()` primitive. The follow-up GoalApproval task is declared
 * in chainSteps(); the engine walks it after onComplete() runs. Old
 * onComplete() hand-roll deleted.
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
        $player_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}tt_players
             WHERE archived_at IS NULL
               AND ( status IS NULL OR status = '' OR status = 'active' )
               AND team_id IS NOT NULL AND team_id > 0
               AND club_id = %d
             ORDER BY id ASC",
            CurrentClub::id()
        ) );
        if ( ! is_array( $player_ids ) || empty( $player_ids ) ) return [];

        $contexts = [];
        foreach ( $player_ids as $pid ) {
            $contexts[] = $context->with( [ 'player_id' => (int) $pid ] );
        }
        return $contexts;
    }

    /**
     * Phase 2 chain: spawn a GoalApproval task on completion. The
     * step's contextBuilder resolves the team_id from the player's
     * roster row at chain-time so a re-rostered player still routes
     * to the right coach.
     *
     * @return list<ChainStep>
     */
    public function chainSteps(): array {
        return [
            new ChainStep(
                id: 'goal_approval',
                template_key: GoalApprovalTemplate::KEY,
                contextBuilder: static function ( array $task, array $response ): TaskContext {
                    $player_id = (int) ( $task['player_id'] ?? 0 );
                    if ( $player_id <= 0 ) return ChainStep::inheritContext( $task );
                    global $wpdb;
                    $team_id = (int) $wpdb->get_var( $wpdb->prepare(
                        "SELECT team_id FROM {$wpdb->prefix}tt_players WHERE id = %d AND club_id = %d LIMIT 1",
                        $player_id, CurrentClub::id()
                    ) );
                    return new TaskContext(
                        $player_id,
                        $team_id > 0 ? $team_id : null,
                        null, null, null, null,
                        (int) ( $task['id'] ?? 0 )
                    );
                },
                description: __( 'Coach reviews and approves the player\'s submitted goals.', 'talenttrack' )
            ),
        ];
    }
}
