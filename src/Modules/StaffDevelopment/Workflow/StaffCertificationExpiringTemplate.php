<?php
namespace TT\Modules\StaffDevelopment\Workflow;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\StaffDevelopment\Repositories\StaffCertificationsRepository;
use TT\Modules\Workflow\Contracts\AssigneeResolver;
use TT\Modules\Workflow\Resolvers\LambdaResolver;
use TT\Modules\Workflow\TaskContext;
use TT\Modules\Workflow\TaskTemplate;

/**
 * StaffCertificationExpiringTemplate — daily 06:00 cron walks
 * `tt_staff_certifications.expires_on` against the four threshold
 * windows (90/60/30/0 days). Engine-side dedup prevents the same
 * (cert_id, threshold) firing twice.
 *
 * Assignee: the staff member who holds the cert. The HoD is CC'd via
 * the existing notification channel.
 */
class StaffCertificationExpiringTemplate extends TaskTemplate {

    public const KEY = 'staff_certification_expiring';

    public function key(): string { return self::KEY; }

    public function name(): string {
        return __( 'Staff certification expiring', 'talenttrack' );
    }

    public function description(): string {
        return __( 'A certification (UEFA, first aid, GDPR, etc.) is approaching expiry; confirm or renew.', 'talenttrack' );
    }

    public function defaultSchedule(): array {
        return [ 'type' => 'cron', 'expression' => '0 6 * * *' ];
    }

    public function defaultDeadlineOffset(): string {
        return '+14 days';
    }

    public function defaultAssignee(): AssigneeResolver {
        return new LambdaResolver( static function ( TaskContext $context ): array {
            global $wpdb;
            $cert_id = (int) ( $context->extras['certification_id'] ?? 0 );
            if ( $cert_id <= 0 ) return [];

            $repo = new StaffCertificationsRepository();
            $cert = $repo->find( $cert_id );
            if ( ! $cert ) return [];

            $uid = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT wp_user_id FROM {$wpdb->prefix}tt_people WHERE id = %d",
                (int) $cert->person_id
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
