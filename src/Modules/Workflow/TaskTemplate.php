<?php
namespace TT\Modules\Workflow;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Workflow\Contracts\TaskTemplateInterface;

/**
 * TaskTemplate — convenient base class for concrete templates. Provides
 * default no-op behaviour for expandTrigger() (single task, no fan-out)
 * and onComplete() (do nothing). Sprint 1 ships the base only;
 * concrete templates land in Sprints 3-4.
 *
 * Templates that don't fan out OR don't need post-completion behaviour
 * extend this and implement only the abstract bits.
 */
abstract class TaskTemplate implements TaskTemplateInterface {

    /** Default: a single task, no fan-out. Override in fan-out templates. */
    public function expandTrigger( TaskContext $context ): array {
        return [ $context ];
    }

    /** Default: no-op. Override in templates that need post-completion behaviour. */
    public function onComplete( array $task, array $response ): void {
        // intentionally empty
    }

    /** Default: no entity links. Override to declare them. */
    public function entityLinks(): array {
        return [];
    }

    /** Default: manual trigger. Override for cron / event templates. */
    public function defaultSchedule(): array {
        return [ 'type' => 'manual' ];
    }

    /**
     * #0022 Phase 2 — declarative chain primitive. Templates whose
     * completion should spawn follow-up tasks return ChainStep[] here.
     * The engine walks the steps after onComplete() and dispatches each
     * one whose condition holds.
     *
     * Default empty array; templates with chains override.
     *
     * @return list<\TT\Modules\Workflow\Chain\ChainStep>
     */
    public function chainSteps(): array {
        return [];
    }
}
