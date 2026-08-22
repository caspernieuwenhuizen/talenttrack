<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Config\ConfigService;
use TT\Modules\Alerts\Definitions\ParentNeverActivatedAlert;
use TT\Modules\Alerts\Definitions\PlayerTurns18Alert;
use TT\Modules\Alerts\Definitions\StaffCertificateExpiringAlert;
use TT\Modules\Alerts\Domain\AlertContext;
use TT\Modules\Alerts\Domain\Severity;

/**
 * #2636 instalment 3 — the three People definitions.
 *
 * Three negatives here matter more than any of the positives. A player who
 * is already an adult must not be told they are about to become one. A
 * parent invitation that somebody accepted, or that an admin made redundant
 * by linking the account directly, must not keep nagging — both leave a
 * stale `pending` row behind and neither is a problem. And a staff
 * certificate alert must reach the person it belongs to and nobody else:
 * this is somebody's own professional record.
 */
final class AlertsPeopleDefinitionsTest extends WP_UnitTestCase {

    /** @var string */
    private $p;

    /** @var int */
    private $club = 1;

    /** @var int */
    private $head;

    /** @var ConfigService */
    private $config;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->p      = $wpdb->prefix;
        $this->head   = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $this->config = new ConfigService();
    }

    // -- people.player_turns_18 ------------------------------------------

    public function test_player_turning_18_soon_alerts_the_head_coach(): void {
        $team   = $this->insertTeam();
        $this->assignHeadCoach( $team, $this->head );
        $player = $this->insertPlayer( $team, $this->birthdayIn( 10 ) );

        $out = ( new PlayerTurns18Alert() )->evaluate( new AlertContext( $this->club ) );

        $this->assertCount( 1, $out );
        $this->assertSame( $this->head, $out[0]->recipientUserId );
        $this->assertSame( 'player', $out[0]->subjectType );
        $this->assertSame( $player, $out[0]->playerId );
        $this->assertNotSame( '', $out[0]->title() );
    }

    public function test_player_turning_18_beyond_the_lead_time_produces_nothing(): void {
        $team = $this->insertTeam();
        $this->assignHeadCoach( $team, $this->head );
        $this->insertPlayer( $team, $this->birthdayIn( 90 ) );

        $this->assertSame( [], ( new PlayerTurns18Alert() )->evaluate( new AlertContext( $this->club ) ) );
    }

    /**
     * Somebody who is already an adult is not about to become one. Without
     * the lower bound of the window every senior player would carry this
     * alert forever.
     */
    public function test_player_who_is_already_18_produces_nothing(): void {
        $team = $this->insertTeam();
        $this->assignHeadCoach( $team, $this->head );
        $this->insertPlayer( $team, $this->birthdayIn( -30 ) );

        $this->assertSame( [], ( new PlayerTurns18Alert() )->evaluate( new AlertContext( $this->club ) ) );
    }

    public function test_player_without_a_date_of_birth_produces_nothing(): void {
        $team = $this->insertTeam();
        $this->assignHeadCoach( $team, $this->head );
        $this->insertPlayer( $team, null );

        $this->assertSame( [], ( new PlayerTurns18Alert() )->evaluate( new AlertContext( $this->club ) ) );
    }

    public function test_archived_player_turning_18_produces_nothing(): void {
        global $wpdb;
        $team   = $this->insertTeam();
        $this->assignHeadCoach( $team, $this->head );
        $player = $this->insertPlayer( $team, $this->birthdayIn( 10 ) );
        $wpdb->update( "{$this->p}tt_players", [ 'archived_at' => current_time( 'mysql' ) ], [ 'id' => $player ] );

        $this->assertSame( [], ( new PlayerTurns18Alert() )->evaluate( new AlertContext( $this->club ) ) );
    }

    public function test_birthday_severity_ages_up_inside_a_week(): void {
        $team = $this->insertTeam();
        $this->assignHeadCoach( $team, $this->head );
        $this->insertPlayer( $team, $this->birthdayIn( 3 ) );

        $out = ( new PlayerTurns18Alert() )->evaluate( new AlertContext( $this->club ) );
        $this->assertSame( Severity::URGENT, $out[0]->severity );
    }

    public function test_birthday_lead_time_comes_from_config_not_from_code(): void {
        $team = $this->insertTeam();
        $this->assignHeadCoach( $team, $this->head );
        $this->insertPlayer( $team, $this->birthdayIn( 50 ) );

        $this->assertSame( [], ( new PlayerTurns18Alert() )->evaluate( new AlertContext( $this->club ) ) );

        $this->config->set( PlayerTurns18Alert::CONFIG_KEY_LEAD_DAYS, '60' );

        $this->assertCount( 1, ( new PlayerTurns18Alert() )->evaluate( new AlertContext( $this->club ) ) );
    }

    // -- people.parent_never_activated ------------------------------------

    public function test_stale_parent_invitation_alerts_sender_and_head_coach(): void {
        $sender = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $team   = $this->insertTeam();
        $this->assignHeadCoach( $team, $this->head );
        $player = $this->insertPlayer( $team, $this->birthdayIn( 900 ) );
        $invite = $this->insertInvitation( 'parent', $player, $sender, 'pending', $this->daysAgo( 30 ) );

        $out = ( new ParentNeverActivatedAlert() )->evaluate( new AlertContext( $this->club ) );

        $this->assertCount( 2, $out );
        $recipients = array_map( static fn( $o ) => $o->recipientUserId, $out );
        sort( $recipients );
        $expected = [ $sender, $this->head ];
        sort( $expected );
        $this->assertSame( $expected, $recipients );

        $this->assertSame( 'invitation', $out[0]->subjectType );
        $this->assertSame( $invite, $out[0]->subjectId );
        $this->assertSame( $player, $out[0]->playerId );
    }

    public function test_expired_parent_invitation_still_counts_as_never_activated(): void {
        $team   = $this->insertTeam();
        $this->assignHeadCoach( $team, $this->head );
        $player = $this->insertPlayer( $team, $this->birthdayIn( 900 ) );
        $this->insertInvitation( 'parent', $player, $this->head, 'expired', $this->daysAgo( 60 ) );

        $this->assertCount( 1, ( new ParentNeverActivatedAlert() )->evaluate( new AlertContext( $this->club ) ) );
    }

    public function test_accepted_parent_invitation_produces_nothing(): void {
        global $wpdb;
        $team   = $this->insertTeam();
        $this->assignHeadCoach( $team, $this->head );
        $player = $this->insertPlayer( $team, $this->birthdayIn( 900 ) );
        $id     = $this->insertInvitation( 'parent', $player, $this->head, 'accepted', $this->daysAgo( 30 ) );
        $wpdb->update( "{$this->p}tt_invitations", [ 'accepted_at' => current_time( 'mysql' ) ], [ 'id' => $id ] );

        $this->assertSame( [], ( new ParentNeverActivatedAlert() )->evaluate( new AlertContext( $this->club ) ) );
    }

    public function test_revoked_parent_invitation_produces_nothing(): void {
        global $wpdb;
        $team   = $this->insertTeam();
        $this->assignHeadCoach( $team, $this->head );
        $player = $this->insertPlayer( $team, $this->birthdayIn( 900 ) );
        $id     = $this->insertInvitation( 'parent', $player, $this->head, 'revoked', $this->daysAgo( 30 ) );
        $wpdb->update( "{$this->p}tt_invitations", [ 'revoked_at' => current_time( 'mysql' ) ], [ 'id' => $id ] );

        $this->assertSame( [], ( new ParentNeverActivatedAlert() )->evaluate( new AlertContext( $this->club ) ) );
    }

    /**
     * A parent invited twice who accepted the other one, or linked directly
     * by an admin, leaves a stale pending row behind. Neither is a problem
     * and neither should keep nagging.
     */
    public function test_player_with_a_linked_parent_produces_nothing(): void {
        global $wpdb;
        $team   = $this->insertTeam();
        $this->assignHeadCoach( $team, $this->head );
        $player = $this->insertPlayer( $team, $this->birthdayIn( 900 ) );
        $this->insertInvitation( 'parent', $player, $this->head, 'pending', $this->daysAgo( 30 ) );
        $wpdb->insert( "{$this->p}tt_player_parents", [
            'club_id'        => $this->club,
            'player_id'      => $player,
            'parent_user_id' => self::factory()->user->create(),
            'is_primary'     => 1,
        ] );

        $this->assertSame( [], ( new ParentNeverActivatedAlert() )->evaluate( new AlertContext( $this->club ) ) );
    }

    public function test_recent_parent_invitation_produces_nothing(): void {
        $team   = $this->insertTeam();
        $this->assignHeadCoach( $team, $this->head );
        $player = $this->insertPlayer( $team, $this->birthdayIn( 900 ) );
        $this->insertInvitation( 'parent', $player, $this->head, 'pending', $this->daysAgo( 3 ) );

        $this->assertSame( [], ( new ParentNeverActivatedAlert() )->evaluate( new AlertContext( $this->club ) ) );
    }

    /**
     * The boundary with `onboarding.invitation_stale`, which owns player
     * and staff invitations. Two definitions covering the same row would
     * double-alert the same person.
     */
    public function test_player_invitation_is_not_this_definitions_business(): void {
        $team   = $this->insertTeam();
        $this->assignHeadCoach( $team, $this->head );
        $player = $this->insertPlayer( $team, $this->birthdayIn( 900 ) );
        $this->insertInvitation( 'player', $player, $this->head, 'pending', $this->daysAgo( 30 ) );

        $this->assertSame( [], ( new ParentNeverActivatedAlert() )->evaluate( new AlertContext( $this->club ) ) );
    }

    // -- people.staff_certificate_expiring --------------------------------

    public function test_expiring_certificate_alerts_the_person_it_belongs_to(): void {
        $staff  = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $person = $this->insertPerson( $staff );
        $cert   = $this->insertCertification( $person, $this->daysAhead( 30 ) );

        $out = ( new StaffCertificateExpiringAlert() )->evaluate( new AlertContext( $this->club ) );

        $this->assertCount( 1, $out );
        $this->assertSame( $staff, $out[0]->recipientUserId );
        $this->assertSame( 'staff_certification', $out[0]->subjectType );
        $this->assertSame( $cert, $out[0]->subjectId );
        $this->assertNull( $out[0]->playerId );
        $this->assertNotSame( '', $out[0]->title() );
    }

    /**
     * Dropping already-expired certificates would make the alert vanish at
     * exactly the moment the problem becomes real.
     */
    public function test_recently_expired_certificate_is_urgent(): void {
        $person = $this->insertPerson( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        $this->insertCertification( $person, $this->daysAgo( 5 ) );

        $out = ( new StaffCertificateExpiringAlert() )->evaluate( new AlertContext( $this->club ) );

        $this->assertCount( 1, $out );
        $this->assertSame( Severity::URGENT, $out[0]->severity );
    }

    public function test_certificate_far_in_the_future_produces_nothing(): void {
        $person = $this->insertPerson( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        $this->insertCertification( $person, $this->daysAhead( 200 ) );

        $this->assertSame( [], ( new StaffCertificateExpiringAlert() )->evaluate( new AlertContext( $this->club ) ) );
    }

    public function test_long_lapsed_certificate_produces_nothing(): void {
        $person = $this->insertPerson( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        $this->insertCertification( $person, $this->daysAgo( 200 ) );

        $this->assertSame( [], ( new StaffCertificateExpiringAlert() )->evaluate( new AlertContext( $this->club ) ) );
    }

    public function test_certificate_with_no_expiry_produces_nothing(): void {
        $person = $this->insertPerson( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        $this->insertCertification( $person, null );

        $this->assertSame( [], ( new StaffCertificateExpiringAlert() )->evaluate( new AlertContext( $this->club ) ) );
    }

    public function test_archived_certificate_produces_nothing(): void {
        global $wpdb;
        $person = $this->insertPerson( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        $cert   = $this->insertCertification( $person, $this->daysAhead( 30 ) );
        $wpdb->update( "{$this->p}tt_staff_certifications", [ 'archived_at' => current_time( 'mysql' ) ], [ 'id' => $cert ] );

        $this->assertSame( [], ( new StaffCertificateExpiringAlert() )->evaluate( new AlertContext( $this->club ) ) );
    }

    /**
     * No linked account means nobody to tell. Routing it to an
     * administrator instead would turn somebody's own professional record
     * into a third party's notification.
     */
    public function test_certificate_of_a_person_without_an_account_produces_nothing(): void {
        $person = $this->insertPerson( 0 );
        $this->insertCertification( $person, $this->daysAhead( 30 ) );

        $this->assertSame( [], ( new StaffCertificateExpiringAlert() )->evaluate( new AlertContext( $this->club ) ) );
    }

    public function test_certificate_of_an_archived_person_produces_nothing(): void {
        global $wpdb;
        $person = $this->insertPerson( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        $this->insertCertification( $person, $this->daysAhead( 30 ) );
        $wpdb->update( "{$this->p}tt_people", [ 'archived_at' => current_time( 'mysql' ) ], [ 'id' => $person ] );

        $this->assertSame( [], ( new StaffCertificateExpiringAlert() )->evaluate( new AlertContext( $this->club ) ) );
    }

    public function test_certificate_window_comes_from_config_not_from_code(): void {
        $person = $this->insertPerson( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        $this->insertCertification( $person, $this->daysAhead( 120 ) );

        $this->assertSame( [], ( new StaffCertificateExpiringAlert() )->evaluate( new AlertContext( $this->club ) ) );

        $this->config->set( StaffCertificateExpiringAlert::CONFIG_KEY_WINDOW_DAYS, '150' );

        $this->assertCount( 1, ( new StaffCertificateExpiringAlert() )->evaluate( new AlertContext( $this->club ) ) );
    }

    // -- fixtures --------------------------------------------------------

    private function daysAgo( int $n ): string {
        return gmdate( 'Y-m-d', current_time( 'timestamp' ) - $n * DAY_IN_SECONDS );
    }

    private function daysAhead( int $n ): string {
        return gmdate( 'Y-m-d', current_time( 'timestamp' ) + $n * DAY_IN_SECONDS );
    }

    /** A date of birth whose eighteenth anniversary falls `$n` days from today. */
    private function birthdayIn( int $n ): string {
        $target = gmdate( 'Y-m-d', current_time( 'timestamp' ) + $n * DAY_IN_SECONDS );
        return gmdate( 'Y-m-d', (int) strtotime( $target . ' -18 years' ) );
    }

    private function insertTeam(): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_teams", [ 'club_id' => $this->club, 'name' => 'U18 alerts' ] );
        return (int) $wpdb->insert_id;
    }

    private function insertPlayer( int $team_id, ?string $dob ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_players", [
            'club_id'       => $this->club,
            'team_id'       => $team_id,
            'first_name'    => 'Alert',
            'last_name'     => 'Fixture',
            'status'        => 'active',
            'date_joined'   => $this->daysAgo( 400 ),
            'date_of_birth' => $dob,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function insertInvitation( string $kind, int $player_id, int $created_by, string $status, string $created_on ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_invitations", [
            'club_id'          => $this->club,
            'token'            => wp_generate_password( 32, false ),
            'kind'             => $kind,
            'target_player_id' => $player_id,
            'created_by'       => $created_by,
            'created_at'       => $created_on . ' 09:00:00',
            'expires_at'       => $this->daysAhead( 7 ) . ' 09:00:00',
            'status'           => $status,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function insertPerson( int $wp_user_id ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_people", [
            'club_id'    => $this->club,
            'first_name' => 'Staff',
            'last_name'  => 'Fixture',
            'role_type'  => 'coach',
            'status'     => 'active',
            'wp_user_id' => $wp_user_id,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function insertCertification( int $person_id, ?string $expires_on ): int {
        global $wpdb;

        $wpdb->insert( "{$this->p}tt_lookups", [
            'club_id'     => $this->club,
            'lookup_type' => 'cert_type',
            'name'        => 'UEFA B',
        ] );
        $lookup_id = (int) $wpdb->insert_id;

        $wpdb->insert( "{$this->p}tt_staff_certifications", [
            'club_id'             => $this->club,
            'person_id'           => $person_id,
            'cert_type_lookup_id' => $lookup_id,
            'issuer'              => 'KNVB',
            'issued_on'           => $this->daysAgo( 700 ),
            'expires_on'          => $expires_on,
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
