<?php
namespace TT\Modules\Workflow\Templates;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Workflow\Contracts\AssigneeResolver;
use TT\Modules\Workflow\Forms\QuarterlyHoDReviewForm;
use TT\Modules\Workflow\Resolvers\RoleBasedResolver;
use TT\Modules\Workflow\TaskTemplate;

/**
 * QuarterlyHoDReviewTemplate — fires at the start of each quarter
 * (cron 00:00 on the 1st of every 3rd month), one task per Head of
 * Development. No entity links; the form pulls live aggregated data
 * at render time (per-team activity, evaluations done, goals set,
 * tasks-completion rate).
 *
 * 14-day deadline. Deliberately long so HoDs have a calm window to
 * write the review without it bouncing into "overdue" prematurely.
 */
class QuarterlyHoDReviewTemplate extends TaskTemplate {

    public const KEY = 'quarterly_hod_review';

    public function key(): string { return self::KEY; }

    public function name(): string {
        return __( 'Quarterly Head of Development review', 'talenttrack' );
    }

    public function description(): string {
        return __( 'Reflect on the past quarter across the academy: what worked, what didn\'t, what to focus on next.', 'talenttrack' );
    }

    public function defaultSchedule(): array {
        // Quarter starts: 00:00 on the 1st of every 3rd month.
        return [ 'type' => 'cron', 'expression' => '0 0 1 */3 *' ];
    }

    public function defaultDeadlineOffset(): string {
        return '+14 days';
    }

    public function defaultAssignee(): AssigneeResolver {
        return new RoleBasedResolver( 'tt_head_dev' );
    }

    public function formClass(): string {
        return QuarterlyHoDReviewForm::class;
    }

    public function entityLinks(): array {
        return [];
    }
}
