<?php
namespace TT\Modules\StaffDevelopment\Workflow;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Workflow\Contracts\AssigneeResolver;
use TT\Modules\Workflow\Resolvers\RoleBasedResolver;
use TT\Modules\Workflow\TaskTemplate;

/**
 * StaffTopDownReviewTemplate — annual top-down review of every staff
 * member. Same Sept 1 cron as the self-eval; assigned to head_dev with
 * a 60-day window. Per-staff fan-out is driven by the dispatcher's
 * extras['person_id'] payload (Sprint 2 of #0039 wires the fan-out).
 */
class StaffTopDownReviewTemplate extends TaskTemplate {

    public const KEY = 'staff_top_down_review';

    public function key(): string { return self::KEY; }

    public function name(): string {
        return __( 'Top-down staff review', 'talenttrack' );
    }

    public function description(): string {
        return __( 'Head of Development reviews each staff member against the staff evaluation categories.', 'talenttrack' );
    }

    public function defaultSchedule(): array {
        return [ 'type' => 'cron', 'expression' => '0 0 1 9 *' ];
    }

    public function defaultDeadlineOffset(): string {
        return '+60 days';
    }

    public function defaultAssignee(): AssigneeResolver {
        return new RoleBasedResolver( 'tt_head_dev' );
    }

    public function formClass(): string {
        return StaffStubForm::class;
    }

    public function entityLinks(): array {
        return [];
    }
}
