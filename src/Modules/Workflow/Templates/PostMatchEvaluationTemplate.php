<?php
namespace TT\Modules\Workflow\Templates;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Workflow\Contracts\AssigneeResolver;
use TT\Modules\Workflow\Forms\PostMatchEvaluationForm;
use TT\Modules\Workflow\Resolvers\TeamHeadCoachResolver;
use TT\Modules\Workflow\TaskContext;
use TT\Modules\Workflow\TaskTemplate;

/**
 * PostMatchEvaluationTemplate — fans out one task per active player
 * on the team that played the match. The head coach gets every task;
 * the form captures a brief reflection per player (overall rating +
 * what went well + what to work on).
 *
 * Trigger: manual in v1 (a coach hits "Generate post-match tasks" from
 * a session detail page; that admin button lands in Sprint 5). Once
 * the parallel #0026 branch settles, the SessionsModule can fire
 * `tt_session_completed` and an event-trigger row in
 * tt_workflow_triggers picks up automatically.
 *
 * Default deadline: 72 hours from creation.
 *
 * Context required: `team_id` and `session_id`. `expandTrigger()` walks
 * `tt_players.team_id` to fan out one TaskContext per active player.
 */
class PostMatchEvaluationTemplate extends TaskTemplate {

    public const KEY = 'post_match_evaluation';

    public function key(): string { return self::KEY; }

    public function name(): string {
        return __( 'Post-match coach evaluation', 'talenttrack' );
    }

    public function description(): string {
        return __( 'Quick reflection on each player\'s match performance — overall feel plus what went well and what to work on. Due 72 hours after the match.', 'talenttrack' );
    }

    public function defaultSchedule(): array {
        return [ 'type' => 'manual' ];
    }

    public function defaultDeadlineOffset(): string {
        return '+72 hours';
    }

    public function defaultAssignee(): AssigneeResolver {
        return new TeamHeadCoachResolver();
    }

    public function formClass(): string {
        return PostMatchEvaluationForm::class;
    }

    public function entityLinks(): array {
        return [ 'team_id', 'session_id', 'player_id' ];
    }

    /**
     * Fan-out: one task per active player on the team. Each carries
     * the original session + team and the per-player player_id.
     */
    public function expandTrigger( TaskContext $context ): array {
        if ( ! $context->team_id ) return [];
        global $wpdb;
        $player_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}tt_players
             WHERE team_id = %d
               AND archived_at IS NULL
               AND ( status IS NULL OR status = '' OR status = 'active' )
             ORDER BY id ASC",
            (int) $context->team_id
        ) );
        if ( ! is_array( $player_ids ) || empty( $player_ids ) ) return [];

        $contexts = [];
        foreach ( $player_ids as $pid ) {
            $contexts[] = $context->with( [ 'player_id' => (int) $pid ] );
        }
        return $contexts;
    }
}
