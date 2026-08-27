<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Config\ConfigService;
use TT\Infrastructure\FeatureToggles\FeatureToggleService;
use TT\Modules\Invitations\InvitationKind;
use TT\Modules\Invitations\InvitationService;
use TT\Modules\Invitations\InvitationsRepository;

/**
 * #2964 — creating an invitation and mailing someone their credentials
 * used to be the same irreversible action.
 *
 * The behaviour that matters here is the negative one: a deferred
 * invitation must send NOTHING. A test that only proves the explicit send
 * works would pass just as happily if the notifier were still firing on
 * creation, which is the bug.
 *
 * `tt_comms_dispatch` is the chokepoint the notifier hands off to, so
 * counting that action is how we observe "was anyone actually mailed".
 */
final class DeferredInvitationTest extends WP_UnitTestCase {

    /** @var int */
    private $dispatches = 0;

    /** @var int */
    private $person;

    public function set_up(): void {
        parent::set_up();

        ( new FeatureToggleService( new ConfigService() ) )->setEnabled( 'allow_registration', true );
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_people", [
            'club_id'    => 1,
            'first_name' => 'Sanne',
            'last_name'  => 'de Boer',
            'email'      => 'sanne@example.test',
            'role_type'  => 'staff',
            'status'     => 'active',
        ] );
        $this->person = (int) $wpdb->insert_id;

        $this->dispatches = 0;
        add_action( 'tt_comms_dispatch', [ $this, 'countDispatch' ] );
    }

    public function tear_down(): void {
        remove_action( 'tt_comms_dispatch', [ $this, 'countDispatch' ] );
        parent::tear_down();
    }

    public function countDispatch(): void {
        $this->dispatches++;
    }

    /** @param array<string,mixed> $extra */
    private function create( array $extra = [] ): array {
        return ( new InvitationService() )->create( array_merge( [
            'kind'             => InvitationKind::STAFF,
            'target_person_id' => $this->person,
            'prefill_email'    => 'sanne@example.test',
        ], $extra ) );
    }

    /**
     * A second invitation needs a second person: create() deduplicates on
     * the target, returning the existing pending invitation rather than
     * issuing a new one. Two invitations for one person are the same
     * invitation.
     *
     * @param array<string,mixed> $extra
     */
    private function createForNewPerson( array $extra = [] ): array {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_people", [
            'club_id'    => 1,
            'first_name' => 'Ander',
            'last_name'  => 'Persoon',
            'email'      => 'other@example.test',
            'role_type'  => 'staff',
            'status'     => 'active',
        ] );

        return ( new InvitationService() )->create( array_merge( [
            'kind'             => InvitationKind::STAFF,
            'target_person_id' => (int) $wpdb->insert_id,
            'prefill_email'    => 'other@example.test',
        ], $extra ) );
    }

    public function test_a_deferred_invitation_mails_nobody(): void {
        $result = $this->create( [ 'defer_send' => true ] );

        $this->assertTrue( $result['ok'], (string) ( $result['error'] ?? '' ) );
        $this->assertSame( 0, $this->dispatches, 'a deferred invitation was emailed anyway' );

        $row = ( new InvitationsRepository() )->find( (int) $result['id'] );
        $this->assertNull( $row->sent_at );
    }

    public function test_an_ordinary_invitation_still_sends_immediately(): void {
        // The pre-existing behaviour must be byte-identical for callers
        // that do not opt in.
        $result = $this->create();

        $this->assertTrue( $result['ok'] );
        $this->assertSame( 1, $this->dispatches, 'the normal create path stopped sending' );

        $row = ( new InvitationsRepository() )->find( (int) $result['id'] );
        $this->assertNotNull( $row->sent_at );
    }

    public function test_explicit_send_delivers_and_stamps(): void {
        $result = $this->create( [ 'defer_send' => true ] );
        $id     = (int) $result['id'];

        $this->assertTrue( ( new InvitationService() )->send( $id ) );
        $this->assertSame( 1, $this->dispatches );

        $row = ( new InvitationsRepository() )->find( $id );
        $this->assertNotNull( $row->sent_at );
    }

    public function test_sending_twice_does_not_double_deliver(): void {
        $result  = $this->create( [ 'defer_send' => true ] );
        $id      = (int) $result['id'];
        $service = new InvitationService();

        $this->assertTrue( $service->send( $id ) );
        $this->assertFalse( $service->send( $id ), 'a second send was allowed' );
        $this->assertSame( 1, $this->dispatches, 'the invitee was mailed twice' );
    }

    public function test_bulk_send_reports_per_invitation(): void {
        $a = (int) $this->create( [ 'defer_send' => true ] )['id'];
        $b = (int) $this->createForNewPerson( [ 'defer_send' => true ] )['id'];
        $this->assertNotSame( $a, $b );

        $service = new InvitationService();
        $service->send( $a ); // already sent — must be reported as skipped
        $this->dispatches = 0;

        $out = $service->sendDeferred();

        $this->assertSame( [ $b ], $out['sent'] );
        $this->assertSame( [], $out['skipped'], 'an unsent invitation was skipped' );
        $this->assertSame( 1, $this->dispatches );
    }

    public function test_unsent_count_tracks_what_is_waiting(): void {
        $repo = new InvitationsRepository();

        $this->assertSame( 0, $repo->unsentCount() );

        $this->create( [ 'defer_send' => true ] );
        $this->createForNewPerson( [ 'defer_send' => true ] );
        $this->assertSame( 2, $repo->unsentCount() );

        $this->createForNewPerson(); // sent immediately — not waiting
        $this->assertSame( 2, $repo->unsentCount() );

        ( new InvitationService() )->sendDeferred();
        $this->assertSame( 0, $repo->unsentCount() );
    }

    public function test_a_link_only_deferred_invite_sends_nothing_when_released(): void {
        // No prefill email: the notifier's supported "link-only" path. The
        // send still stamps, so it stops showing as waiting.
        $result = $this->create( [ 'defer_send' => true, 'prefill_email' => '' ] );
        $id     = (int) $result['id'];

        $this->assertTrue( ( new InvitationService() )->send( $id ) );
        $this->assertSame( 0, $this->dispatches );
        $this->assertSame( 0, ( new InvitationsRepository() )->unsentCount() );
    }
}
