<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Alerts\AlertEvaluator;
use TT\Modules\Alerts\Definitions\NoGuardianContactAlert;
use TT\Modules\Alerts\Domain\AlertContext;
use TT\Modules\Comms\Channel\Adapters\EmailChannelAdapter;
use TT\Modules\Comms\Channel\ChannelAdapterRegistry;
use TT\Modules\Comms\Domain\CommsResult;
use TT\Modules\Comms\Send\TrialPlayerWelcomeSend;
use TT\Modules\Comms\Template\TemplateRegistry;
use TT\Modules\Comms\Template\TemplateSwitch;
use TT\Modules\Comms\Templates\TrialPlayerWelcomeTemplate;
use TT\Modules\DemoData\DemoGenerationContext;
use TT\Modules\Invitations\PlayerParentsRepository;

/**
 * #3576 — a message that reached nobody says who it was about, the demo
 * generator stops filling the error log, and a player nobody at home can be
 * reached about gets an alert of their own.
 *
 * The error log held fifty identical "Comms send resolved to zero
 * recipients" warnings written in two seconds, each naming only a template
 * key. An admin could not tell which families were never welcomed, and the
 * burst pushed real errors off the first page.
 */
final class CommsZeroRecipientSubjectTest extends WP_UnitTestCase {

    private string $p;
    private int $club;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->p    = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();

        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        TemplateRegistry::clear();
        ChannelAdapterRegistry::clear();
        TemplateRegistry::register( new TrialPlayerWelcomeTemplate() );
        ChannelAdapterRegistry::register( new EmailChannelAdapter() );
        QueryHelpers::set_config( TemplateSwitch::CONFIG_KEY, '' );

        $wpdb->query( "DELETE FROM {$this->p}tt_error_log" );
        $wpdb->query( "DELETE FROM {$this->p}tt_comms_log" );
    }

    public function tear_down(): void {
        TemplateRegistry::clear();
        ChannelAdapterRegistry::clear();
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    // ── the warning names its subject ─────────────────────────────────

    public function test_a_welcome_that_reached_nobody_names_the_player_and_the_case(): void {
        $player = $this->insertPlayer();
        $case   = $this->insertCase( $player );

        $results = TrialPlayerWelcomeSend::send( $case );
        $this->assertSame( CommsResult::STATUS_NO_RECIPIENTS, $results[0]->status );

        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        $res = rest_do_request( new WP_REST_Request( 'GET', '/talenttrack/v1/system/errors' ) );
        $this->assertSame( 200, $res->get_status() );

        $warnings = array_values( array_filter(
            (array) $res->get_data()['data'],
            static fn( $row ): bool => ( $row['message'] ?? '' ) === 'Comms send resolved to zero recipients'
        ) );
        $this->assertCount( 1, $warnings, 'exactly one warning for one send' );
        $this->assertSame( $player, (int) ( $warnings[0]['context']['player_id'] ?? 0 ) );
        $this->assertSame( 'trial_case', $warnings[0]['context']['subject_type'] ?? null );
        $this->assertSame( $case, (int) ( $warnings[0]['context']['subject_id'] ?? 0 ) );

        global $wpdb;
        $this->assertSame(
            (string) $player,
            (string) $wpdb->get_var( "SELECT recipient_player_id FROM {$this->p}tt_comms_log ORDER BY id DESC LIMIT 1" ),
            'the audit row says whose family was missing'
        );
    }

    public function test_a_switched_off_template_is_not_warned_about(): void {
        QueryHelpers::set_config( TemplateSwitch::CONFIG_KEY, TrialPlayerWelcomeTemplate::KEY );

        $results = TrialPlayerWelcomeSend::send( $this->insertCase( $this->insertPlayer() ) );

        $this->assertSame( CommsResult::STATUS_TEMPLATE_DISABLED, $results[0]->status );
        $this->assertSame( 0, $this->zeroRecipientWarnings() );
    }

    public function test_demo_generation_sends_nothing_and_warns_about_nothing(): void {
        $case = $this->insertCase( $this->insertPlayer() );

        DemoGenerationContext::begin();
        try {
            $results = TrialPlayerWelcomeSend::send( $case );
        } finally {
            DemoGenerationContext::end();
        }

        $this->assertSame( [], $results );
        $this->assertSame( 0, $this->zeroRecipientWarnings() );
        $this->assertFalse( DemoGenerationContext::isActive(), 'the flag does not outlive the generator' );
    }

    public function test_a_family_that_can_be_reached_is_still_welcomed(): void {
        $player = $this->insertPlayer();
        ( new PlayerParentsRepository() )->link( $player, self::factory()->user->create( [ 'user_email' => 'mum@example.test' ] ), true );

        $results = TrialPlayerWelcomeSend::send( $this->insertCase( $player ) );

        $this->assertNotSame( CommsResult::STATUS_NO_RECIPIENTS, $results[0]->status );
        $this->assertSame( 0, $this->zeroRecipientWarnings() );
    }

    // ── the alert ─────────────────────────────────────────────────────

    public function test_a_player_nobody_can_reach_gets_one_occurrence_until_somebody_can(): void {
        global $wpdb;
        [ $team, $coach ] = $this->teamWithHeadCoach();
        $player           = $this->insertPlayer( 'active', $team );

        $this->assertContains( $player, $this->alertedPlayers() );
        $for_coach = array_filter(
            ( new NoGuardianContactAlert() )->evaluate( new AlertContext( $this->club ) ),
            static fn( $o ): bool => $o->recipientUserId === $coach && (int) $o->playerId === $player
        );
        $this->assertCount( 1, $for_coach, 'the head coach hears about it, once' );

        $wpdb->update( "{$this->p}tt_players", [ 'guardian_email' => 'home@example.test' ], [ 'id' => $player ] );
        $this->assertNotContains( $player, $this->alertedPlayers(), 'a guardian email resolves it' );

        $wpdb->update( "{$this->p}tt_players", [ 'guardian_email' => '' ], [ 'id' => $player ] );
        ( new PlayerParentsRepository() )->link( $player, self::factory()->user->create(), true );
        $this->assertNotContains( $player, $this->alertedPlayers(), 'a linked parent resolves it' );
    }

    public function test_a_player_whose_parent_was_invited_is_left_to_the_invitation_alert(): void {
        [ $team ] = $this->teamWithHeadCoach();

        $pending = $this->insertPlayer( 'active', $team );
        $this->insertParentInvitation( $pending, 'pending', gmdate( 'Y-m-d H:i:s', strtotime( '-2 days' ) ) );

        $expired = $this->insertPlayer( 'active', $team );
        $this->insertParentInvitation( $expired, 'expired', gmdate( 'Y-m-d H:i:s', strtotime( '-30 days' ) ) );

        $alerted = $this->alertedPlayers();
        $this->assertNotContains( $pending, $alerted, 'a sent, pending invitation is the invitation alert\'s' );
        $this->assertNotContains( $expired, $alerted, 'a sent, expired invitation is the invitation alert\'s' );
    }

    /**
     * #3657 — an invitation that was created but never mailed did not ask
     * the family anything. Once it expired, none of the three related
     * alerts reported the player.
     */
    public function test_a_parent_invitation_that_was_never_sent_does_not_hide_the_player(): void {
        [ $team ] = $this->teamWithHeadCoach();

        $held_expired = $this->insertPlayer( 'active', $team );
        $this->insertParentInvitation( $held_expired, 'expired', null );

        $held_pending = $this->insertPlayer( 'active', $team );
        $this->insertParentInvitation( $held_pending, 'pending', null );

        $alerted = $this->alertedPlayers();
        $this->assertContains( $held_expired, $alerted, 'an expired invitation nobody sent hides nothing' );
        $this->assertContains( $held_pending, $alerted, 'a held invitation nobody sent hides nothing' );
    }

    public function test_the_alerts_endpoint_lists_a_player_whose_only_invitation_was_never_sent(): void {
        [ $team, $coach ] = $this->teamWithHeadCoach();
        $player           = $this->insertPlayer( 'active', $team );
        $this->insertParentInvitation( $player, 'expired', null );

        ( new AlertEvaluator() )->run( new NoGuardianContactAlert(), new AlertContext( $this->club ) );

        wp_set_current_user( $coach );
        $request = new WP_REST_Request( 'GET', '/talenttrack/v1/alerts' );
        $request->set_param( 'state', 'open' );
        $request->set_param( 'player_id', $player );
        $response = rest_do_request( $request );
        $this->assertSame( 200, $response->get_status() );

        $body = $response->get_data();
        $rows = is_array( $body ) && is_array( $body['data'] ?? null ) ? $body['data'] : [];
        $keys = array_map( static fn( $row ): string => (string) ( $row['alert_key'] ?? '' ), $rows );
        $this->assertContains( 'people.no_guardian_contact', $keys, 'the open alerts list names the player nobody at home was asked about' );
    }

    // ── fixtures ──────────────────────────────────────────────────────

    private function insertPlayer( string $status = 'trial', int $team = 0 ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_players", [
            'club_id'    => $this->club,
            'team_id'    => $team,
            'first_name' => 'Sem',
            'last_name'  => 'de Vries',
            'status'     => $status,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function insertParentInvitation( int $player_id, string $status, ?string $sent_at ): void {
        global $wpdb;
        $row = [
            'club_id'          => $this->club,
            'kind'             => 'parent',
            'target_player_id' => $player_id,
            'status'           => $status,
            'token'            => wp_generate_password( 32, false ),
            'created_by'       => 1,
            'expires_at'       => gmdate( 'Y-m-d H:i:s', strtotime( $status === 'expired' ? '-1 day' : '+14 days' ) ),
        ];
        if ( $sent_at !== null ) $row['sent_at'] = $sent_at;
        $wpdb->insert( "{$this->p}tt_invitations", $row );
    }

    private function insertCase( int $player_id ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_trial_tracks", [ 'club_id' => $this->club, 'name' => 'Standard trial' ] );
        $track = (int) $wpdb->insert_id;
        $wpdb->insert( "{$this->p}tt_trial_cases", [
            'club_id'    => $this->club,
            'player_id'  => $player_id,
            'track_id'   => $track,
            'start_date' => '2026-09-14',
            'end_date'   => '2026-10-14',
            'status'     => 'open',
            'uuid'       => wp_generate_uuid4(),
            'created_by' => 1,
        ] );
        return (int) $wpdb->insert_id;
    }

    /** @return array{0:int,1:int} team id, head coach user id */
    private function teamWithHeadCoach(): array {
        global $wpdb;
        $coach = self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $wpdb->insert( "{$this->p}tt_teams", [ 'club_id' => $this->club, 'name' => 'Hedel O11-1' ] );
        $team = (int) $wpdb->insert_id;
        $wpdb->insert( "{$this->p}tt_people", [
            'club_id' => $this->club, 'first_name' => 'Head', 'last_name' => 'Coach',
            'role_type' => 'head_coach', 'wp_user_id' => $coach, 'status' => 'active',
        ] );
        $person = (int) $wpdb->insert_id;

        // The head coach is the `head_coach` functional role on the team.
        $role = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$this->p}tt_functional_roles WHERE role_key = %s AND club_id = %d",
            'head_coach', $this->club
        ) );
        if ( $role <= 0 ) {
            $wpdb->insert( "{$this->p}tt_functional_roles", [
                'club_id' => $this->club, 'role_key' => 'head_coach', 'label' => 'Head coach',
                'description' => 'Created by CommsZeroRecipientSubjectTest.', 'is_system' => 1, 'sort_order' => 10,
            ] );
            $role = (int) $wpdb->insert_id;
        }
        $wpdb->insert( "{$this->p}tt_team_people", [
            'club_id' => $this->club, 'team_id' => $team, 'person_id' => $person,
            'functional_role_id' => $role, 'role_in_team' => 'head_coach',
        ] );
        return [ $team, $coach ];
    }

    /** @return list<int> */
    private function alertedPlayers(): array {
        $ids = [];
        foreach ( ( new NoGuardianContactAlert() )->evaluate( new AlertContext( $this->club ) ) as $occurrence ) {
            $ids[ (int) $occurrence->playerId ] = true;
        }
        return array_keys( $ids );
    }

    private function zeroRecipientWarnings(): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->p}tt_error_log WHERE message = %s",
            'Comms send resolved to zero recipients'
        ) );
    }
}
