<?php
namespace TT\Modules\StaffDevelopment\Workflow;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Workflow\Contracts\AssigneeResolver;
use TT\Modules\Workflow\Resolvers\LambdaResolver;
use TT\Modules\Workflow\TaskContext;
use TT\Modules\Workflow\TaskTemplate;

/**
 * StaffAnnualSelfEvalTemplate — fires once per year on Sept 1, fans
 * out one task per non-archived staff member. Assignee resolves to the
 * staff member's WP user id via `tt_people.wp_user_id`. 30-day
 * deadline.
 */
class StaffAnnualSelfEvalTemplate extends TaskTemplate {

    public const KEY = 'staff_annual_self_eval';

    public function key(): string { return self::KEY; }

    public function name(): string {
        return __( 'Annual staff self-evaluation', 'talenttrack' );
    }

    public function description(): string {
        return __( 'Self-rate against the staff evaluation categories at the start of every academy year.', 'talenttrack' );
    }

    public function defaultSchedule(): array {
        return [ 'type' => 'cron', 'expression' => '0 0 1 9 *' ];
    }

    public function defaultDeadlineOffset(): string {
        return '+30 days';
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
