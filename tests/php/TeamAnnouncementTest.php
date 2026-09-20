<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_UnitTestCase;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Comms\Channel\ChannelAdapterInterface;
use TT\Modules\Comms\Channel\ChannelAdapterRegistry;
use TT\Modules\Comms\Domain\CommsRequest;
use TT\Modules\Comms\Domain\CommsResult;
use TT\Modules\Comms\Domain\MessageType;
use TT\Modules\Comms\Domain\Recipient;
use TT\Modules\Comms\OptOut\OptOutPolicy;
use TT\Modules\Comms\Send\MassAnnouncementSender;
use TT\Modules\Comms\Template\TemplateRegistry;
use TT\Modules\Comms\Template\TemplateSwitch;
use TT\Modules\Comms\Templates\MassAnnouncementTemplate;
use TT\Modules\Invitations\PlayerParentsRepository;
use TT\Modules\Wizards\TeamAnnouncement\NewTeamAnnouncementWizard;
use TT\Shared\Wizards\WizardRegistry;

/**
 * #3693 — team announcements.
 *
 * `mass_announcement` had a message type, shipped copy, a catalog entry
 * and an opt-out row on *My settings* before anything could construct
 * one, which made that preference a switch for mail nobody received.
 *
 * Two things are worth pinning. The first is the audience rule, and it
 * is pinned **at the route**: a team manager who posts another team's id
 * must be refused, because a dropdown that does not offer the option is
 * not the same as a system that will not do it. The second is that an
 * announcement is an ordinary message — refusable, and held by quiet
 * hours — which is the whole difference between it and the safeguarding
 * broadcast it is modelled on.
 */
final class TeamAnnouncementTest extends WP_UnitTestCase {

    private string $log;
    private int $admin;
    private int $coach_a;
    private int $head_dev;
    private int $parent;
    private int $team_a;
    private int $team_b;

    /** @var array<string,int> */
    private array $players = [];

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->log = $wpdb->prefix . 'tt_comms_log';
        $wpdb->query( "DELETE FROM {$this->log}" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}tt_comms_optouts" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}tt_player_parents" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}tt_players" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}tt_user_role_scopes" );

        ( new RolesService() )->installRoles();
        $this->admin = (int) self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $this->admin );

        TemplateRegistry::clear();
        ChannelAdapterRegistry::clear();
        TemplateRegistry::register( new MassAnnouncementTemplate() );
        ChannelAdapterRegistry::register( new AnnouncementSpyAdapter() );
        QueryHelpers::set_config( TemplateSwitch::CONFIG_KEY, '' );
        // A zero-width window, so quiet hours cannot decide the outcome
        // of tests that are about the audience. CI runs at whatever hour
        // it runs at, and the default window is 21:00-07:00.
        QueryHelpers::set_config( 'comms_quiet_hours_start', '00:00' );
        QueryHelpers::set_config( 'comms_quiet_hours_end', '00:00' );

        // The registry is populated by WizardsModule at boot; register
        // explicitly so the test does not depend on module boot order.
        WizardRegistry::register( new NewTeamAnnouncementWizard() );

        $this->seedAcademy();
        do_action( 'rest_api_init' );
    }

    public function tear_down(): void {
        TemplateRegistry::clear();
        ChannelAdapterRegistry::clear();
        OptOutPolicy::flushTableCache();
        parent::tear_down();
    }

    /* ---- who may reach whom ---------------------------------------------- */

    public function test_a_coach_may_announce_to_a_team_they_hold(): void {
        $this->assertTrue(
            ( new MassAnnouncementSender() )->canSend( $this->coach_a, MassAnnouncementSender::SCOPE_TEAM, $this->team_a )
        );
    }

    public function test_a_coach_may_not_announce_to_a_team_they_do_not_hold(): void {
        $sender = new MassAnnouncementSender();

        $this->assertFalse( $sender->canSend( $this->coach_a, MassAnnouncementSender::SCOPE_TEAM, $this->team_b ) );
        $this->assertFalse( $sender->canSend( $this->coach_a, MassAnnouncementSender::SCOPE_ACADEMY ) );
        $this->assertFalse( $sender->canSend( $this->coach_a, MassAnnouncementSender::SCOPE_AGE_GROUP, 0, 'O15' ) );
    }

    public function test_an_academy_sender_may_reach_any_team_an_age_group_or_everybody(): void {
        $sender = new MassAnnouncementSender();

        $this->assertTrue( $sender->canSend( $this->head_dev, MassAnnouncementSender::SCOPE_TEAM, $this->team_a ) );
        $this->assertTrue( $sender->canSend( $this->head_dev, MassAnnouncementSender::SCOPE_TEAM, $this->team_b ) );
        $this->assertTrue( $sender->canSend( $this->head_dev, MassAnnouncementSender::SCOPE_AGE_GROUP, 0, 'O13' ) );
        $this->assertTrue( $sender->canSend( $this->head_dev, MassAnnouncementSender::SCOPE_ACADEMY ) );
    }

    public function test_an_age_group_nobody_plays_in_is_not_an_audience(): void {
        $this->assertFalse(
            ( new MassAnnouncementSender() )->canSend( $this->head_dev, MassAnnouncementSender::SCOPE_AGE_GROUP, 0, 'O19' )
        );
    }

    /**
     * The capability shape, pinned. Coach and staff announce to their own
     * squads; Head of Development and academy admin speak for the academy;
     * a scout and a read-only observer do neither.
     */
    public function test_the_default_grants(): void {
        foreach ( [ 'tt_coach', 'tt_staff' ] as $role ) {
            $user = (int) self::factory()->user->create( [ 'role' => $role ] );
            $this->assertTrue( user_can( $user, MassAnnouncementSender::CAP_TEAM ), "{$role} may announce to its own teams" );
            $this->assertFalse( user_can( $user, MassAnnouncementSender::CAP_ACADEMY ), "{$role} may not speak for the academy" );
        }

        foreach ( [ 'administrator', 'tt_head_dev', 'tt_club_admin' ] as $role ) {
            $user = (int) self::factory()->user->create( [ 'role' => $role ] );
            $this->assertTrue( user_can( $user, MassAnnouncementSender::CAP_ACADEMY ), "{$role} may announce academy-wide" );
            $this->assertTrue(
                MassAnnouncementSender::holdsTeamTier( $user ),
                "{$role} holds the team tier through the academy one"
            );
        }

        foreach ( [ 'tt_scout', 'tt_readonly_observer', 'tt_parent', 'tt_player' ] as $role ) {
            $user = (int) self::factory()->user->create( [ 'role' => $role ] );
            $this->assertFalse( MassAnnouncementSender::canAnnounce( $user ), "{$role} may not announce" );
        }
    }

    /* ---- the route enforces it, not the screen --------------------------- */

    /**
     * The acceptance criterion this issue turns on. Hiding the option is a
     * courtesy; refusing the request is the rule.
     */
    public function test_the_route_refuses_a_team_the_sender_does_not_hold(): void {
        wp_set_current_user( $this->coach_a );

        $response = $this->dispatch( 'POST', '/talenttrack/v1/comms/announcements', [
            'scope'   => MassAnnouncementSender::SCOPE_TEAM,
            'team_id' => $this->team_b,
            'subject' => 'Kit',
            'body'    => 'Bring a white shirt.',
        ] );

        $this->assertSame( 403, $response->get_status() );
        $this->assertSame( '0', $this->logCount(), 'a refused announcement sends nothing' );
    }

    public function test_the_route_refuses_an_academy_audience_from_a_team_sender(): void {
        wp_set_current_user( $this->coach_a );

        foreach ( [ MassAnnouncementSender::SCOPE_ACADEMY, MassAnnouncementSender::SCOPE_AGE_GROUP ] as $scope ) {
            $response = $this->dispatch( 'POST', '/talenttrack/v1/comms/announcements', [
                'scope'     => $scope,
                'age_group' => 'O13',
                'subject'   => 'Kit',
                'body'      => 'Bring a white shirt.',
            ] );
            $this->assertSame( 403, $response->get_status(), "{$scope} is refused for a team-scoped sender" );
        }

        $this->assertSame( '0', $this->logCount() );
    }

    public function test_the_route_refuses_somebody_with_neither_capability(): void {
        wp_set_current_user( (int) self::factory()->user->create( [ 'role' => 'tt_scout' ] ) );

        $this->assertSame( 403, $this->dispatch( 'GET', '/talenttrack/v1/comms/announcements/recipients', [
            'scope'   => MassAnnouncementSender::SCOPE_TEAM,
            'team_id' => $this->team_a,
        ] )->get_status() );

        $this->assertSame( 403, $this->dispatch( 'POST', '/talenttrack/v1/comms/announcements', [
            'scope'   => MassAnnouncementSender::SCOPE_TEAM,
            'team_id' => $this->team_a,
            'subject' => 'Kit',
            'body'    => 'Bring a white shirt.',
        ] )->get_status() );
    }

    public function test_a_team_scope_with_no_team_is_refused_rather_than_widened(): void {
        wp_set_current_user( $this->admin );

        $response = $this->dispatch( 'POST', '/talenttrack/v1/comms/announcements', [
            'scope'   => MassAnnouncementSender::SCOPE_TEAM,
            'subject' => 'Kit',
            'body'    => 'Bring a white shirt.',
        ] );

        $this->assertSame( 400, $response->get_status(), 'a missing team must not fall back to the whole academy' );
        $this->assertSame( '0', $this->logCount() );
    }

    /* ---- the count, before anything is committed ------------------------- */

    public function test_the_recipient_count_is_available_before_anything_is_sent(): void {
        wp_set_current_user( $this->coach_a );

        $response = $this->dispatch( 'GET', '/talenttrack/v1/comms/announcements/recipients', [
            'scope'   => MassAnnouncementSender::SCOPE_TEAM,
            'team_id' => $this->team_a,
        ] );
        $this->assertSame( 200, $response->get_status() );

        $data = $this->payload( $response );
        $this->assertSame(
            ( new MassAnnouncementSender() )->recipientCount( MassAnnouncementSender::SCOPE_TEAM, $this->team_a ),
            $data['count']
        );
        $this->assertTrue( $data['can_opt_out'], 'an announcement can be refused, and the confirm step says so' );
        $this->assertSame( 'respected', $data['quiet_hours'] );
        $this->assertSame( '0', $this->logCount(), 'asking costs nothing' );
    }

    public function test_a_team_announcement_stops_at_that_team(): void {
        $sender = new MassAnnouncementSender();

        $academy = $sender->recipientCount( MassAnnouncementSender::SCOPE_ACADEMY );
        $team_a  = $sender->recipientCount( MassAnnouncementSender::SCOPE_TEAM, $this->team_a );

        $this->assertGreaterThan( 0, $team_a );
        $this->assertLessThan( $academy, $team_a );
    }

    public function test_an_age_group_reaches_every_team_in_it(): void {
        $sender = new MassAnnouncementSender();

        $this->assertSame(
            [ 'O13', 'O15' ],
            $sender->ageGroups(),
            'the age groups on offer are the ones teams are actually in'
        );
        $this->assertGreaterThan(
            $sender->recipientCount( MassAnnouncementSender::SCOPE_TEAM, $this->team_a ),
            $sender->recipientCount( MassAnnouncementSender::SCOPE_AGE_GROUP, 0, 'O13' ),
            'the age group is wider than one of its teams'
        );
    }

    public function test_a_parent_with_two_children_receives_one_copy(): void {
        $recipients = ( new MassAnnouncementSender() )->audience( MassAnnouncementSender::SCOPE_ACADEMY );
        $user_ids   = array_map( static fn ( Recipient $r ): int => $r->userId, $recipients );

        $this->assertSame(
            1,
            count( array_keys( $user_ids, $this->parent, true ) ),
            'a parent with a child in two teams is one recipient, not two'
        );
    }

    /* ---- an ordinary message --------------------------------------------- */

    /**
     * The line between this and the safeguarding broadcast. A family that
     * has switched announcements off does not get one.
     */
    public function test_a_recipient_who_opted_out_does_not_receive_it(): void {
        ( new OptOutPolicy() )->setOptedOut( $this->parent, MessageType::MASS_ANNOUNCEMENT, true );

        ( new MassAnnouncementSender() )->send(
            'Kit',
            'Bring a white shirt on Saturday.',
            MassAnnouncementSender::SCOPE_ACADEMY
        );

        $row = $this->rowFor( $this->parent );
        $this->assertNotNull( $row, 'a refusal still leaves the row that proves it' );
        $this->assertSame( CommsResult::STATUS_OPTED_OUT, $row->status );
    }

    /** The other half: quiet hours hold it, and there is no override. */
    public function test_quiet_hours_hold_it(): void {
        QueryHelpers::set_config( 'comms_quiet_hours_start', '00:00' );
        QueryHelpers::set_config( 'comms_quiet_hours_end', '23:59' );

        $results = ( new MassAnnouncementSender() )->send(
            'Kit',
            'Bring a white shirt on Saturday.',
            MassAnnouncementSender::SCOPE_ACADEMY
        );

        $this->assertNotSame( [], $results );
        foreach ( $results as $result ) {
            $this->assertSame(
                CommsResult::STATUS_QUIET_HOURS,
                $result->status,
                'an announcement waits for the morning; nothing in the flow can override that'
            );
        }
    }

    public function test_a_sent_announcement_is_recorded_in_the_message_log(): void {
        wp_set_current_user( $this->coach_a );

        $response = $this->dispatch( 'POST', '/talenttrack/v1/comms/announcements', [
            'scope'   => MassAnnouncementSender::SCOPE_TEAM,
            'team_id' => $this->team_a,
            'subject' => 'Kit',
            'body'    => 'Bring a white shirt on Saturday.',
        ] );

        $this->assertSame( 200, $response->get_status() );
        $data = $this->payload( $response );
        $this->assertGreaterThan( 0, $data['sent'] );
        $this->assertSame( $data['recipients'], $data['sent'] );

        global $wpdb;
        $this->assertSame(
            (string) $data['recipients'],
            (string) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->log} WHERE message_type = %s",
                MessageType::MASS_ANNOUNCEMENT
            ) ),
            'one row per recipient, in the one log'
        );
    }

    public function test_an_empty_message_is_refused(): void {
        wp_set_current_user( $this->coach_a );

        $this->assertSame( 400, $this->dispatch( 'POST', '/talenttrack/v1/comms/announcements', [
            'scope'   => MassAnnouncementSender::SCOPE_TEAM,
            'team_id' => $this->team_a,
            'subject' => 'Kit',
            'body'    => '   ',
        ] )->get_status() );

        $this->assertSame( '0', $this->logCount() );
    }

    /* ---- the wizard ------------------------------------------------------- */

    public function test_the_flow_is_a_wizard_ending_in_a_confirm_step(): void {
        $wizard = WizardRegistry::find( ( new NewTeamAnnouncementWizard() )->slug() );

        $this->assertNotNull( $wizard, 'the announcement flow is reachable at ?tt_view=wizard' );

        $slugs = array_map(
            static fn ( $step ): string => $step->slug(),
            $wizard->steps()
        );
        $this->assertSame( [ 'audience', 'compose', 'confirm' ], $slugs );
        $this->assertSame( 'audience', $wizard->firstStepSlug(), 'the audience is chosen before the words are written' );
    }

    /**
     * `set_up()` registers the wizard itself so the tests above do not
     * depend on module boot order, which would make the assertion above
     * tautological on its own. This is the half that is not: the module
     * really does register it, so a live install has it too.
     */
    public function test_the_wizards_module_registers_it(): void {
        $source = (string) file_get_contents(
            dirname( __DIR__, 2 ) . '/src/Modules/Wizards/WizardsModule.php'
        );
        $this->assertStringContainsString( 'new NewTeamAnnouncementWizard()', $source );
    }

    /**
     * Save model C: nothing is written before the last step. The first two
     * steps declare a next step, so the framework never calls their
     * `submit()`, and they have nothing to commit if it did.
     */
    public function test_no_step_before_the_confirm_step_commits_anything(): void {
        $wizard = new NewTeamAnnouncementWizard();
        $steps  = $wizard->steps();

        foreach ( $steps as $index => $step ) {
            $last = $index === count( $steps ) - 1;
            $this->assertSame(
                $last,
                $step->nextStep( [] ) === null,
                'only the last step submits'
            );
            if ( ! $last ) {
                $this->assertSame( [], $step->submit( [] ), 'a step that is not the last has nothing to commit' );
            }
        }

        $this->assertSame( '0', $this->logCount() );
    }

    /* ---- fixtures --------------------------------------------------------- */

    /**
     * Two age groups, three teams, so an age group can be shown to be
     * wider than one of its teams and narrower than the academy. One
     * parent has a child in two of them.
     */
    private function seedAcademy(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => CurrentClub::id(), 'name' => 'O13-1', 'age_group' => 'O13' ] );
        $this->team_a = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => CurrentClub::id(), 'name' => 'O13-2', 'age_group' => 'O13' ] );
        $team_a2 = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => CurrentClub::id(), 'name' => 'O15-1', 'age_group' => 'O15' ] );
        $this->team_b = (int) $wpdb->insert_id;

        $this->parent = (int) self::factory()->user->create( [ 'role' => 'tt_parent', 'user_email' => 'parent@example.test' ] );
        $second       = (int) self::factory()->user->create( [ 'role' => 'tt_parent', 'user_email' => 'second@example.test' ] );
        $third        = (int) self::factory()->user->create( [ 'role' => 'tt_parent', 'user_email' => 'third@example.test' ] );

        $repo = new PlayerParentsRepository();

        $this->players['a1'] = $this->insertPlayer( 'Sam', $this->team_a );
        $this->players['a2'] = $this->insertPlayer( 'Kim', $team_a2 );
        $this->players['b1'] = $this->insertPlayer( 'Robin', $this->team_b );

        // The shared parent's children sit in two different teams, which
        // is what makes the deduplication assertion mean something.
        $repo->link( $this->players['a1'], $this->parent, true );
        $repo->link( $this->players['b1'], $this->parent, true );
        $repo->link( $this->players['a2'], $second, true );
        $repo->link( $this->players['b1'], $third, true );

        $this->coach_a  = (int) self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $this->head_dev = (int) self::factory()->user->create( [ 'role' => 'tt_head_dev' ] );
        $this->assignToTeam( $this->coach_a, $this->team_a );
    }

    private function insertPlayer( string $first, int $team_id ): int {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_players', [
            'club_id'       => CurrentClub::id(),
            'first_name'    => $first,
            'last_name'     => 'Announce',
            'team_id'       => $team_id,
            'status'        => 'active',
            'date_of_birth' => '2012-05-04',
        ] );
        return (int) $wpdb->insert_id;
    }

    /** A `tt_people` row plus a team-scoped grant — how the app assigns staff. */
    private function assignToTeam( int $user_id, int $team_id ): void {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_people', [
            'club_id'    => CurrentClub::id(),
            'first_name' => 'Team',
            'last_name'  => 'Coach',
            'role_type'  => 'head_coach',
            'wp_user_id' => $user_id,
            'status'     => 'active',
        ] );

        $wpdb->insert( $wpdb->prefix . 'tt_user_role_scopes', [
            'club_id'    => CurrentClub::id(),
            'person_id'  => (int) $wpdb->insert_id,
            'role_id'    => 1,
            'scope_type' => 'team',
            'scope_id'   => $team_id,
        ] );
    }

    private function logCount(): string {
        global $wpdb;
        return (string) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->log}" );
    }

    private function rowFor( int $user_id ): ?object {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$this->log} WHERE recipient_user_id = %d LIMIT 1",
            $user_id
        ) );
        return $row instanceof \stdClass ? $row : null;
    }

    /**
     * @param array<string,mixed> $params
     */
    private function dispatch( string $method, string $route, array $params = [] ): \WP_REST_Response {
        $request = new WP_REST_Request( $method, $route );
        foreach ( $params as $key => $value ) {
            $request->set_param( $key, $value );
        }
        return rest_get_server()->dispatch( $request );
    }

    /**
     * @return array<string,mixed>
     */
    private function payload( \WP_REST_Response $response ): array {
        $data = $response->get_data();
        if ( is_array( $data ) && isset( $data['data'] ) && is_array( $data['data'] ) ) {
            return $data['data'];
        }
        return is_array( $data ) ? $data : [];
    }
}

/** Reaches anyone with an email address, and records nothing else. */
final class AnnouncementSpyAdapter implements ChannelAdapterInterface {
    public function key(): string { return 'email'; }
    public function canReach( Recipient $recipient ): bool { return $recipient->emailAddress !== ''; }
    public function send( CommsRequest $request, Recipient $recipient, string $uuid, string $renderedSubject, string $renderedBody ): CommsResult {
        return new CommsResult( $uuid, CommsResult::STATUS_SENT, 'email', $recipient );
    }
}
