<?php
namespace TT\Modules\StaffDevelopment;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\Container;
use TT\Core\ModuleInterface;
use TT\Modules\StaffDevelopment\Rest\StaffDevelopmentRestController;
use TT\Modules\StaffDevelopment\Workflow\StaffAnnualSelfEvalTemplate;
use TT\Modules\StaffDevelopment\Workflow\StaffCertificationExpiringTemplate;
use TT\Modules\StaffDevelopment\Workflow\StaffPdpSeasonReviewTemplate;
use TT\Modules\StaffDevelopment\Workflow\StaffTopDownReviewTemplate;
use TT\Modules\Workflow\WorkflowModule;

/**
 * StaffDevelopmentModule (#0039) — personal-development surface for
 * academy staff (coaches, scouts, mentors, support staff).
 *
 * Mirrors the player module's primitives applied to `tt_people` rows:
 * goals, evaluations, PDP. Adds a certifications register that doesn't
 * have a player-side equivalent, plus the Mentor functional role +
 * `tt_staff_mentorships` pivot.
 *
 * Schema lives in migration 0046; capabilities are installed
 * idempotently here on boot. Tile registrations live in
 * `CoreSurfaceRegistration`.
 */
class StaffDevelopmentModule implements ModuleInterface {

    public function getName(): string { return 'staff_development'; }

    public function register( Container $container ): void {}

    public function boot( Container $container ): void {
        add_action( 'init', [ self::class, 'ensureCapabilities' ] );
        add_action( 'init', [ self::class, 'registerWorkflowTemplates' ], 5 );

        StaffDevelopmentRestController::init();
    }

    /**
     * Idempotent capability assignment.
     *
     *   tt_view_staff_development          — see the staff-development
     *                                        surface (own data for staff,
     *                                        anyone for managers).
     *   tt_manage_staff_development         — full edit on any staff
     *                                        member's records.
     *   tt_view_staff_certifications_expiry — see the org-wide expiring
     *                                        certifications roll-up.
     */
    public static function ensureCapabilities(): void {
        $view   = 'tt_view_staff_development';
        $manage = 'tt_manage_staff_development';
        $expiry = 'tt_view_staff_certifications_expiry';

        $view_roles   = [ 'administrator', 'tt_head_dev', 'tt_club_admin', 'tt_coach', 'tt_scout', 'tt_staff' ];
        $manage_roles = [ 'administrator', 'tt_head_dev', 'tt_club_admin' ];
        $expiry_roles = [ 'administrator', 'tt_head_dev', 'tt_club_admin' ];

        foreach ( $view_roles as $r ) {
            $role = get_role( $r );
            if ( $role && ! $role->has_cap( $view ) ) $role->add_cap( $view );
        }
        foreach ( $manage_roles as $r ) {
            $role = get_role( $r );
            if ( $role && ! $role->has_cap( $manage ) ) $role->add_cap( $manage );
        }
        foreach ( $expiry_roles as $r ) {
            $role = get_role( $r );
            if ( $role && ! $role->has_cap( $expiry ) ) $role->add_cap( $expiry );
        }
    }

    /**
     * Register the four workflow templates with the shared registry.
     * Stubs in v1 — registration only; the cron / dispatcher wiring
     * follows the same pattern as #0044's PDP templates.
     */
    public static function registerWorkflowTemplates(): void {
        if ( ! class_exists( WorkflowModule::class ) ) return;
        $registry = WorkflowModule::registry();
        $registry->register( new StaffAnnualSelfEvalTemplate() );
        $registry->register( new StaffTopDownReviewTemplate() );
        $registry->register( new StaffCertificationExpiringTemplate() );
        $registry->register( new StaffPdpSeasonReviewTemplate() );
    }
}
