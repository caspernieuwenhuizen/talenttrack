<?php
namespace TT\Modules\Workflow\Templates;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Workflow\Contracts\AssigneeResolver;
use TT\Modules\Workflow\Forms\PostGameEvaluationForm;
use TT\Modules\Workflow\Resolvers\TeamHeadCoachResolver;
use TT\Modules\Workflow\TaskContext;
use TT\Modules\Workflow\TaskTemplate;

/**
 * PostGameEvaluationTemplate (#0035) — fans out one task per active
 * player on the team that played the game. The head coach gets every
 * task; the form captures a brief reflection per player (overall
 * rating + what went well + what to work on).
 *
 * Trigger: event-driven on `tt_activity_completed`. The dispatcher
 * filters on `activity_type_key = 'game'` (any subtype — friendly /
 * cup / league all spawn the eval; clubs that only want league evals
 * disable + reconfigure via the workflow admin UI).
 *
 * Default deadline: 72 hours from creation.
 *
 * Context required: `team_id` and `activity_id`. `expandTrigger()` walks
 * `tt_players.team_id` to fan out one TaskContext per active player.
 */
class PostGameEvaluationTemplate extends TaskTemplate {

    public const KEY = 'post_game_evaluation';

    public function key(): string { return self::KEY; }

    public function name(): string {
        return __( 'Post-game coach evaluation', 'talenttrack' );
    }

    public function description(): string {
        return __( 'Quick reflection on each player\'s game performance — overall feel plus what went well and what to work on. Due 72 hours after the game.', 'talenttrack' );
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
        return PostGameEvaluationForm::class;
    }

    public function entityLinks(): array {
        return [ 'team_id', 'activity_id', 'player_id' ];
    }

    /**
     * Fan-out: one task per active player on the team. Each carries
     * the original activity + team and the per-player player_id.
     *
     * #0035: only fires when the activity is type=game (any subtype —
     * friendly / cup / league all spawn the eval). Trainings + other
     * activities short-circuit to an empty array, so the dispatcher
     * never creates tasks for them.
     */
    public function expandTrigger( TaskContext $context ): array {
        if ( ! $context->team_id || ! $context->activity_id ) return [];

        global $wpdb;
        $type = (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT activity_type_key FROM {$wpdb->prefix}tt_activities WHERE id = %d",
            (int) $context->activity_id
        ) );
        if ( $type !== 'game' ) return [];

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
