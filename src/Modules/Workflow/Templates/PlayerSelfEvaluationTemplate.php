<?php
namespace TT\Modules\Workflow\Templates;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Workflow\Contracts\AssigneeResolver;
use TT\Modules\Workflow\Forms\PlayerSelfEvaluationForm;
use TT\Modules\Workflow\Resolvers\PlayerOrParentResolver;
use TT\Modules\Workflow\TaskContext;
use TT\Modules\Workflow\TaskTemplate;

/**
 * PlayerSelfEvaluationTemplate — fans out one task per active rostered
 * player every Sunday at 18:00 (cron `0 18 * * 0`). The resolver
 * routes each task per the install's minors-assignment policy
 * (player vs parent vs both).
 *
 * Default deadline: 7 days from creation (so the task is still open
 * the following Sunday before the next one is created).
 *
 * Sprint 5's admin UI lets clubs disable this template entirely, or
 * change the cadence (e.g. fortnightly). Until then, the seeded
 * trigger row is what runs.
 */
class PlayerSelfEvaluationTemplate extends TaskTemplate {

    public const KEY = 'player_self_evaluation';

    public function key(): string { return self::KEY; }

    public function name(): string {
        return __( 'Weekly self-evaluation', 'talenttrack' );
    }

    public function description(): string {
        return __( 'How did this week feel? Quick self-rating plus what went well and what to work on next week.', 'talenttrack' );
    }

    public function defaultSchedule(): array {
        return [ 'type' => 'cron', 'expression' => '0 18 * * 0' ];
    }

    public function defaultDeadlineOffset(): string {
        return '+7 days';
    }

    public function defaultAssignee(): AssigneeResolver {
        return new PlayerOrParentResolver();
    }

    public function formClass(): string {
        return PlayerSelfEvaluationForm::class;
    }

    public function entityLinks(): array {
        return [ 'player_id' ];
    }

    /**
     * Fan-out: every active rostered player gets a task per cron tick.
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
}
