<?php
namespace TT\Modules\Workflow;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * TaskContext — the bag of identifiers passed from a trigger through
 * fan-out and on to the AssigneeResolver. Plain value object; no
 * behaviour beyond getters.
 *
 * The fields mirror the entity-link columns on tt_workflow_tasks.
 * Templates with no entity links still get a TaskContext with all
 * fields null (e.g. the Quarterly HoD review).
 *
 * `extras` is an open map for trigger-specific data that isn't an
 * entity FK (e.g. a session-completed event might pass `match_result`
 * if useful). Resolvers are free to consult it; the engine doesn't
 * persist it.
 */
final class TaskContext {

    public function __construct(
        public readonly ?int $player_id     = null,
        public readonly ?int $team_id       = null,
        public readonly ?int $session_id    = null,
        public readonly ?int $evaluation_id = null,
        public readonly ?int $goal_id       = null,
        public readonly ?int $trial_case_id = null,
        public readonly ?int $parent_task_id = null,
        /** @var array<string,mixed> */
        public readonly array $extras       = []
    ) {}

    /**
     * Returns the entity-link payload as a column => value map suitable
     * for $wpdb->insert into tt_workflow_tasks. Null values are kept
     * (the column is nullable).
     *
     * @return array<string,int|null>
     */
    public function toEntityLinks(): array {
        return [
            'player_id'      => $this->player_id,
            'team_id'        => $this->team_id,
            'session_id'     => $this->session_id,
            'evaluation_id'  => $this->evaluation_id,
            'goal_id'        => $this->goal_id,
            'trial_case_id'  => $this->trial_case_id,
            'parent_task_id' => $this->parent_task_id,
        ];
    }

    /**
     * Convenience: derive a new context from this one with one field
     * overridden. Used by fan-out templates that take a base context
     * and fan out across players.
     */
    public function with( array $overrides ): self {
        return new self(
            $overrides['player_id']      ?? $this->player_id,
            $overrides['team_id']        ?? $this->team_id,
            $overrides['session_id']     ?? $this->session_id,
            $overrides['evaluation_id']  ?? $this->evaluation_id,
            $overrides['goal_id']        ?? $this->goal_id,
            $overrides['trial_case_id']  ?? $this->trial_case_id,
            $overrides['parent_task_id'] ?? $this->parent_task_id,
            $overrides['extras']         ?? $this->extras
        );
    }
}
