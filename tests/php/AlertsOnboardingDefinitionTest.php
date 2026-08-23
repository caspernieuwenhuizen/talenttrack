<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Config\ConfigService;
use TT\Modules\Alerts\Definitions\InvitationStaleAlert;
use TT\Modules\Alerts\Domain\AlertContext;
use TT\Modules\Alerts\Domain\Severity;

/**
 * #2636 instalment 6 — `onboarding.invitation_stale`.
 *
 * The negatives here are all versions of the same mistake: chasing an
 * invitation that the system has already made redundant. An account created
 * directly by an admin, an acceptance that happened, a revocation — each
 * leaves a `pending` row behind, and none of them is a problem. So does the
 * parent invitation, which belongs to a different definition entirely.
 */
final class AlertsOnboardingDefinitionTest extends WP_UnitTestCase {

    /** @var string */
    private $p;

    /** @var int */
    private $club = 1;

    /** @var int */
    private $sender;

    /** @var ConfigService */
    private $config;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->p      = $wpdb->prefix;
        $this->sender = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $this->config = new ConfigService();
    }

    public function test_stale_player_invitation_alerts_the_sender(): void {
        $team   = $this->insertTeam();
        $player = $this->insertPlayer( $team, 0 );
        $invite = $this->insertInvitation( 'player', $player, 0, 'pending', $this->daysAgo( 30 ) );

        $out = ( new InvitationStaleAlert() )->evaluate( new AlertContext( $this->club ) );

        $this->assertCount( 1, $out );
        $this->assertSame( $this->sender, $out[0]->recipientUserId );
        $this->assertSame( 'invitation', $out[0]->subjectType );
        $this->assertSame( $invite, $out[0]->subjectId );
        $this->assertSame( $player, $out[0]->playerId );
        $this->assertNotSame( '', $out[0]->title() );
    }

    public function test_stale_player_invitation_also_reaches_the_head_coach(): void {
        $head   = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $team   = $this->insertTeam();
        $this->assignHeadCoach( $team, $head );
        $player = $this->insertPlayer( $team, 0 );
        $this->insertInvitation( 'player', $player, 0, 'pending', $this->daysAgo( 30 ) );

        $out        = ( new InvitationStaleAlert() )->evaluate( new AlertContext( $this->club ) );
        $recipients = array_map( static fn( $o ) => $o->recipientUserId, $out );
        sort( $recipients );
        $expected = [ $this->sender, $head ];
        sort( $expected );

        $this->assertSame( $expected, $recipients );
    }

    /**
     * A staff invitation has no player and no team, so the sender is the
     * whole audience. Nothing about the row should make the base class
     * describe the invitee as "this player".
     */
    public function test_stale_staff_invitation_alerts_the_sender_only(): void {
        $person = $this->insertPerson( 0 );
        $this->insertStaffInvitation( $person, 'pending', $this->daysAgo( 30 ) );

        $out = ( new InvitationStaleAlert() )->evaluate( new AlertContext( $this->club ) );

        $this->assertCount( 1, $out );
        $this->assertSame( $this->sender, $out[0]->recipientUserId );
        $this->assertNull( $out[0]->playerId );
        $this->assertStringContainsString( 'Nieuwe Trainer', $out[0]->title() );
    }

    public function test_recent_invitation_produces_nothing(): void {
        $team   = $this->insertTeam();
        $player = $this->insertPlayer( $team, 0 );
        $this->insertInvitation( 'player', $player, 0, 'pending', $this->daysAgo( 3 ) );

        $this->assertSame( [], ( new InvitationStaleAlert() )->evaluate( new AlertContext( $this->club ) ) );
    }

    public function test_accepted_invitation_produces_nothing(): void {
        global $wpdb;
        $team   = $this->insertTeam();
        $player = $this->insertPlayer( $team, 0 );
        $id     = $this->insertInvitation( 'player', $player, 0, 'accepted', $this->daysAgo( 30 ) );
        $wpdb->update( "{$this->p}tt_invitations", [ 'accepted_at' => current_time( 'mysql' ) ], [ 'id' => $id ] );

        $this->assertSame( [], ( new InvitationStaleAlert() )->evaluate( new AlertContext( $this->club ) ) );
    }

    public function test_revoked_invitation_produces_nothing(): void {
        global $wpdb;
        $team   = $this->insertTeam();
        $player = $this->insertPlayer( $team, 0 );
        $id     = $this->insertInvitation( 'player', $player, 0, 'revoked', $this->daysAgo( 30 ) );
        $wpdb->update( "{$this->p}tt_invitations", [ 'revoked_at' => current_time( 'mysql' ) ], [ 'id' => $id ] );

        $this->assertSame( [], ( new InvitationStaleAlert() )->evaluate( new AlertContext( $this->club ) ) );
    }

    /**
     * An admin who created the account directly leaves the invitation
     * sitting at `pending`. Chasing it would be chasing something already
     * done.
     */
    public function test_player_who_already_has_an_account_produces_nothing(): void {
        $team   = $this->insertTeam();
        $player = $this->insertPlayer( $team, self::factory()->user->create() );
        $this->insertInvitation( 'player', $player, 0, 'pending', $this->daysAgo( 30 ) );

        $this->assertSame( [], ( new InvitationStaleAlert() )->evaluate( new AlertContext( $this->club ) ) );
    }

    public function test_staff_member_who_already_has_an_account_produces_nothing(): void {
        $person = $this->insertPerson( self::factory()->user->create() );
        $this->insertStaffInvitation( $person, 'pending', $this->daysAgo( 30 ) );

        $this->assertSame( [], ( new InvitationStaleAlert() )->evaluate( new AlertContext( $this->club ) ) );
    }

    public function test_invitation_for_an_archived_player_produces_nothing(): void {
        global $wpdb;
        $team   = $this->insertTeam();
        $player = $this->insertPlayer( $team, 0 );
        $this->insertInvitation( 'player', $player, 0, 'pending', $this->daysAgo( 30 ) );
        $wpdb->update( "{$this->p}tt_players", [ 'archived_at' => current_time( 'mysql' ) ], [ 'id' => $player ] );

        $this->assertSame( [], ( new InvitationStaleAlert() )->evaluate( new AlertContext( $this->club ) ) );
    }

    /**
     * The boundary with `people.parent_never_activated`, which owns parent
     * invitations. Two definitions covering the same row would tell the
     * same person the same thing twice.
     */
    public function test_parent_invitation_is_not_this_definitions_business(): void {
        $team   = $this->insertTeam();
        $player = $this->insertPlayer( $team, 0 );
        $this->insertInvitation( 'parent', $player, 0, 'pending', $this->daysAgo( 30 ) );

        $this->assertSame( [], ( new InvitationStaleAlert() )->evaluate( new AlertContext( $this->club ) ) );
    }

    public function test_expired_invitation_still_counts(): void {
        $team   = $this->insertTeam();
        $player = $this->insertPlayer( $team, 0 );
        $this->insertInvitation( 'player', $player, 0, 'expired', $this->daysAgo( 60 ) );

        $this->assertCount( 1, ( new InvitationStaleAlert() )->evaluate( new AlertContext( $this->club ) ) );
    }

    public function test_severity_ages_up_at_twice_the_threshold(): void {
        $team   = $this->insertTeam();
        $player = $this->insertPlayer( $team, 0 );
        $this->insertInvitation( 'player', $player, 0, 'pending', $this->daysAgo( 60 ) );

        $out = ( new InvitationStaleAlert() )->evaluate( new AlertContext( $this->club ) );
        $this->assertSame( Severity::URGENT, $out[0]->severity );
    }

    public function test_threshold_comes_from_config_not_from_code(): void {
        $team   = $this->insertTeam();
        $player = $this->insertPlayer( $team, 0 );
        $this->insertInvitation( 'player', $player, 0, 'pending', $this->daysAgo( 5 ) );

        $this->assertSame( [], ( new InvitationStaleAlert() )->evaluate( new AlertContext( $this->club ) ) );

        $this->config->set( InvitationStaleAlert::CONFIG_KEY_STALE_DAYS, '3' );

        $this->assertCount( 1, ( new InvitationStaleAlert() )->evaluate( new AlertContext( $this->club ) ) );
    }

    // -- fixtures --------------------------------------------------------

    private function daysAgo( int $n ): string {
        return gmdate( 'Y-m-d', current_time( 'timestamp' ) - $n * DAY_IN_SECONDS );
    }

    private function daysAhead( int $n ): string {
        return gmdate( 'Y-m-d', current_time( 'timestamp' ) + $n * DAY_IN_SECONDS );
    }

    private function insertTeam(): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_teams", [ 'club_id' => $this->club, 'name' => 'U11 alerts' ] );
        return (int) $wpdb->insert_id;
    }

    private function insertPlayer( int $team_id, int $wp_user_id ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_players", [
            'club_id'     => $this->club,
            'team_id'     => $team_id,
            'first_name'  => 'Alert',
            'last_name'   => 'Fixture',
            'status'      => 'active',
            'date_joined' => $this->daysAgo( 400 ),
            'wp_user_id'  => $wp_user_id > 0 ? $wp_user_id : null,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function insertPerson( int $wp_user_id ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_people", [
            'club_id'    => $this->club,
            'first_name' => 'Nieuwe',
            'last_name'  => 'Trainer',
            'role_type'  => 'coach',
            'status'     => 'active',
            'wp_user_id' => $wp_user_id > 0 ? $wp_user_id : null,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function insertInvitation( string $kind, int $player_id, int $person_id, string $status, string $created_on ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_invitations", [
            'club_id'          => $this->club,
            'token'            => wp_generate_password( 32, false ),
            'kind'             => $kind,
            'target_player_id' => $player_id > 0 ? $player_id : null,
            'target_person_id' => $person_id > 0 ? $person_id : null,
            'created_by'       => $this->sender,
            'created_at'       => $created_on . ' 09:00:00',
            'expires_at'       => $this->daysAhead( 7 ) . ' 09:00:00',
            'status'           => $status,
        ] );
        return (int) $wpdb->insert_id;
    }

    /**
     * A staff invitation carries the prefilled name the sender typed and no
     * player at all — the shape the title resolver has to handle without
     * calling the invitee "this player".
     */
    private function insertStaffInvitation( int $person_id, string $status, string $created_on ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_invitations", [
            'club_id'             => $this->club,
            'token'               => wp_generate_password( 32, false ),
            'kind'                => 'staff',
            'target_person_id'    => $person_id,
            'prefill_first_name'  => 'Nieuwe',
            'prefill_last_name'   => 'Trainer',
            'prefill_email'       => 'trainer@example.test',
            'created_by'          => $this->sender,
            'created_at'          => $created_on . ' 09:00:00',
            'expires_at'          => $this->daysAhead( 7 ) . ' 09:00:00',
            'status'              => $status,
        ] );
        return (int) $wpdb->insert_id;
    }

    /**
     * Head-coach assignment through `tt_team_people`, the single source of
     * truth since #1315 retired `tt_teams.head_coach_id`.
     */
    private function assignHeadCoach( int $team_id, int $user_id ): void {
        global $wpdb;

        $role_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$this->p}tt_functional_roles WHERE role_key = %s LIMIT 1",
            'head_coach'
        ) );
        if ( $role_id <= 0 ) {
            $wpdb->insert( "{$this->p}tt_functional_roles", [
                'club_id'  => $this->club,
                'role_key' => 'head_coach',
                'label'    => 'Head Coach',
            ] );
            $role_id = (int) $wpdb->insert_id;
        }

        $person_id = $this->insertPerson( $user_id );

        $wpdb->insert( "{$this->p}tt_team_people", [
            'club_id'            => $this->club,
            'team_id'            => $team_id,
            'person_id'          => $person_id,
            'functional_role_id' => $role_id,
        ] );
    }
}
