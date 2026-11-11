<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Players\PlayerParentVisibilityRepository;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Comms\Channel\Adapters\EmailChannelAdapter;
use TT\Modules\Comms\Channel\ChannelAdapterRegistry;
use TT\Modules\Comms\Template\TemplateRegistry;
use TT\Modules\Comms\Template\TemplateSwitch;
use TT\Modules\Comms\Templates\PdpConversationSignedTemplate;
use TT\Modules\Invitations\PlayerParentsRepository;
use TT\Modules\Pdp\Forms\PdpFormCategorySeeder;
use TT\Modules\Pdp\Forms\PdpFormRegistry;
use TT\Modules\Pdp\Frontend\PdpFormReadView;

/**
 * #4981 (epic #4975) — after a conversation form is signed off, the player
 * and each parent the child's `pdp` switch allows are told, read it with
 * both scores, and confirm they read it. Nothing of it before sign-off, and
 * nothing for an unlinked parent or the read-only observer.
 */
final class PdpReadConfirmTest extends WP_UnitTestCase {

    use CommitsAfterCascade;
    use QuietHoursClosed;

    private const OUTFIELD   = 'jg4it_elite_outfield';
    private const GOALKEEPER = 'jg4it_elite_goalkeeper';

    private string $p    = '';
    private int $club    = 0;
    private int $player  = 0;
    private int $conv    = 0;
    private int $trainer = 0;
    private int $account = 0;
    private int $parent  = 0;

    /** @var list<string> */
    private array $sent = [];

    public function set_up(): void {
        parent::set_up();
        $this->markFixtureFloor();
        $this->markConfigKeys( PdpFormRegistry::CONFIG_KEY, 'rating_min', 'rating_max', 'rating_step', TemplateSwitch::CONFIG_KEY );
        global $wpdb, $wp_rest_server;
        $this->p    = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();

        $roles = new RolesService();
        $roles->installRoles();
        $roles->ensureCapabilities();
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        PdpFormRegistry::reset();

        QueryHelpers::set_config( 'rating_min', '5' );
        QueryHelpers::set_config( 'rating_max', '9' );
        QueryHelpers::set_config( 'rating_step', '1' );
        $forms = [ 'outfield' => self::OUTFIELD, 'goalkeeper' => self::GOALKEEPER ];
        $this->assertIsArray( PdpFormCategorySeeder::ensure( $forms ) );
        QueryHelpers::set_config( PdpFormRegistry::CONFIG_KEY, (string) wp_json_encode( $forms ) );

        TemplateRegistry::clear();
        ChannelAdapterRegistry::clear();
        TemplateRegistry::register( new PdpConversationSignedTemplate() );
        ChannelAdapterRegistry::register( new EmailChannelAdapter() );
        QueryHelpers::set_config( TemplateSwitch::CONFIG_KEY, '' );
        $this->closeQuietHours();
        $this->sent = [];
        add_filter( 'tt_comms_email_send', [ $this, 'captureSend' ], 10, 2 );

        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        $this->trainer = (int) self::factory()->user->create( [ 'role' => 'administrator' ] );
        $this->seed();
    }

    public function tear_down(): void {
        global $wp_rest_server;
        remove_filter( 'tt_comms_email_send', [ $this, 'captureSend' ], 10 );
        TemplateRegistry::clear();
        ChannelAdapterRegistry::clear();
        PdpFormRegistry::reset();
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        $this->cleanUpAndCommit();
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        parent::tear_down();
    }

    /**
     * @param mixed               $accepted
     * @param array<string,mixed> $args
     */
    public function captureSend( $accepted, $args ): bool {
        $this->sent[] = strtolower( (string) ( $args['to'] ?? '' ) );
        return true;
    }

    // ---- the message ---------------------------------------------------------

    public function test_sign_off_tells_the_player_and_the_parent_once(): void {
        $this->signOff();
        sort( $this->sent );
        $this->assertSame( [ 'dad-4981@example.test', 'sam-4981@example.test' ], $this->sent, 'the player and the parent, once each' );

        $this->sent = [];
        wp_set_current_user( $this->trainer );
        $this->assertSame( 409, $this->send( 'POST', 'signoff', [] )[1], 'a second sign-off is refused' );
        $this->assertSame( [], $this->sent, 'and sends nothing' );
    }

    public function test_a_parent_the_pdp_switch_hides_it_from_is_not_told(): void {
        ( new PlayerParentVisibilityRepository() )->setVisibility( $this->player, 'pdp', false );
        $this->flush();
        $this->signOff();
        $this->assertSame( [ 'sam-4981@example.test' ], $this->sent, 'the player only' );
    }

    // ---- reading -------------------------------------------------------------

    public function test_a_parent_reads_nothing_before_sign_off_and_both_scores_after(): void {
        $this->playerPrepares();

        wp_set_current_user( $this->parent );
        $this->assertSame( 403, $this->send( 'GET', 'form', [] )[1], 'the route refuses a parent before sign-off' );
        $this->assertSame( '', $this->readAs( $this->parent ), 'and the page draws nothing' );

        $this->signOff();

        $html  = $this->readAs( $this->parent );
        $items = count( PdpFormRegistry::items( (array) PdpFormRegistry::definition( self::OUTFIELD ) ) );
        $this->assertSame( $items, substr_count( $html, 'class="tt-pdp-formread__item"' ), 'every item' );
        $this->assertStringContainsString( 'Player: 4 · Goed', $html, 'the player\'s score' );
        $this->assertSame( $items, substr_count( $html, 'tt-pdp-formread__coach">Trainer: 3 · Voldoende' ), 'and the trainer\'s, on every item' );
        $this->assertStringContainsString( 'Afgesproken: passing met links.', $html, 'the agreed strongest quality' );
        $this->assertStringContainsString( 'Elke training 20 passes links.', $html, 'the development points' );
        $this->assertStringContainsString( 'Toelichting techniek 4981', $html, 'the trainer\'s notes' );
        $this->assertMatchesRegularExpression( '/Player [0-9],[0-9] · Trainer 3,0|Player [0-9]\.[0-9] · Trainer 3\.0/', $html, 'the averages in the fold title' );
    }

    public function test_without_preparation_the_players_column_reads_not_filled_in(): void {
        $this->signOff();
        foreach ( [ $this->account, $this->parent ] as $reader ) {
            $html = $this->readAs( $reader );
            $this->assertStringContainsString( 'Player: not filled in', $html, 'reader ' . $reader );
            $this->assertStringNotContainsString( 'Player: 4', $html );
        }
    }

    // ---- confirming ----------------------------------------------------------

    public function test_before_sign_off_the_acknowledgement_is_refused(): void {
        $this->playerPrepares(); // the first write pins the form
        wp_set_current_user( $this->account );
        [ $data, $status ] = $this->send( 'PATCH', '', [ 'player_ack_at' => gmdate( 'Y-m-d H:i:s' ) ], 'pdp-conversations/' . $this->conv );
        $this->assertSame( 409, $status );
        $this->assertSame( 'form_not_signed_off', $data['errors'][0]['code'] );
    }

    public function test_the_parents_confirmation_is_recorded_with_its_date(): void {
        global $wpdb;
        $this->signOff();
        wp_set_current_user( $this->parent );
        $at = gmdate( 'Y-m-d H:i:s' );
        $this->assertSame( 200, $this->send( 'PATCH', '', [ 'parent_ack_at' => $at ], 'pdp-conversations/' . $this->conv )[1] );
        $stored = (string) $wpdb->get_var( $wpdb->prepare( "SELECT parent_ack_at FROM {$this->p}tt_pdp_conversations WHERE id = %d", $this->conv ) );
        $this->assertNotSame( '', $stored, 'recorded' );
        $this->assertSame( substr( $at, 0, 10 ), substr( $stored, 0, 10 ), 'with its date' );
    }

    public function test_the_server_stamps_the_confirmation_time_whatever_the_browser_sends(): void {
        global $wpdb;
        $this->signOff();
        wp_set_current_user( $this->account );
        $this->assertSame( 200, $this->send( 'PATCH', '', [ 'player_ack_at' => '2020-01-01 09:00:00' ], 'pdp-conversations/' . $this->conv )[1] );
        $stored = (string) $wpdb->get_var( $wpdb->prepare( "SELECT player_ack_at FROM {$this->p}tt_pdp_conversations WHERE id = %d", $this->conv ) );
        $this->assertLessThan( 120, abs( strtotime( $stored . ' UTC' ) - time() ), 'now, not the date the request carried: ' . $stored );
    }

    public function test_a_parent_who_may_not_read_the_form_is_offered_no_confirmation_and_refused_one(): void {
        global $wpdb;
        $this->signOff();
        ( new PlayerParentVisibilityRepository() )->setVisibility( $this->player, 'pdp', false );
        $this->flush();

        $html = $this->myPdp( $this->parent );
        $this->assertStringNotContainsString( 'I have read it', $html, 'no confirm on a form the parent cannot read' );
        $this->assertStringNotContainsString( 'Acknowledge as parent', $html, 'and no free-text acknowledgement in its place' );

        wp_set_current_user( $this->parent );
        $this->assertSame( 403, $this->send( 'PATCH', '', [ 'parent_ack_at' => 'now' ], 'pdp-conversations/' . $this->conv )[1] );
        $this->assertNull( $wpdb->get_var( $wpdb->prepare( "SELECT parent_ack_at FROM {$this->p}tt_pdp_conversations WHERE id = %d", $this->conv ) ), 'nothing written' );
    }

    /**
     * The message follows the age tiers (#0042) as every message about one
     * player does: parents only up to ten, both at eleven, the player alone
     * from twelve.
     *
     * @dataProvider tiers
     * @param list<string> $told
     */
    public function test_the_message_follows_the_age_tier( int $age, array $told ): void {
        global $wpdb;
        $wpdb->update( "{$this->p}tt_players", [ 'date_of_birth' => gmdate( 'Y-m-d', strtotime( "-{$age} years -2 months" ) ) ], [ 'id' => $this->player ] );
        $this->signOff();
        sort( $this->sent );
        $this->assertSame( $told, $this->sent );
    }

    /** @return array<string, array{0:int, 1:list<string>}> */
    public static function tiers(): array {
        return [
            'youngest (9)' => [ 9, [ 'dad-4981@example.test' ] ],
            'middle (11)'  => [ 11, [ 'dad-4981@example.test', 'sam-4981@example.test' ] ],
            'oldest (14)'  => [ 14, [ 'sam-4981@example.test' ] ],
        ];
    }

    public function test_the_goal_link_shows_only_where_the_goal_may_be_read(): void {
        $this->signOff();
        $this->assertStringContainsString( 'Open the goal', $this->readAs( $this->parent ), 'shared: the link' );

        ( new PlayerParentVisibilityRepository() )->setVisibility( $this->player, 'goals', false );
        $this->flush();
        $html = $this->readAs( $this->parent );
        $this->assertStringContainsString( 'Elke training 20 passes links.', $html, 'the development point still reads' );
        $this->assertStringNotContainsString( 'Open the goal', $html, 'without a link the goal page would refuse' );
    }

    public function test_the_message_link_opens_the_talk_and_a_notice_waits_until_confirmed(): void {
        $this->signOff();
        $link = \TT\Modules\Comms\Send\PdpConversationSignedSend::link( $this->conv, null );
        $this->assertStringContainsString( 'conv=' . $this->conv, $link );
        $this->assertStringEndsWith( '#tt-pdp-read-' . $this->conv, $link );

        $_GET['conv'] = (string) $this->conv;
        $html = $this->myPdp( $this->account );
        unset( $_GET['conv'] );
        $this->assertStringContainsString( 'id="tt-pdp-panel-' . $this->conv . '" class="tt-pdp-panel">', $html, 'the talk is open on arrival' );
        $this->assertStringContainsString( 'id="tt-pdp-read-' . $this->conv . '" tabindex="-1"', $html, 'at the signed form\'s heading' );
        $this->assertStringContainsString( 'tt-pdp-awaiting', $html, 'the notice says it waits' );

        wp_set_current_user( $this->account );
        $this->assertSame( 200, $this->send( 'PATCH', '', [ 'player_ack_at' => 'now' ], 'pdp-conversations/' . $this->conv )[1] );
        $this->assertStringNotContainsString( 'tt-pdp-awaiting', $this->myPdp( $this->account ), 'gone once confirmed' );
    }

    // ---- nobody else -----------------------------------------------------------

    public function test_an_unlinked_parent_and_the_observer_get_nothing(): void {
        global $wpdb;
        $this->signOff();
        $stranger = (int) self::factory()->user->create( [ 'role' => 'tt_parent' ] );
        $observer = (int) self::factory()->user->create( [ 'role' => 'tt_readonly_observer' ] );
        $wpdb->insert( "{$this->p}tt_people", [
            'club_id' => $this->club, 'first_name' => 'Obs', 'last_name' => '4981', 'role_type' => 'readonly_observer', 'wp_user_id' => $observer, 'status' => 'active',
        ] );
        $this->flush();

        foreach ( [ 'unlinked parent' => $stranger, 'observer' => $observer ] as $who => $uid ) {
            wp_set_current_user( $uid );
            $this->assertContains( $this->send( 'GET', 'form', [] )[1], [ 403, 404 ], $who );
            $this->assertSame( '', $this->readAs( $uid ), $who . ' reads nothing' );
        }
    }

    // ---- helpers -------------------------------------------------------------

    private function readAs( int $user ): string {
        wp_set_current_user( $user );
        ob_start();
        PdpFormReadView::render( $this->conv, $user === $this->parent ? $this->player : null );
        return trim( (string) ob_get_clean() );
    }

    /** My PDP for the player, as this reader sees it. */
    private function myPdp( int $user ): string {
        wp_set_current_user( $user );
        ob_start();
        \TT\Modules\Pdp\Frontend\FrontendMyPdpView::render( (object) \TT\Infrastructure\Query\QueryHelpers::get_player( $this->player ) );
        return (string) ob_get_clean();
    }

    private function playerPrepares(): void {
        wp_set_current_user( $this->account );
        $this->assertSame( 200, $this->send( 'PATCH', 'form/player', [ 'scores' => [ 'aannames' => 4 ], 'answers' => [ 'strongest_quality' => 'Mijn wendbaarheid 4981' ] ] )[1] );
        $this->assertSame( 200, $this->send( 'PUT', 'form/player/preparation', [] )[1] );
    }

    private function signOff(): void {
        $definition = (array) PdpFormRegistry::definition( self::OUTFIELD );
        $scores     = [];
        foreach ( PdpFormRegistry::items( $definition ) as $item ) $scores[ $item['key'] ] = 3;
        wp_set_current_user( $this->trainer );
        [ $data, $status ] = $this->send( 'PATCH', 'form/coach', [
            'scores'  => $scores,
            'answers' => [ 'strongest_quality' => 'Afgesproken: passing met links.', 'notes.technical' => 'Toelichting techniek 4981' ],
            'goals'   => [
                '1' => [ 'action' => 'Elke training 20 passes links.', 'support' => 'Extra oefenvorm.', 'due_date' => '2027-01-31' ],
                '2' => [ 'action' => 'Kijken voor de aanname.', 'support' => 'Video na elke wedstrijd.', 'due_date' => '2027-02-28' ],
            ],
        ] );
        $this->assertSame( 200, $status, wp_json_encode( $data ) );
        [ $data, $status ] = $this->send( 'POST', 'signoff', [] );
        $this->assertSame( 201, $status, wp_json_encode( $data ) );
        $this->flush();
    }

    private function seed(): void {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_teams", [ 'club_id' => $this->club, 'name' => 'O15-1' ] );
        $team = (int) $wpdb->insert_id;
        $wpdb->insert( "{$this->p}tt_players", [
            'club_id' => $this->club, 'team_id' => $team, 'first_name' => 'Sam', 'last_name' => 'de Vries',
            'status' => 'active', 'wp_user_id' => null, 'preferred_positions' => '["CB"]',
        ] );
        $this->player = (int) $wpdb->insert_id;
        $this->assertGreaterThan( 0, $this->player, 'the player fixture: ' . $wpdb->last_error );
        $wpdb->insert( "{$this->p}tt_seasons", [ 'club_id' => $this->club, 'name' => '2026/27', 'start_date' => '2026-07-01', 'end_date' => '2027-06-30', 'is_current' => 1 ] );
        $season = (int) $wpdb->insert_id;
        $wpdb->insert( "{$this->p}tt_pdp_files", [ 'club_id' => $this->club, 'player_id' => $this->player, 'season_id' => $season, 'status' => 'open' ] );
        $file = (int) $wpdb->insert_id;
        $wpdb->insert( "{$this->p}tt_pdp_conversations", [
            'club_id' => $this->club, 'pdp_file_id' => $file, 'sequence' => 1, 'template_key' => 'start',
            'scheduled_at' => gmdate( 'Y-m-d H:i:s', time() + 5 * DAY_IN_SECONDS ),
        ] );
        $this->conv    = (int) $wpdb->insert_id;
        $this->account = (int) self::factory()->user->create( [ 'role' => 'tt_player', 'user_email' => 'sam-4981@example.test' ] );
        $wpdb->update( "{$this->p}tt_players", [ 'wp_user_id' => $this->account ], [ 'id' => $this->player ] );
        $this->parent = (int) self::factory()->user->create( [ 'role' => 'tt_parent', 'user_email' => 'dad-4981@example.test' ] );
        ( new PlayerParentsRepository() )->link( $this->player, $this->parent, true );
        $this->flush();
    }

    private function flush(): void {
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
    }

    /**
     * @param array<string,mixed> $body
     * @return array{0:array<string,mixed>,1:int}
     */
    private function send( string $method, string $route, array $body, ?string $path = null ): array {
        $path    = $path ?? 'pdp-conversations/' . $this->conv . '/' . $route;
        $request = new WP_REST_Request( $method, '/talenttrack/v1/' . $path );
        if ( $body !== [] ) {
            $request->set_header( 'content-type', 'application/json' );
            $request->set_body( (string) wp_json_encode( $body ) );
        }
        $response = rest_get_server()->dispatch( $request );
        $data     = $response->get_data();
        return [ is_array( $data ) ? $data : [], (int) $response->get_status() ];
    }
}
