<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Config\ConfigService;
use TT\Infrastructure\FeatureToggles\FeatureToggleService;
use TT\Modules\Invitations\InvitationKind;
use TT\Modules\Invitations\InvitationService;
use TT\Modules\Invitations\InvitationsRepository;
use TT\Modules\Invitations\InvitationStatus;

/**
 * #4130 — accepting a staff invitation while signed in never leaves two
 * active People records on one account.
 *
 * The account already has a People record; the invitation names another.
 * The invitation links to the account's record, the invited one is folded
 * into it (team assignments move, it goes inactive), or — when the invited
 * record has history of its own — the accept is refused and the invitation
 * stays pending.
 */
final class StaffInvitationOnePersonPerAccountTest extends WP_UnitTestCase {

    private string $p = '';
    private int $club = 1;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->p = $wpdb->prefix;
        ( new FeatureToggleService( new ConfigService() ) )->setEnabled( 'allow_registration', true );
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
    }

    public function test_the_invited_record_is_folded_into_the_accounts_record(): void {
        global $wpdb;
        $account = self::factory()->user->create( [ 'role' => 'subscriber', 'user_email' => 'coach@example.test' ] );
        $held    = $this->person( 'Sanne', 'de Boer', '', $account );
        $invited = $this->person( 'Sanne', 'de Boer', '0612345678', null );

        $wpdb->insert( "{$this->p}tt_teams", [ 'club_id' => $this->club, 'name' => 'Fold JO13-1' ] );
        $team = (int) $wpdb->insert_id;
        $wpdb->insert( "{$this->p}tt_team_people", [
            'club_id'      => $this->club,
            'team_id'      => $team,
            'person_id'    => $invited,
            'role_in_team' => 'assistant_coach',
        ] );
        $this->assertGreaterThan( 0, (int) $wpdb->insert_id, 'fixture: the assignment was written' );

        $out = ( new InvitationService() )->silentLink( $this->invitation( $invited ), $account );
        $this->assertTrue( $out['ok'], (string) ( $out['error'] ?? '' ) );

        $this->assertSame( [ $held ], $this->activePeopleFor( $account ), 'one active People record holds the account' );
        $this->assertSame( 'inactive', $this->column( $invited, 'status' ), 'the invited record is set aside, not deleted' );
        $this->assertSame( 0, (int) $this->column( $invited, 'wp_user_id' ), 'and is not bound to the account' );
        $this->assertSame( '0612345678', $this->column( $held, 'phone' ), 'its details fill what the kept record lacked' );

        $moved = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->p}tt_team_people WHERE team_id = %d AND person_id = %d",
            $team, $held
        ) );
        $this->assertSame( 1, $moved, 'the team assignment moved to the kept record' );
    }

    public function test_an_invited_record_with_history_is_refused_and_stays_pending(): void {
        global $wpdb;
        $account = self::factory()->user->create( [ 'role' => 'subscriber' ] );
        $held    = $this->person( 'Sanne', 'de Boer', '', $account );
        $invited = $this->person( 'Sanne', 'de Boer', '', null );
        $wpdb->insert( "{$this->p}tt_staff_goals", [
            'club_id'    => $this->club,
            'person_id'  => $invited,
            'title'      => 'UEFA C',
            'created_by' => 1,
        ] );
        $this->assertGreaterThan( 0, (int) $wpdb->insert_id, 'fixture: the staff goal was written' );

        $invitation = $this->invitation( $invited );
        $out        = ( new InvitationService() )->silentLink( $invitation, $account );

        $this->assertFalse( $out['ok'] );
        $this->assertNotSame( '', (string) $out['error'], 'with a message a person can read' );
        $this->assertSame( [ $held ], $this->activePeopleFor( $account ) );
        $this->assertSame( 'active', $this->column( $invited, 'status' ), 'nothing was folded' );
        $this->assertSame(
            InvitationStatus::PENDING,
            (string) ( new InvitationsRepository() )->find( (int) $invitation->id )->status,
            'the invitation is left for an administrator'
        );
    }

    public function test_an_account_without_a_record_is_bound_to_the_invited_one(): void {
        $account = self::factory()->user->create( [ 'role' => 'subscriber' ] );
        $invited = $this->person( 'Sanne', 'de Boer', '', null );

        $out = ( new InvitationService() )->silentLink( $this->invitation( $invited ), $account );

        $this->assertTrue( $out['ok'], (string) ( $out['error'] ?? '' ) );
        $this->assertSame( [ $invited ], $this->activePeopleFor( $account ) );
    }

    private function person( string $first, string $last, string $phone, ?int $user_id ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_people", [
            'club_id'    => $this->club,
            'first_name' => $first,
            'last_name'  => $last,
            'phone'      => $phone,
            'wp_user_id' => $user_id,
            'role_type'  => 'coach',
            'status'     => 'active',
        ] );
        $id = (int) $wpdb->insert_id;
        $this->assertGreaterThan( 0, $id, 'fixture: the person was written' );
        return $id;
    }

    private function invitation( int $person_id ): object {
        $result = ( new InvitationService() )->create( [
            'kind'             => InvitationKind::STAFF,
            'target_person_id' => $person_id,
            'prefill_email'    => '',
        ] );
        $this->assertTrue( $result['ok'], (string) ( $result['error'] ?? '' ) );
        return ( new InvitationsRepository() )->find( (int) $result['id'] );
    }

    /** @return list<int> */
    private function activePeopleFor( int $user_id ): array {
        global $wpdb;
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM {$this->p}tt_people WHERE wp_user_id = %d AND club_id = %d AND status = 'active' ORDER BY id",
            $user_id, $this->club
        ) );
        return array_map( 'intval', (array) $ids );
    }

    private function column( int $person_id, string $column ): string {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$this->p}tt_people WHERE id = %d AND club_id = %d",
            $person_id, $this->club
        ), ARRAY_A );
        return (string) ( is_array( $row ) ? ( $row[ $column ] ?? '' ) : '' );
    }
}
