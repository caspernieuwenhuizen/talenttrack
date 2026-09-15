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
use TT\Modules\Comms\Send\SafeguardingBroadcastSender;
use TT\Modules\Comms\Template\TemplateRegistry;
use TT\Modules\Comms\Template\TemplateSwitch;
use TT\Modules\Comms\Templates\SafeguardingBroadcastTemplate;
use TT\Modules\Invitations\PlayerParentsRepository;

/**
 * #3423 (epic #3384) — the safeguarding broadcast.
 *
 * `MessageType::SAFEGUARDING_BROADCAST` has been operational since the
 * module shipped and *My settings* has told every parent so; nothing could
 * send one. These tests hold the two halves of that promise to account.
 *
 * The first one is the reason the issue exists: a family who has muted
 * every kind of message the academy sends still receives this one. It is
 * asserted end to end — through the real template, the real policy chain
 * and the real audit row — rather than by trusting that the operational
 * flag does what its name says.
 *
 * The second is the blast radius: who may send one, and how far it goes.
 */
final class SafeguardingBroadcastTest extends WP_UnitTestCase {

    private string $log;
    private int $admin;
    private int $parent;
    private int $team_a;
    private int $team_b;

    /** @var int[] */
    private array $players = [];

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->log = $wpdb->prefix . 'tt_comms_log';
        $wpdb->query( "DELETE FROM {$this->log}" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}tt_comms_optouts" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}tt_player_parents" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}tt_players" );

        ( new RolesService() )->installRoles();
        $this->admin = (int) self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $this->admin );

        TemplateRegistry::clear();
        ChannelAdapterRegistry::clear();
        TemplateRegistry::register( new SafeguardingBroadcastTemplate() );
        ChannelAdapterRegistry::register( new BroadcastSpyAdapter() );
        QueryHelpers::set_config( TemplateSwitch::CONFIG_KEY, '' );

        $this->seedAcademy();
        do_action( 'rest_api_init' );
    }

    public function tear_down(): void {
        TemplateRegistry::clear();
        ChannelAdapterRegistry::clear();
        OptOutPolicy::flushTableCache();
        parent::tear_down();
    }

    /* ---- the promise ----------------------------------------------------- */

    /**
     * The whole point of the message type. A parent who has switched off
     * everything the academy can send them still gets this one — including
     * when a row exists against the operational type itself, which the
     * preferences screen cannot create but an older install might hold.
     */
    public function test_a_recipient_who_has_opted_out_of_everything_still_receives_it(): void {
        $this->muteEverything( $this->parent );

        $results = ( new SafeguardingBroadcastSender() )->send(
            'Ground closed',
            'The east gate is locked this weekend.',
            SafeguardingBroadcastSender::SCOPE_ACADEMY
        );

        $this->assertNotSame( [], $results );
        foreach ( $results as $result ) {
            $this->assertSame( CommsResult::STATUS_SENT, $result->status, 'nobody can refuse a safeguarding broadcast' );
        }

        $row = $this->rowFor( $this->parent );
        $this->assertNotNull( $row, 'the send leaves a log row like every other' );
        $this->assertSame( CommsResult::STATUS_SENT, $row->status );
        $this->assertSame( MessageType::SAFEGUARDING_BROADCAST, $row->message_type );
    }

    /** The other half of "operational": the clock does not hold it either. */
    public function test_it_is_sent_during_quiet_hours(): void {
        QueryHelpers::set_config( 'comms_quiet_hours_start', '00:00' );
        QueryHelpers::set_config( 'comms_quiet_hours_end', '23:59' );

        $results = ( new SafeguardingBroadcastSender() )->send(
            'Ground closed',
            'The east gate is locked this weekend.',
            SafeguardingBroadcastSender::SCOPE_ACADEMY
        );

        foreach ( $results as $result ) {
            $this->assertNotSame( CommsResult::STATUS_QUIET_HOURS, $result->status );
        }
        $this->assertSame( CommsResult::STATUS_SENT, $this->rowFor( $this->parent )->status );
    }

    public function test_every_send_goes_through_the_message_log(): void {
        $results = ( new SafeguardingBroadcastSender() )->send(
            'Ground closed',
            'The east gate is locked this weekend.',
            SafeguardingBroadcastSender::SCOPE_ACADEMY
        );

        global $wpdb;
        $logged = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->log}" );
        $this->assertSame( count( $results ), $logged, 'one row per recipient, in the one log' );
    }

    /* ---- the blast radius ------------------------------------------------ */

    public function test_the_academy_audience_reaches_every_family_once(): void {
        $sender = new SafeguardingBroadcastSender();

        // The shared parent has two children in the academy and must not
        // receive two copies of the same broadcast.
        $recipients = $sender->audience( SafeguardingBroadcastSender::SCOPE_ACADEMY );
        $userIds    = array_map( static fn ( Recipient $r ): int => $r->userId, $recipients );

        $this->assertSame(
            1,
            count( array_keys( $userIds, $this->parent, true ) ),
            'a parent with two children in the academy is one recipient, not two'
        );
    }

    public function test_a_team_scoped_broadcast_stops_at_that_team(): void {
        $sender = new SafeguardingBroadcastSender();

        $academy = $sender->recipientCount( SafeguardingBroadcastSender::SCOPE_ACADEMY );
        $team_a  = $sender->recipientCount( SafeguardingBroadcastSender::SCOPE_TEAM, $this->team_a );
        $team_b  = $sender->recipientCount( SafeguardingBroadcastSender::SCOPE_TEAM, $this->team_b );

        $this->assertGreaterThan( 0, $team_b );
        $this->assertLessThan( $academy, $team_b, 'a team is narrower than the academy' );
        $this->assertGreaterThan( 0, $team_a );
    }

    public function test_a_team_scope_with_no_team_reaches_nobody(): void {
        $this->assertSame(
            0,
            ( new SafeguardingBroadcastSender() )->recipientCount( SafeguardingBroadcastSender::SCOPE_TEAM, 0 ),
            'a missing team must not fall back to the whole academy'
        );
    }

    public function test_a_departed_player_is_not_on_the_distribution_list(): void {
        global $wpdb;
        $sender = new SafeguardingBroadcastSender();
        $before = $sender->recipientCount( SafeguardingBroadcastSender::SCOPE_ACADEMY );

        $wpdb->update(
            $wpdb->prefix . 'tt_players',
            [ 'archived_at' => current_time( 'mysql' ) ],
            [ 'id' => $this->players['solo'] ]
        );

        $this->assertLessThan(
            $before,
            $sender->recipientCount( SafeguardingBroadcastSender::SCOPE_ACADEMY ),
            'a family whose child left the academy is off the list'
        );
    }

    /* ---- who may send one ------------------------------------------------ */

    /**
     * The capability decision, pinned. Academy admin and the WordPress
     * administrator hold it; a coach does not, and neither does the head of
     * development — `tt_send_email` would have handed it to every coach,
     * which is the answer this deliberately did not take.
     */
    public function test_only_the_academy_admin_holds_the_capability_by_default(): void {
        $cap = SafeguardingBroadcastSender::CAP;

        foreach ( [ 'administrator', 'tt_club_admin' ] as $role ) {
            $user = (int) self::factory()->user->create( [ 'role' => $role ] );
            $this->assertTrue( user_can( $user, $cap ), "{$role} may send a safeguarding broadcast" );
        }

        foreach ( [ 'tt_coach', 'tt_head_dev', 'tt_scout' ] as $role ) {
            $user = (int) self::factory()->user->create( [ 'role' => $role ] );
            $this->assertFalse( user_can( $user, $cap ), "{$role} may not send a safeguarding broadcast" );
        }

        // And the cap this deliberately is not: a coach holds `tt_send_email`,
        // which is exactly why sending to every family is a separate one.
        $coach = (int) self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $this->assertTrue( user_can( $coach, 'tt_send_email' ) );
        $this->assertFalse( user_can( $coach, $cap ) );
    }

    public function test_the_rest_route_refuses_someone_without_the_capability(): void {
        wp_set_current_user( (int) self::factory()->user->create( [ 'role' => 'tt_coach' ] ) );

        $this->assertSame( 403, $this->dispatch( 'POST', '/talenttrack/v1/comms/safeguarding-broadcasts', [
            'subject'      => 'Ground closed',
            'body'         => 'The east gate is locked.',
            'acknowledged' => true,
        ] )->get_status() );

        $this->assertSame( 403, $this->dispatch( 'GET', '/talenttrack/v1/comms/safeguarding-broadcasts/recipients' )->get_status() );
    }

    /* ---- the confirm step ------------------------------------------------ */

    public function test_the_recipient_count_is_available_before_anything_is_sent(): void {
        $response = $this->dispatch( 'GET', '/talenttrack/v1/comms/safeguarding-broadcasts/recipients', [
            'scope' => SafeguardingBroadcastSender::SCOPE_ACADEMY,
        ] );
        $this->assertSame( 200, $response->get_status() );

        $data = $this->payload( $response );
        $this->assertSame(
            ( new SafeguardingBroadcastSender() )->recipientCount( SafeguardingBroadcastSender::SCOPE_ACADEMY ),
            $data['count']
        );
        $this->assertFalse( $data['can_opt_out'], 'the confirm step is told recipients cannot refuse it' );

        global $wpdb;
        $this->assertSame( '0', (string) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->log}" ), 'asking costs nothing' );
    }

    /**
     * The acknowledgement is a gate, not decoration. A caller that omits it
     * has not agreed to the blast radius, and the API says so rather than
     * sending to every family and reporting success.
     */
    public function test_an_unacknowledged_broadcast_is_refused(): void {
        $response = $this->dispatch( 'POST', '/talenttrack/v1/comms/safeguarding-broadcasts', [
            'subject'      => 'Ground closed',
            'body'         => 'The east gate is locked.',
            'acknowledged' => false,
        ] );

        $this->assertSame( 400, $response->get_status() );

        global $wpdb;
        $this->assertSame( '0', (string) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->log}" ), 'nothing was sent' );
    }

    public function test_an_acknowledged_broadcast_sends_and_reports_what_it_reached(): void {
        $response = $this->dispatch( 'POST', '/talenttrack/v1/comms/safeguarding-broadcasts', [
            'subject'      => 'Ground closed',
            'body'         => 'The east gate is locked.',
            'acknowledged' => true,
        ] );

        $this->assertSame( 200, $response->get_status() );
        $data = $this->payload( $response );
        $this->assertGreaterThan( 0, $data['sent'] );
        $this->assertSame( $data['recipients'], $data['sent'] );
    }

    /* ---- fixtures -------------------------------------------------------- */

    /**
     * Two teams, three players. One parent has a child in each team, so the
     * deduplication and the team scoping both have something to prove.
     */
    private function seedAcademy(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => CurrentClub::id(), 'name' => 'U13' ] );
        $this->team_a = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => CurrentClub::id(), 'name' => 'U15' ] );
        $this->team_b = (int) $wpdb->insert_id;

        $this->parent = (int) self::factory()->user->create( [
            'role'       => 'subscriber',
            'user_email' => 'parent@example.test',
        ] );
        $other_parent = (int) self::factory()->user->create( [
            'role'       => 'subscriber',
            'user_email' => 'other@example.test',
        ] );
        $third_parent = (int) self::factory()->user->create( [
            'role'       => 'subscriber',
            'user_email' => 'third@example.test',
        ] );

        $repo = new PlayerParentsRepository();

        $this->players['older']   = $this->insertPlayer( 'Sam', $this->team_a );
        $this->players['extra']   = $this->insertPlayer( 'Kim', $this->team_a );
        $this->players['younger'] = $this->insertPlayer( 'Robin', $this->team_b );
        $this->players['solo']    = $this->insertPlayer( 'Alex', $this->team_b );

        $repo->link( $this->players['older'], $this->parent, true );
        $repo->link( $this->players['younger'], $this->parent, true );
        $repo->link( $this->players['extra'], $third_parent, true );
        $repo->link( $this->players['solo'], $other_parent, true );
    }

    private function insertPlayer( string $first, int $team_id ): int {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_players', [
            'club_id'       => CurrentClub::id(),
            'first_name'    => $first,
            'last_name'     => 'Broadcast',
            'team_id'       => $team_id,
            'status'        => 'active',
            'date_of_birth' => '2012-05-04',
        ] );
        return (int) $wpdb->insert_id;
    }

    /**
     * Mute everything this user could mute — and then the one type the
     * preferences screen refuses to store, written straight into the table
     * the way an older install might carry it.
     */
    private function muteEverything( int $user_id ): void {
        global $wpdb;
        $policy = new OptOutPolicy();
        foreach ( MessageType::optOutable() as $type ) {
            $policy->setOptedOut( $user_id, $type, true );
        }
        $wpdb->insert( $wpdb->prefix . 'tt_comms_optouts', [
            'club_id'      => CurrentClub::id(),
            'user_id'      => $user_id,
            'message_type' => MessageType::SAFEGUARDING_BROADCAST,
            'opted_out_at' => current_time( 'mysql' ),
        ] );
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
final class BroadcastSpyAdapter implements ChannelAdapterInterface {
    public function key(): string { return 'email'; }
    public function canReach( Recipient $recipient ): bool { return $recipient->emailAddress !== ''; }
    public function send( CommsRequest $request, Recipient $recipient, string $uuid, string $renderedSubject, string $renderedBody ): CommsResult {
        return new CommsResult( $uuid, CommsResult::STATUS_SENT, 'email', $recipient );
    }
}
