<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Config\ConfigService;
use TT\Infrastructure\FeatureToggles\FeatureToggleService;
use TT\Modules\Invitations\InvitationKind;
use TT\Modules\Invitations\InvitationService;
use TT\Modules\Invitations\InvitationsRepository;
use TT\Modules\Onboarding\OnboardingState;

/**
 * #2965 — adding staff during setup, with their credentials held.
 *
 * The behaviour worth proving is the negative one: adding a coach during
 * onboarding must email nobody. An admin who cannot trust that will not
 * add anyone until the very end, which is exactly the problem the step
 * was built to remove.
 */
final class OnboardingStaffStepTest extends WP_UnitTestCase {

    /** @var int */
    private $dispatches = 0;

    public function set_up(): void {
        parent::set_up();
        OnboardingState::reset();

        ( new FeatureToggleService( new ConfigService() ) )->setEnabled( 'allow_registration', true );
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $this->dispatches = 0;
        add_action( 'tt_comms_dispatch', [ $this, 'countDispatch' ] );
    }

    public function tear_down(): void {
        remove_action( 'tt_comms_dispatch', [ $this, 'countDispatch' ] );
        OnboardingState::reset();
        parent::tear_down();
    }

    public function countDispatch(): void {
        $this->dispatches++;
    }

    private function makePersonWithHeldInvite( string $email ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_people", [
            'club_id'    => 1,
            'first_name' => 'Nieuwe',
            'last_name'  => 'Trainer',
            'email'      => $email,
            'role_type'  => 'staff',
            'status'     => 'active',
        ] );
        $person_id = (int) $wpdb->insert_id;

        $result = ( new InvitationService() )->create( [
            'kind'             => InvitationKind::STAFF,
            'target_person_id' => $person_id,
            'prefill_email'    => $email,
            'defer_send'       => true,
        ] );
        $this->assertTrue( $result['ok'], (string) ( $result['error'] ?? '' ) );

        return $person_id;
    }

    public function test_staff_sits_after_first_admin_and_before_dashboard(): void {
        $steps = OnboardingState::STEPS;

        $admin     = array_search( 'first_admin', $steps, true );
        $staff     = array_search( 'staff', $steps, true );
        $dashboard = array_search( 'dashboard', $steps, true );

        $this->assertNotFalse( $staff, 'the staff step is missing from STEPS' );
        $this->assertGreaterThan( $admin, $staff );
        $this->assertLessThan( $dashboard, $staff );
    }

    public function test_adding_staff_emails_nobody(): void {
        $this->makePersonWithHeldInvite( 'coach@example.test' );

        $this->assertSame( 0, $this->dispatches, 'a held invitation was emailed during setup' );
        $this->assertSame( 1, ( new InvitationsRepository() )->unsentCount() );
    }

    public function test_sending_releases_every_held_invitation(): void {
        $this->makePersonWithHeldInvite( 'one@example.test' );
        $this->makePersonWithHeldInvite( 'two@example.test' );

        $this->assertSame( 0, $this->dispatches );

        $result = ( new InvitationService() )->sendDeferred();

        $this->assertCount( 2, $result['sent'] );
        $this->assertSame( 2, $this->dispatches );
        $this->assertSame( 0, ( new InvitationsRepository() )->unsentCount() );
    }

    public function test_leaving_without_sending_keeps_the_invitations(): void {
        $this->makePersonWithHeldInvite( 'later@example.test' );

        // Skipping the step is not abandoning the invitation — it stays
        // ready, and the admin is told where to find it.
        OnboardingState::setStep( 'dashboard' );

        $this->assertSame( 1, ( new InvitationsRepository() )->unsentCount() );
        $this->assertSame( 0, $this->dispatches );
    }

    public function test_the_step_payload_tracks_who_was_added(): void {
        OnboardingState::recordPayload( 'staff', [
            'added' => [
                [ 'name' => 'A Coach', 'email' => 'a@example.test', 'invited' => true ],
                [ 'name' => 'No Email', 'email' => '', 'invited' => false ],
            ],
        ] );

        $added = OnboardingState::payloadFor( 'staff' )['added'];

        $this->assertCount( 2, $added );
        $this->assertTrue( $added[0]['invited'] );
        $this->assertFalse( $added[1]['invited'], 'someone with no email cannot be invited' );
    }
}
