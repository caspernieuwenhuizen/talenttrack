<?php
namespace TT\Modules\Pdp\Workflow;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Workflow\Contracts\AssigneeResolver;
use TT\Modules\Workflow\Resolvers\RoleBasedResolver;
use TT\Modules\Workflow\TaskTemplate;

/**
 * PdpVerdictDueTemplate — once per PDP file at season-end-minus-N-days.
 * Assigned to head of academy (`tt_head_dev`).
 *
 * Sprint 1 ships registration only; Sprint 2 wires the cron + form.
 */
class PdpVerdictDueTemplate extends TaskTemplate {

    public const KEY = 'pdp_verdict_due';

    public function key(): string { return self::KEY; }

    public function name(): string {
        return __( 'PDP verdict due', 'talenttrack' );
    }

    public function description(): string {
        return __( 'End-of-season decision for each player\'s PDP file. Assigned to the head of academy.', 'talenttrack' );
    }

    public function defaultSchedule(): array {
        return [ 'type' => 'manual' ];
    }

    public function defaultDeadlineOffset(): string {
        return '+14 days';
    }

    public function defaultAssignee(): AssigneeResolver {
        return new RoleBasedResolver( 'tt_head_dev' );
    }

    public function formClass(): string {
        return PdpStubForm::class;
    }

    public function entityLinks(): array {
        return [ 'player_id' ];
    }
}
