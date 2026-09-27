<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Config\ConfigService;
use TT\Infrastructure\FeatureToggles\FeatureToggleService;
use TT\Infrastructure\People\StaffPersonProvisioner;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Invitations\InvitationKind;
use TT\Modules\Invitations\InvitationService;
use TT\Modules\Invitations\InvitationsRepository;

/**
 * #4091 — every staff login has a People record.
 *
 * `POST trial-cases/{id}/staff` takes `person_id` only now, so an account
 * that held a staff role without a person record could not be put on a
 * panel at all. Migration 0296 backfills the accounts that exist; the role
 * hooks create the record for every account that gains a staff role later.
 *
 * The suite holds the role hook (tests/php/bootstrap.php); these tests
 * release it and hold it again afterwards.
 */
final class StaffPersonProvisionerTest extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        StaffPersonProvisioner::release();
    }

    public function tear_down(): void {
        StaffPersonProvisioner::hold();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_a_new_staff_login_gets_a_person_record(): void {
        $user = self::factory()->user->create( [
            'role' => 'tt_coach', 'first_name' => 'Karin', 'last_name' => 'Keeperstra', 'user_email' => 'karin@example.test',
        ] );

        $rows = $this->peopleFor( $user );
        $this->assertCount( 1, $rows );
        $this->assertSame( 'Karin', $rows[0]['first_name'] );
        $this->assertSame( 'Keeperstra', $rows[0]['last_name'] );
        $this->assertSame( 'coach', $rows[0]['role_type'] );
        $this->assertSame( 'active', $rows[0]['status'] );
        $this->assertSame( (int) CurrentClub::id(), (int) $rows[0]['club_id'] );
    }

    public function test_the_display_name_is_used_when_the_account_has_no_name_fields(): void {
        $user = self::factory()->user->create( [ 'role' => 'tt_scout', 'display_name' => 'Sanne van der Berg' ] );

        $rows = $this->peopleFor( $user );
        $this->assertCount( 1, $rows );
        $this->assertSame( 'Sanne', $rows[0]['first_name'] );
        $this->assertSame( 'van der Berg', $rows[0]['last_name'] );
        $this->assertSame( 'scout', $rows[0]['role_type'] );
    }

    public function test_a_role_granted_later_creates_the_record_then(): void {
        $user = self::factory()->user->create( [ 'role' => 'subscriber' ] );
        $this->assertCount( 0, $this->peopleFor( $user ) );

        ( new \WP_User( $user ) )->add_role( 'tt_staff' );

        $this->assertCount( 1, $this->peopleFor( $user ) );
    }

    public function test_it_is_idempotent(): void {
        $user = self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $this->assertCount( 1, $this->peopleFor( $user ) );

        ( new \WP_User( $user ) )->set_role( 'tt_head_dev' );
        $this->assertSame( 0, ( new StaffPersonProvisioner() )->ensureForUser( $user ) );
        ( new StaffPersonProvisioner() )->backfill();

        $this->assertCount( 1, $this->peopleFor( $user ) );
    }

    public function test_an_account_held_by_an_inactive_record_is_left_alone(): void {
        StaffPersonProvisioner::hold();
        $user = self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        StaffPersonProvisioner::release();

        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_people", [
            'club_id' => (int) CurrentClub::id(), 'first_name' => 'Oud', 'last_name' => 'Record',
            'role_type' => 'coach', 'wp_user_id' => $user, 'status' => 'inactive',
        ] );

        $this->assertSame( 0, ( new StaffPersonProvisioner() )->ensureForUser( $user ) );
        $this->assertCount( 1, $this->peopleFor( $user ) );
    }

    public function test_a_player_login_is_never_given_a_staff_record(): void {
        $user = self::factory()->user->create( [ 'role' => 'subscriber' ] );
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id' => (int) CurrentClub::id(), 'first_name' => 'Sem', 'last_name' => 'Spelersma',
            'status' => 'active', 'wp_user_id' => $user,
        ] );

        ( new \WP_User( $user ) )->set_role( 'tt_coach' );
        ( new StaffPersonProvisioner() )->backfill();

        $this->assertCount( 0, $this->peopleFor( $user ) );
    }

    public function test_a_parent_login_is_never_given_a_staff_record(): void {
        $parent = self::factory()->user->create( [ 'role' => 'subscriber' ] );
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id' => (int) CurrentClub::id(), 'first_name' => 'Kind', 'last_name' => 'Van Ouder', 'status' => 'active',
        ] );
        $wpdb->insert( "{$wpdb->prefix}tt_player_parents", [
            'club_id' => (int) CurrentClub::id(), 'player_id' => (int) $wpdb->insert_id, 'parent_user_id' => $parent, 'is_primary' => 1,
        ] );

        ( new \WP_User( $parent ) )->set_role( 'tt_coach' );
        $this->assertCount( 0, $this->peopleFor( $parent ), 'a parent-bound login got a staff record' );

        $both = self::factory()->user->create( [ 'role' => 'tt_parent' ] );
        ( new \WP_User( $both ) )->add_role( 'tt_coach' );
        $this->assertCount( 0, $this->peopleFor( $both ), 'a login holding the parent role got a staff record' );
    }

    public function test_the_backfill_reports_what_it_created(): void {
        StaffPersonProvisioner::hold();
        $a = self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $b = self::factory()->user->create( [ 'role' => 'tt_team_manager' ] );
        self::factory()->user->create( [ 'role' => 'subscriber' ] );
        StaffPersonProvisioner::release();

        $created = ( new StaffPersonProvisioner() )->backfill();

        $this->assertGreaterThanOrEqual( 2, $created );
        $this->assertCount( 1, $this->peopleFor( $a ) );
        $this->assertSame( 'manager', $this->peopleFor( $b )[0]['role_type'] );
        $this->assertSame( 0, ( new StaffPersonProvisioner() )->backfill(), 'a second run created records' );
    }

    public function test_a_staff_invitation_naming_a_person_links_it_without_a_second_record(): void {
        $this->enableInvitations();
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_people", [
            'club_id' => (int) CurrentClub::id(), 'first_name' => 'Anouk', 'last_name' => 'Uitgenodigd',
            'role_type' => 'coach', 'status' => 'active',
        ] );
        $person = (int) $wpdb->insert_id;

        $out = ( new InvitationService() )->accept( $this->staffInvitation( $person ), [
            'recovery_email' => 'anouk@example.test', 'password' => 'correct-horse-battery',
        ] );

        $this->assertTrue( $out['ok'], (string) ( $out['error'] ?? '' ) );
        $rows = $this->peopleFor( (int) $out['user_id'] );
        $this->assertCount( 1, $rows );
        $this->assertSame( $person, (int) $rows[0]['id'] );
    }

    public function test_a_staff_invitation_naming_nobody_creates_the_record(): void {
        $this->enableInvitations();

        $out = ( new InvitationService() )->accept( $this->staffInvitation( 0 ), [
            'recovery_email' => 'nieuw@example.test', 'password' => 'correct-horse-battery',
        ] );

        $this->assertTrue( $out['ok'], (string) ( $out['error'] ?? '' ) );
        $this->assertCount( 1, $this->peopleFor( (int) $out['user_id'] ) );
    }

    private function enableInvitations(): void {
        ( new FeatureToggleService( new ConfigService() ) )->setEnabled( 'allow_registration', true );
        StaffPersonProvisioner::hold();
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        StaffPersonProvisioner::release();
    }

    private function staffInvitation( int $person_id ): object {
        $args = [ 'kind' => InvitationKind::STAFF, 'prefill_email' => '' ];
        if ( $person_id > 0 ) $args['target_person_id'] = $person_id;
        $result = ( new InvitationService() )->create( $args );
        $this->assertTrue( $result['ok'], (string) ( $result['error'] ?? '' ) );
        $row = ( new InvitationsRepository() )->find( (int) $result['id'] );
        $this->assertIsObject( $row );
        return $row;
    }

    /** @return list<array<string,mixed>> */
    private function peopleFor( int $user_id ): array {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tt_people WHERE wp_user_id = %d AND club_id = %d ORDER BY id",
            $user_id, (int) CurrentClub::id()
        ), ARRAY_A );
        return is_array( $rows ) ? array_values( $rows ) : [];
    }
}
