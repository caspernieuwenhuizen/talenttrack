<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\Invitations\InvitationService;
use TT\Modules\Invitations\InvitationsRepository;
use TT\Modules\Invitations\InvitationKind;
use TT\Infrastructure\Config\ConfigService;
use TT\Infrastructure\FeatureToggles\FeatureToggleService;

/**
 * #2963 — redemption is the one path that produced two addresses for one
 * person by construction, every single time.
 *
 * Accepting an invitation created the WP account from the address the
 * invitee typed and linked it to the person row, but never wrote that
 * address back. So an admin who guessed the address when creating the
 * person, and an invitee who accepted with their own, left the install
 * holding both — with different code paths sending to each.
 */
final class InvitationRedemptionEmailTest extends WP_UnitTestCase {

    /** @var string */
    private $p;

    /** @var int */
    private $club = 1;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->p = $wpdb->prefix;

        // Both create() and accept() refuse when registration is off, and
        // create() additionally requires an acting user.
        ( new FeatureToggleService( new ConfigService() ) )->setEnabled( 'allow_registration', true );
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
    }

    private function makePerson( ?string $email ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_people", [
            'club_id'    => $this->club,
            'first_name' => 'Sanne',
            'last_name'  => 'de Boer',
            'email'      => $email,
            'wp_user_id' => null,
            'role_type'  => 'staff',
            'status'     => 'active',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function makeStaffInvitation( int $personId, string $prefill ): object {
        $repo = new InvitationsRepository();
        $service = new InvitationService();
        $result = $service->create( [
            'kind'             => InvitationKind::STAFF,
            'target_person_id' => $personId,
            'prefill_email'    => $prefill,
        ] );
        $this->assertTrue( $result['ok'], (string) ( $result['error'] ?? '' ) );
        $id = (int) $result['id'];
        return $repo->find( $id );
    }

    private function personEmail( int $personId ): string {
        global $wpdb;
        return (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT email FROM {$this->p}tt_people WHERE id = %d AND club_id = %d",
            $personId, $this->club
        ) );
    }

    private function personUserId( int $personId ): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT wp_user_id FROM {$this->p}tt_people WHERE id = %d AND club_id = %d",
            $personId, $this->club
        ) );
    }

    public function test_redemption_address_replaces_the_admins_guess(): void {
        $personId   = $this->makePerson( 'guessed@example.test' );
        $invitation = $this->makeStaffInvitation( $personId, 'guessed@example.test' );

        $out = ( new InvitationService() )->accept( $invitation, [
            'recovery_email' => 'actual@example.test',
            'password'       => 'correct-horse-battery',
        ] );

        $this->assertTrue( $out['ok'], (string) ( $out['error'] ?? '' ) );
        $this->assertSame( 'actual@example.test', $this->personEmail( $personId ) );
        $this->assertSame( (int) $out['user_id'], $this->personUserId( $personId ) );
    }

    public function test_redemption_fills_an_empty_person_email(): void {
        $personId   = $this->makePerson( null );
        $invitation = $this->makeStaffInvitation( $personId, '' );

        $out = ( new InvitationService() )->accept( $invitation, [
            'recovery_email' => 'fresh@example.test',
            'password'       => 'correct-horse-battery',
        ] );

        $this->assertTrue( $out['ok'], (string) ( $out['error'] ?? '' ) );
        $this->assertSame( 'fresh@example.test', $this->personEmail( $personId ) );
    }

    public function test_the_two_stores_agree_after_redemption(): void {
        $personId   = $this->makePerson( 'guessed@example.test' );
        $invitation = $this->makeStaffInvitation( $personId, 'guessed@example.test' );

        $out = ( new InvitationService() )->accept( $invitation, [
            'recovery_email' => 'agreed@example.test',
            'password'       => 'correct-horse-battery',
        ] );

        $this->assertTrue( $out['ok'], (string) ( $out['error'] ?? '' ) );

        $user = get_userdata( (int) $out['user_id'] );
        $this->assertSame( (string) $user->user_email, $this->personEmail( $personId ) );
    }

    public function test_an_existing_account_email_is_still_rejected(): void {
        // The email_exists guard is unrelated to this change and must
        // keep working — a redemption that collides is refused, not
        // silently written through to the person row.
        self::factory()->user->create( [ 'user_email' => 'taken@example.test' ] );
        $personId   = $this->makePerson( 'guessed@example.test' );
        $invitation = $this->makeStaffInvitation( $personId, 'guessed@example.test' );

        $out = ( new InvitationService() )->accept( $invitation, [
            'recovery_email' => 'taken@example.test',
            'password'       => 'correct-horse-battery',
        ] );

        $this->assertFalse( $out['ok'] );
        $this->assertSame( 'guessed@example.test', $this->personEmail( $personId ) );
    }
}
