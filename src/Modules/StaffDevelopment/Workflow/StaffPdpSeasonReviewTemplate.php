<?php
namespace TT\Modules\StaffDevelopment\Workflow;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Workflow\Contracts\AssigneeResolver;
use TT\Modules\Workflow\Resolvers\LambdaResolver;
use TT\Modules\Workflow\TaskContext;
use TT\Modules\Workflow\TaskTemplate;

/**
 * StaffPdpSeasonReviewTemplate — fires when a season is set current
 * (`tt_pdp_season_set_current` action from #0044's carryover). Fans
 * out one task per non-archived staff member: review your PDP for the
 * new season.
 *
 * Schedule type is `manual` because the trigger is event-driven, not
 * cron-driven; the workflow engine's event-dispatcher invokes
 * `expandTrigger()` per fired event.
 */
class StaffPdpSeasonReviewTemplate extends TaskTemplate {

    public const KEY = 'staff_pdp_season_review';

    public function key(): string { return self::KEY; }

    public function name(): string {
        return __( 'Staff PDP season review', 'talenttrack' );
    }

    public function description(): string {
        return __( 'Refresh your personal development plan now that a new season has started.', 'talenttrack' );
    }

    public function defaultSchedule(): array {
        return [ 'type' => 'manual' ];
    }

    public function defaultDeadlineOffset(): string {
        return '+21 days';
    }

    public function defaultAssignee(): AssigneeResolver {
        return new LambdaResolver( static function ( TaskContext $context ): array {
            global $wpdb;
            $person_id = (int) ( $context->extras['person_id'] ?? 0 );
            if ( $person_id <= 0 ) return [];
            $uid = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT wp_user_id FROM {$wpdb->prefix}tt_people WHERE id = %d", $person_id
            ) );
            return $uid > 0 ? [ $uid ] : [];
        } );
    }

    public function formClass(): string {
        return StaffStubForm::class;
    }

    public function entityLinks(): array {
        return [];
    }
}
