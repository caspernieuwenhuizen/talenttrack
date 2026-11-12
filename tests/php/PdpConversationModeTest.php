<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Pdp\Forms\PdpFormCategorySeeder;
use TT\Modules\Pdp\Forms\PdpFormRegistry;
use TT\Modules\Pdp\Frontend\FrontendPdpConversationModeView;
use TT\Modules\Pdp\Frontend\PdpFormConversationView;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;
use TT\Shared\Frontend\DashboardShortcode;
use TT\Shared\Frontend\PresentationSurfaces;
use TT\Shared\Frontend\ShellPreference;

/**
 * #5082 — the PDP talk on the screen in the room.
 *
 * What a room screen may show is an allowlist decided in the domain, so the
 * tests read the REST answer and the rendered page for what must never be
 * there: the background, the trainer's section notes, sign-off, what
 * sign-off still needs. The readers are those of the trainer's part; a
 * refused reader gets the normal screen with its chain. A score set in the
 * mode goes through the same route and shows on the talk's page.
 */
final class PdpConversationModeTest extends WP_UnitTestCase {

    use CommitsAfterCascade;

    private const FORM      = 'jg4it_elite_outfield';
    private const NOTE      = 'Toelichting-alleen-voor-de-trainer-5082';
    private const AGREED    = 'Afgesproken-passing-links-5082';
    private const PLAYERSAY = 'Mijn-passing-links-5082';

    private string $p     = '';
    private int $club     = 0;
    private int $player   = 0;
    private int $file     = 0;
    private int $conv     = 0;
    private int $trainer  = 0;
    private int $account  = 0;
    private int $parent   = 0;

    /** @var array<string, mixed> */
    private array $get = [];

    public function set_up(): void {
        parent::set_up();
        $this->markFixtureFloor();
        $this->markConfigKeys( PdpFormRegistry::CONFIG_KEY, 'rating_min', 'rating_max', 'rating_step', 'academy_name' );
        global $wpdb, $wp_rest_server;
        $this->p    = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();
        $this->get  = $_GET;

        $roles = new RolesService();
        $roles->installRoles();
        $roles->ensureCapabilities();
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        PdpFormRegistry::reset();
        PresentationSurfaces::useFile( null );
        FrontendBreadcrumbs::reset();

        QueryHelpers::set_config( 'rating_min', '5' );
        QueryHelpers::set_config( 'rating_max', '9' );
        QueryHelpers::set_config( 'rating_step', '1' );
        QueryHelpers::set_config( 'academy_name', 'JO15 Academie 5082' );
        $forms = [ 'outfield' => self::FORM, 'goalkeeper' => 'jg4it_elite_goalkeeper' ];
        $this->assertIsArray( PdpFormCategorySeeder::ensure( $forms ) );
        QueryHelpers::set_config( PdpFormRegistry::CONFIG_KEY, (string) wp_json_encode( $forms ) );

        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        $this->seed();
        ShellPreference::setClubDefault( ShellPreference::APP );

        wp_set_current_user( $this->trainer );
        $this->assertSame( 200, $this->send( 'PATCH', 'form', [ 'form_key' => self::FORM ] )[1] );
        wp_set_current_user( 0 );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        $_GET = $this->get;
        wp_set_current_user( 0 );
        PresentationSurfaces::useFile( null );
        FrontendBreadcrumbs::reset();
        $this->cleanUpAndCommit();
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        PdpFormRegistry::reset();
        parent::tear_down();
    }

    // ---- what the room screen shows -------------------------------------

    public function test_the_display_route_answers_only_the_allowlist(): void {
        $this->prepareAndFill();

        wp_set_current_user( $this->trainer );
        [ $body, $status ] = $this->send( 'GET', 'form/display', [] );
        $this->assertSame( 200, $status, (string) wp_json_encode( $body ) );
        $data = $body['data'];

        $this->assertSame(
            [ 'conversation_id', 'file_id', 'club', 'player', 'talk', 'form', 'player_ready', 'sections', 'scores', 'shared', 'goals', 'state' ],
            array_keys( $data ),
            'exactly the allowlist, in that shape'
        );
        $this->assertSame( [ 'coach_writable', 'coach_signoff_at' ], array_keys( $data['state'] ), 'no sign-off list, no sign-off right' );

        foreach ( [ 'status', 'behaviour', 'potential', 'evaluations', 'attendance', 'minutes', 'injuries', 'notes', 'self_reflection', 'recent_journey', 'season', 'window', 'tests', 'missing', 'answers', 'coach', 'differences', 'signs_off' ] as $key ) {
            $this->assertArrayNotHasKey( $key, $data, "no '{$key}' on the room screen" );
        }

        $json = (string) wp_json_encode( $data );
        $this->assertStringNotContainsString( self::NOTE, $json, 'the trainer\'s section notes stay off the TV' );
        $this->assertStringNotContainsString( 'notes.', $json );

        $definition = (array) PdpFormRegistry::definition( self::FORM );
        $this->assertSame( PdpFormRegistry::sharedFields( $definition ), array_column( $data['shared'], 'key' ), 'only the shared text fields' );
        $this->assertSame( self::PLAYERSAY, $data['shared'][0]['player'] );
        $this->assertSame( self::AGREED, $data['shared'][0]['agreed'] );

        $this->assertTrue( $data['player_ready'] );
        $this->assertSame( 4, $data['scores']['technisch_rechts']['player'] );
        $this->assertSame( 3, $data['scores']['technisch_rechts']['coach'] );
        $this->assertTrue( $data['scores']['technisch_rechts']['discuss'] );
        $this->assertCount( 2, $data['goals'] );
        $this->assertSame( 'Sem de Vries', $data['player']['name'] );
        $this->assertSame( 'JO15 Academie 5082', $data['club']['name'] );
        $this->assertNotNull( $data['sections'][0]['coach_average'] );
    }

    public function test_before_klaar_the_room_screen_carries_nothing_of_the_player(): void {
        wp_set_current_user( $this->account );
        $this->assertSame( 200, $this->send( 'PATCH', 'form/player', [ 'scores' => [ 'technisch_rechts' => 4 ], 'answers' => [ 'strongest_quality' => self::PLAYERSAY ] ] )[1] );

        wp_set_current_user( $this->trainer );
        $data = $this->send( 'GET', 'form/display', [] )[0]['data'];
        $this->assertFalse( $data['player_ready'] );
        $this->assertNull( $data['scores']['technisch_rechts']['player'] );
        $this->assertNull( $data['shared'][0]['player'] );
        $this->assertNull( $data['sections'][0]['player_average'] );
        $this->assertStringNotContainsString( self::PLAYERSAY, (string) wp_json_encode( $data ) );
    }

    // ---- who may open it ------------------------------------------------

    public function test_readers_are_those_of_the_trainers_part(): void {
        wp_set_current_user( $this->account );
        $this->assertSame( 403, $this->send( 'GET', 'form/display', [] )[1], 'the player before sign-off' );

        wp_set_current_user( $this->parent );
        $this->assertSame( 403, $this->send( 'GET', 'form/display', [] )[1], 'a parent before sign-off' );

        wp_set_current_user( (int) self::factory()->user->create( [ 'role' => 'tt_readonly_observer' ] ) );
        $this->assertSame( 403, $this->send( 'GET', 'form/display', [] )[1], 'the read-only observer' );

        wp_set_current_user( $this->trainer );
        $this->assertSame( 200, $this->send( 'GET', 'form/display', [] )[1] );

        global $wpdb;
        $wpdb->update( "{$this->p}tt_pdp_conversations", [ 'club_id' => $this->club + 1 ], [ 'id' => $this->conv ] );
        $this->assertSame( 404, $this->send( 'GET', 'form/display', [] )[1], 'another club\'s conversation reads as missing' );
    }

    // ---- the view -------------------------------------------------------

    public function test_the_mode_presents_without_the_shell_and_without_what_stays_off_the_tv(): void {
        $this->prepareAndFill();

        $html = $this->renderMode( $this->trainer );

        $this->assertTrue( PresentationSurfaces::isPresenting() );
        $this->assertStringContainsString( 'tt-dashboard--presentation', $html );
        foreach ( [ 'tt-dash-header', 'data-tt-shell', 'tt-shell-bar', 'tt-spotlight', 'tt-dash-help', 'tt-breadcrumbs', 'tt-back-link' ] as $chrome ) {
            $this->assertStringNotContainsString( $chrome, $html, "no {$chrome} on the room screen" );
        }
        foreach ( [ 'tt-pdp-pane-evidence', 'data-tt-coach-signoff', 'data-tt-coach-missing', 'data-tt-prep-reopen', 'Finish and sign', self::NOTE, 'answers[' ] as $absent ) {
            $this->assertStringNotContainsString( $absent, $html, "{$absent} is not in the mode's DOM" );
        }

        $this->assertStringContainsString( 'JO15 Academie 5082', $html, 'the club\'s name' );
        $this->assertStringContainsString( 'Sem de Vries', $html );
        $this->assertStringContainsString( self::AGREED, $html );
        $this->assertStringContainsString( self::PLAYERSAY, $html );
        $this->assertSame( 1, substr_count( $html, 'data-tt-presentation-close' ), 'one way back' );
        $this->assertStringContainsString(
            esc_url( FrontendPdpConversationModeView::closeUrl( $this->file, $this->conv ) ),
            $html,
            'back to the talk\'s page, on its conversation tab'
        );
        $this->assertStringContainsString( 'role="tablist"', $html, 'the steps are spine tabs' );
        $this->assertStringContainsString( 'data-tt-pdp-mode-next', $html );
    }

    public function test_the_mode_writes_the_same_route_without_the_pages_snapshot_key(): void {
        $html = $this->renderMode( $this->trainer );

        $this->assertStringContainsString( 'data-rest-path="pdp-conversations/' . $this->conv . '/form/coach"', $html );
        $this->assertStringContainsString( 'data-rest-method="PATCH"', $html );
        $this->assertStringNotContainsString( 'data-autosave-key', $this->between( $html, 'data-tt-pdp-mode', '</form>' ), 'fewer fields than the page: no sitting snapshot' );
        $this->assertStringContainsString( 'data-tt-save-state', $html );
    }

    public function test_a_score_set_and_cleared_in_the_mode_shows_on_the_talks_page(): void {
        wp_set_current_user( $this->trainer );
        $this->assertSame( 200, $this->send( 'PATCH', 'form/coach', [ 'scores' => [ 'aannames' => 5, 'technisch_rechts' => 2 ] ] )[1] );
        $this->assertSame( 200, $this->send( 'PATCH', 'form/coach', [ 'scores' => [ 'technisch_rechts' => '' ] ] )[1], 'the mode clears with an empty value' );

        ob_start();
        PdpFormConversationView::render( $this->conv, $this->trainer );
        $page = (string) ob_get_clean();

        $aannames = $this->between( $page, 'data-tt-item="aannames"', 'data-tt-item=' );
        $this->assertMatchesRegularExpression( '/data-tt-rating-value[^>]*value="5"|value="5"[^>]*data-tt-rating-value/', $aannames, 'the score set in the mode' );
        $cleared = $this->between( $page, 'data-tt-item="technisch_rechts"', 'data-tt-item=' );
        $this->assertStringContainsString( 'Trainer: not scored yet', $cleared, 'and the one cleared there' );
    }

    public function test_a_refused_reader_gets_the_normal_screen_with_its_chain(): void {
        $html = $this->renderMode( $this->account );

        $this->assertFalse( PresentationSurfaces::isPresenting() );
        $this->assertStringNotContainsString( 'tt-dashboard--presentation', $html );
        $this->assertStringContainsString( 'data-tt-shell', $html );
        $this->assertStringContainsString( 'tt-breadcrumbs', $html );
        $this->assertStringNotContainsString( 'data-tt-pdp-mode', $html );
        $this->assertStringContainsString( 'You do not have access to this conversation mode.', $html );
    }

    public function test_another_clubs_talk_is_not_found_in_the_normal_screen(): void {
        global $wpdb;
        $wpdb->update( "{$this->p}tt_pdp_conversations", [ 'club_id' => $this->club + 1 ], [ 'id' => $this->conv ] );

        $html = $this->renderMode( $this->trainer );

        $this->assertFalse( PresentationSurfaces::isPresenting() );
        $this->assertStringContainsString( 'tt-breadcrumbs', $html );
        $this->assertStringContainsString( 'That conversation no longer exists.', $html );
    }

    // ---- the entry point ------------------------------------------------

    public function test_the_talks_page_offers_conversation_mode_to_the_trainer(): void {
        $html = $this->renderTalkPage( $this->trainer );

        $this->assertStringContainsString( 'data-tt-pdp-mode-open', $html );
        $this->assertStringContainsString( esc_url( FrontendPdpConversationModeView::urlFor( $this->conv ) ), $html );
        $this->assertStringNotContainsString( 'target="_blank"', $this->between( $html, 'data-tt-pdp-mode-open', '</a>' ), 'same tab' );
    }

    public function test_the_close_target_is_a_routable_record_page(): void {
        $close = FrontendPdpConversationModeView::closeUrl( $this->file, $this->conv );
        $this->assertTrue( PresentationSurfaces::isRecordUrl( $close ) );
        $this->assertContains( 'pdp', \TT\Shared\Cli\AdminRoutesCommand::routableSlugs() );
        $this->assertContains( FrontendPdpConversationModeView::SLUG, \TT\Shared\Cli\AdminRoutesCommand::routableSlugs() );
        $this->assertTrue( PresentationSurfaces::claims( FrontendPdpConversationModeView::SLUG ) );
    }

    // ---- helpers ----------------------------------------------------------

    private function prepareAndFill(): void {
        wp_set_current_user( $this->account );
        $this->assertSame( 200, $this->send( 'PATCH', 'form/player', [ 'scores' => [ 'technisch_rechts' => 4, 'aannames' => 3 ], 'answers' => [ 'strongest_quality' => self::PLAYERSAY ] ] )[1] );
        $this->assertSame( 200, $this->send( 'PUT', 'form/player/preparation', [] )[1] );

        wp_set_current_user( $this->trainer );
        [ $data, $status ] = $this->send( 'PATCH', 'form/coach', [
            'scores'  => [ 'technisch_rechts' => 3, 'aannames' => 3 ],
            'answers' => [ 'strongest_quality' => self::AGREED, 'notes.technical' => self::NOTE ],
            'goals'   => [
                '1' => [ 'action' => 'Elke training 20 passes links.', 'support' => 'Extra oefenvorm.', 'due_date' => '2027-01-31', 'item_key' => 'technisch_links' ],
                '2' => [ 'action' => 'Kijken voor de aanname.', 'support' => 'Video na elke wedstrijd.', 'due_date' => '2027-02-28' ],
            ],
        ] );
        $this->assertSame( 200, $status, (string) wp_json_encode( $data ) );
    }

    /** The mode as the dashboard renders it for one user. */
    private function renderMode( int $user_id ): string {
        wp_set_current_user( $user_id );
        PresentationSurfaces::flush();
        FrontendBreadcrumbs::reset();
        $_GET = [ 'tt_view' => FrontendPdpConversationModeView::SLUG, 'id' => (string) $this->conv ];
        return DashboardShortcode::render();
    }

    private function renderTalkPage( int $user_id ): string {
        wp_set_current_user( $user_id );
        $_GET = [ 'tt_view' => 'pdp', 'id' => (string) $this->file, 'conv' => (string) $this->conv ];
        ob_start();
        \TT\Modules\Pdp\Frontend\FrontendPdpManageView::render( $user_id, true );
        return (string) ob_get_clean();
    }

    private function between( string $html, string $from, string $to ): string {
        $at = strpos( $html, $from );
        if ( $at === false ) return '';
        $end = strpos( $html, $to, $at + strlen( $from ) );
        return substr( $html, $at, $end === false ? null : $end - $at );
    }

    private function seed(): void {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_teams", [ 'club_id' => $this->club, 'name' => 'O15-1' ] );
        $team = (int) $wpdb->insert_id;
        $ok = $wpdb->insert( "{$this->p}tt_players", [ 'club_id' => $this->club, 'team_id' => $team, 'first_name' => 'Sem', 'last_name' => 'de Vries', 'status' => 'active', 'wp_user_id' => null ] );
        $this->assertNotFalse( $ok, 'the player fixture: ' . $wpdb->last_error );
        $this->player = (int) $wpdb->insert_id;
        $wpdb->insert( "{$this->p}tt_seasons", [ 'club_id' => $this->club, 'name' => '2026/27', 'start_date' => '2026-07-01', 'end_date' => '2027-06-30', 'is_current' => 1 ] );
        $season = (int) $wpdb->insert_id;
        $wpdb->insert( "{$this->p}tt_pdp_files", [ 'club_id' => $this->club, 'player_id' => $this->player, 'season_id' => $season, 'status' => 'open' ] );
        $this->file = (int) $wpdb->insert_id;
        $wpdb->insert( "{$this->p}tt_pdp_conversations", [
            'club_id' => $this->club, 'pdp_file_id' => $this->file, 'sequence' => 1, 'template_key' => 'start',
            'scheduled_at' => gmdate( 'Y-m-d H:i:s', time() + 5 * DAY_IN_SECONDS ),
        ] );
        $this->conv = (int) $wpdb->insert_id;

        $this->trainer = (int) self::factory()->user->create( [ 'role' => 'administrator' ] );
        $this->account = (int) self::factory()->user->create( [ 'role' => 'tt_player' ] );
        $wpdb->update( "{$this->p}tt_players", [ 'wp_user_id' => $this->account ], [ 'id' => $this->player ] );
        $this->parent = (int) self::factory()->user->create( [ 'role' => 'tt_parent' ] );
        $wpdb->insert( "{$this->p}tt_player_parents", [ 'club_id' => $this->club, 'player_id' => $this->player, 'parent_user_id' => $this->parent ] );
    }

    /**
     * @param array<string,mixed> $body
     * @return array{0:array<string,mixed>,1:int}
     */
    private function send( string $method, string $route, array $body ): array {
        $request = new WP_REST_Request( $method, '/talenttrack/v1/pdp-conversations/' . $this->conv . '/' . $route );
        if ( $body !== [] ) {
            $request->set_header( 'content-type', 'application/json' );
            $request->set_body( (string) wp_json_encode( $body ) );
        }
        $response = rest_get_server()->dispatch( $request );
        $data     = $response->get_data();
        return [ is_array( $data ) ? $data : [], (int) $response->get_status() ];
    }
}
