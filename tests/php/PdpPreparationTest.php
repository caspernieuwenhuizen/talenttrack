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
use TT\Modules\Pdp\Forms\PdpFormSummary;
use TT\Modules\Pdp\Frontend\PdpPreparationView;
use TT\Modules\Pdp\Services\PdpCycleState;

/**
 * #4979 (epic #4975) — the player prepares a form conversation in My PDP:
 * from 14 days before the planned talk, never without a planned date, until
 * it locks. The trainer sees the switch go at the player's first save and
 * none of the answers before "klaar".
 */
final class PdpPreparationTest extends WP_UnitTestCase {

    // Every form write takes the conversation's row lock in a transaction,
    // whose commit ends the per-test one; the sweep keeps the rows here.
    use CommitsAfterCascade;

    private const OUTFIELD   = 'jg4it_elite_outfield';
    private const GOALKEEPER = 'jg4it_elite_goalkeeper';

    private string $p    = '';
    private int $club    = 0;
    private int $conv    = 0;
    private int $trainer = 0;
    private int $account = 0;

    public function set_up(): void {
        parent::set_up();
        $this->markFixtureFloor();
        $this->markConfigKeys( PdpFormRegistry::CONFIG_KEY, 'rating_min', 'rating_max', 'rating_step' );
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

        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        $this->trainer = (int) self::factory()->user->create( [ 'role' => 'administrator' ] );
        $this->seed();
    }

    public function tear_down(): void {
        global $wp_rest_server;
        PdpFormRegistry::reset();
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        $this->cleanUpAndCommit();
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        parent::tear_down();
    }

    // ---- the window, in the domain ----------------------------------------

    public function test_the_window_opens_14_days_before_the_planned_time(): void {
        $now = 1_800_000_000;
        $at  = gmdate( 'Y-m-d H:i:s', $now + 14 * DAY_IN_SECONDS );
        $this->assertSame( $now, PdpCycleState::preparationOpensAt( (object) [ 'scheduled_at' => $at ] ) );
        $this->assertFalse( PdpCycleState::preparationOpen( (object) [ 'scheduled_at' => $at ], $now - 60 ), 'a minute before, closed' );
        $this->assertTrue( PdpCycleState::preparationOpen( (object) [ 'scheduled_at' => $at ], $now ), 'at 14 days, open' );
        $this->assertTrue( PdpCycleState::preparationOpen( (object) [ 'scheduled_at' => $at ], $now + 20 * DAY_IN_SECONDS ), 'after the talk the lock decides, not the window' );

        $this->assertNull( PdpCycleState::preparationOpensAt( (object) [ 'scheduled_at' => null ] ) );
        $this->assertFalse( PdpCycleState::preparationOpen( (object) [ 'scheduled_at' => '' ], $now ), 'no planned date, no preparation' );
        $this->assertSame(
            PdpCycleState::preparationOpen( (object) [ 'scheduled_at' => $at ], $now ),
            PdpCycleState::reflectionWindowOpen( (object) [ 'scheduled_at' => $at ], $now ),
            'the reflection keeps the same window'
        );
    }

    // ---- the window, on the write routes ----------------------------------

    public function test_15_days_before_the_talk_the_writes_are_refused_with_the_opening_date(): void {
        $this->plan( time() + 15 * DAY_IN_SECONDS );
        wp_set_current_user( $this->account );

        [ $data, $status ] = $this->send( 'PATCH', 'form/player', [ 'scores' => [ 'aannames' => 3 ] ] );
        $this->assertSame( 409, $status );
        $this->assertSame( 'preparation_not_open', $data['errors'][0]['code'] );
        $details = (array) $data['errors'][0]['details'];
        $this->assertSame( gmdate( 'Y-m-d', time() + DAY_IN_SECONDS ), substr( (string) ( $details['opens_at'] ?? '' ), 0, 10 ), 'it opens in a day: ' . wp_json_encode( $data ) );

        [ $data, $status ] = $this->send( 'PUT', 'form/player/preparation', [] );
        $this->assertSame( 409, $status );
        $this->assertSame( 'preparation_not_open', $data['errors'][0]['code'] );

        $state = $this->send( 'GET', 'form', [] )[0]['data']['state'];
        $this->assertFalse( $state['preparation_open'] );
        $this->assertNotNull( $state['preparation_opens_at'] );
        $this->assertFalse( $this->hasPlayerEntries(), 'nothing was written' );
    }

    public function test_at_14_days_the_form_opens(): void {
        $this->plan( time() + 14 * DAY_IN_SECONDS - 60 );
        wp_set_current_user( $this->account );

        $this->assertSame( 200, $this->send( 'PATCH', 'form/player', [ 'scores' => [ 'aannames' => 3 ] ] )[1] );
        $this->assertSame( 200, $this->send( 'PUT', 'form/player/preparation', [] )[1] );
        $this->assertTrue( $this->send( 'GET', 'form', [] )[0]['data']['state']['preparation_open'] );
    }

    public function test_without_a_planned_date_the_writes_are_refused(): void {
        global $wpdb;
        $wpdb->update( "{$this->p}tt_pdp_conversations", [ 'scheduled_at' => null ], [ 'id' => $this->conv ] );
        wp_set_current_user( $this->account );

        foreach ( [ [ 'PATCH', 'form/player', [ 'answers' => [ 'strongest_quality' => 'Snelheid' ] ] ], [ 'PUT', 'form/player/preparation', [] ] ] as [ $method, $route, $body ] ) {
            [ $data, $status ] = $this->send( $method, $route, $body );
            $this->assertSame( 409, $status, $method . ' ' . $route );
            $this->assertSame( 'preparation_not_open', $data['errors'][0]['code'] );
            $details = (array) $data['errors'][0]['details'];
            $this->assertArrayHasKey( 'opens_at', $details );
            $this->assertNull( $details['opens_at'], 'no opening date until it is planned' );
        }
        $state = $this->send( 'GET', 'form', [] )[0]['data']['state'];
        $this->assertFalse( $state['preparation_open'] );
        $this->assertNull( $state['preparation_opens_at'] );
    }

    // ---- the trainer before "klaar" ---------------------------------------

    public function test_the_trainer_sees_the_switch_go_at_the_first_save_and_no_answers_before_klaar(): void {
        wp_set_current_user( $this->trainer );
        $before = $this->send( 'GET', 'form', [] )[0]['data'];
        $this->assertSame( self::GOALKEEPER, $before['state']['form_switch_to'], 'the switch is offered' );
        $this->assertNull( $before['player'] );

        wp_set_current_user( $this->account );
        $this->assertSame( 200, $this->send( 'PATCH', 'form/player', [ 'scores' => [ 'aannames' => 4 ], 'answers' => [ 'strongest_quality' => 'Mijn wendbaarheid' ] ] )[1] );

        wp_set_current_user( $this->trainer );
        $started = $this->send( 'GET', 'form', [] )[0]['data'];
        $this->assertNull( $started['state']['form_switch_to'], 'the switch is gone' );
        $this->assertNull( $started['player'], 'nothing of what the player wrote before klaar' );
        $this->assertStringNotContainsString( 'wendbaarheid', (string) wp_json_encode( $started ) );

        wp_set_current_user( $this->account );
        $this->assertSame( 200, $this->send( 'PUT', 'form/player/preparation', [] )[1] );
        wp_set_current_user( $this->trainer );
        $ready = $this->send( 'GET', 'form', [] )[0]['data'];
        $this->assertSame( 4, $ready['player']['scores']['aannames'], 'after klaar the trainer reads it' );
    }

    public function test_klaar_and_toch_aanpassen_work_until_the_lock(): void {
        wp_set_current_user( $this->account );
        $this->assertSame( 200, $this->send( 'PUT', 'form/player/preparation', [] )[1] );
        $this->assertNotNull( $this->send( 'GET', 'form', [] )[0]['data']['state']['player_prepared_at'] );
        $this->assertSame( 200, $this->send( 'DELETE', 'form/player/preparation', [] )[1] );
        $this->assertNull( $this->send( 'GET', 'form', [] )[0]['data']['state']['player_prepared_at'] );

        $this->plan( time() - HOUR_IN_SECONDS );
        [ $data, $status ] = $this->send( 'PUT', 'form/player/preparation', [] );
        $this->assertSame( 409, $status );
        $this->assertSame( 'form_locked', $data['errors'][0]['code'], 'after the planned time it is locked' );
    }

    // ---- the carried items -------------------------------------------------

    public function test_a_switch_on_a_talk_not_held_is_refused_in_the_switchs_words(): void {
        global $wpdb;
        $wpdb->update( "{$this->p}tt_pdp_conversations", [ 'not_held_at' => gmdate( 'Y-m-d H:i:s' ), 'not_held_reason' => 'Ziek' ], [ 'id' => $this->conv ] );
        wp_set_current_user( $this->trainer );

        [ $data, $status ] = $this->send( 'PATCH', 'form', [ 'form_key' => self::GOALKEEPER ] );
        $this->assertSame( 409, $status );
        $this->assertSame( 'conversation_not_held', $data['errors'][0]['code'] );
        $this->assertSame( 'This talk did not take place, so the form can no longer be switched.', $data['errors'][0]['message'] );

        $method = new \ReflectionMethod( \TT\Modules\Pdp\Frontend\FrontendPdpManageView::class, 'renderFormSwitch' );
        $method->setAccessible( true );
        ob_start();
        $method->invoke( null, $this->conv, $this->trainer );
        $this->assertSame( '', trim( (string) ob_get_clean() ), 'the form block draws nothing next to the not-held line' );
    }

    // ---- My PDP -----------------------------------------------------------

    public function test_my_pdp_says_when_the_preparation_opens(): void {
        $this->plan( time() + 20 * DAY_IN_SECONDS );
        $html = $this->myPdp();
        $this->assertStringContainsString( 'You can prepare from', $html );
        $this->assertStringNotContainsString( 'data-tt-pdp-prep', $html, 'no form yet' );

        global $wpdb;
        $wpdb->update( "{$this->p}tt_pdp_conversations", [ 'scheduled_at' => null ], [ 'id' => $this->conv ] );
        $this->assertStringContainsString( 'You can prepare once the conversation is planned.', $this->myPdp( null ) );
    }

    public function test_my_pdp_draws_one_step_per_section_and_the_strongest_quality(): void {
        $html = $this->myPdp();
        $definition = PdpFormRegistry::definition( self::OUTFIELD );
        $this->assertNotNull( $definition );

        $this->assertSame( count( (array) $definition['sections'] ) + 1, substr_count( $html, 'data-tt-prep-step=' ), 'a step per section, and the strongest quality' );
        $this->assertSame( count( PdpFormRegistry::items( $definition ) ), substr_count( $html, 'tt-rating-stars tt-rating-stars--choices' ), 'one choice row per item' );
        $this->assertSame( 5 * count( PdpFormRegistry::items( $definition ) ), substr_count( $html, 'class="tt-rating-star tt-rating-choice' ), 'five choices each' );
        $this->assertStringContainsString( 'aria-label="Your score: 3 · Voldoende"', $html, 'a choice is named by the form\'s own label' );
        $this->assertStringContainsString( 'name="answers[strongest_quality]"', $html );
        $this->assertStringContainsString( 'data-tt-prep-done', $html, 'klaar is its own control' );
        $this->assertStringNotContainsString( 'name="ready"', $html );
        $this->assertStringNotContainsString( 'data-tt-autosave-form', $html, 'wired by its own script, so klaar can flush first' );
    }

    public function test_my_pdp_shows_the_summary_after_klaar_and_read_only_after_the_lock(): void {
        wp_set_current_user( $this->account );
        $this->assertSame( 200, $this->send( 'PATCH', 'form/player', [ 'scores' => [ 'aannames' => 4, 'technisch_rechts' => 2 ], 'answers' => [ 'strongest_quality' => 'Mijn wendbaarheid' ] ] )[1] );
        $this->assertSame( 200, $this->send( 'PUT', 'form/player/preparation', [] )[1] );

        $html = $this->myPdp();
        $this->assertStringContainsString( 'Your preparation is ready', $html );
        $this->assertStringContainsString( 'data-tt-prep-reopen', $html, 'toch aanpassen' );
        $this->assertStringContainsString( 'Mijn wendbaarheid', $html );
        $this->assertStringNotContainsString( 'data-tt-pdp-prep ', $html, 'no form while marked ready' );

        $this->plan( time() - HOUR_IN_SECONDS );
        $html = $this->myPdp();
        $this->assertStringContainsString( 'your part of the form can no longer be changed', $html );
        $this->assertStringNotContainsString( 'data-tt-prep-reopen', $html );
        $this->assertStringContainsString( 'Mijn wendbaarheid', $html, 'the summary stays, read-only' );
    }

    public function test_a_parent_sees_nothing_of_the_preparation_and_no_reflection_either(): void {
        global $wpdb;
        $parent = (int) self::factory()->user->create( [ 'role' => 'tt_parent' ] );
        $wpdb->insert( "{$this->p}tt_player_parents", [ 'club_id' => $this->club, 'player_id' => \TT\Modules\Pdp\Prep\PdpPrepAccess::playerFor( $this->conv ), 'parent_user_id' => $parent ] );
        wp_set_current_user( $parent );
        ob_start();
        $handled = PdpPreparationView::render( $this->entry(), false );
        $html    = (string) ob_get_clean();
        $this->assertTrue( $handled, 'a form talk: no free-text reflection in its place' );
        $this->assertSame( '', trim( $html ) );
    }

    public function test_the_summary_counts_and_averages_per_section(): void {
        $definition = PdpFormRegistry::definition( self::OUTFIELD );
        $this->assertNotNull( $definition );
        $summary = PdpFormSummary::of( $definition, [ 'technisch_rechts' => 4, 'technisch_links' => 2, 'aannames' => null ], [ 'strongest_quality' => ' ' ], 'player' );

        $this->assertSame( count( PdpFormRegistry::items( $definition ) ), $summary['items'] );
        $this->assertSame( 2, $summary['scored'] );
        $this->assertSame( 3.0, $summary['sections'][0]['average'] );
        $this->assertNull( $summary['sections'][1]['average'] );
        $this->assertSame( 0, $summary['answered'], 'a blank answer is not an answer' );
        $this->assertFalse( $summary['complete'] );
    }

    // ---- helpers -------------------------------------------------------------

    private function seed(): void {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_teams", [ 'club_id' => $this->club, 'name' => 'O15-1' ] );
        $team = (int) $wpdb->insert_id;
        $wpdb->insert( "{$this->p}tt_players", [
            'club_id' => $this->club, 'team_id' => $team, 'first_name' => 'Sam', 'last_name' => 'de Vries',
            'status' => 'active', 'wp_user_id' => null, 'preferred_positions' => '["CB"]',
        ] );
        $player = (int) $wpdb->insert_id;
        $this->assertGreaterThan( 0, $player, 'the player fixture: ' . $wpdb->last_error );
        $wpdb->insert( "{$this->p}tt_seasons", [ 'club_id' => $this->club, 'name' => '2026/27', 'start_date' => '2026-07-01', 'end_date' => '2027-06-30', 'is_current' => 1 ] );
        $season = (int) $wpdb->insert_id;
        $wpdb->insert( "{$this->p}tt_pdp_files", [ 'club_id' => $this->club, 'player_id' => $player, 'season_id' => $season, 'status' => 'open' ] );
        $file = (int) $wpdb->insert_id;
        $wpdb->insert( "{$this->p}tt_pdp_conversations", [
            'club_id' => $this->club, 'pdp_file_id' => $file, 'sequence' => 1, 'template_key' => 'start',
            'scheduled_at' => gmdate( 'Y-m-d H:i:s', time() + 5 * DAY_IN_SECONDS ),
        ] );
        $this->conv    = (int) $wpdb->insert_id;
        $this->account = (int) self::factory()->user->create( [ 'role' => 'tt_player' ] );
        $wpdb->update( "{$this->p}tt_players", [ 'wp_user_id' => $this->account ], [ 'id' => $player ] );
    }

    private function plan( int $ts ): void {
        global $wpdb;
        $wpdb->update( "{$this->p}tt_pdp_conversations", [ 'scheduled_at' => gmdate( 'Y-m-d H:i:s', $ts ) ], [ 'id' => $this->conv ] );
    }

    private function hasPlayerEntries(): bool {
        return ( new \TT\Modules\Pdp\Forms\PdpFormEntriesRepository() )->hasEntries( $this->conv, 'player' );
    }

    /** @return array<string,mixed> the next talk as `PdpFamilyReader` hands it to the view */
    private function entry( ?string $scheduled = '' ): array {
        global $wpdb;
        if ( $scheduled === '' ) {
            $scheduled = $wpdb->get_var( $wpdb->prepare( "SELECT scheduled_at FROM {$this->p}tt_pdp_conversations WHERE id = %d", $this->conv ) );
        }
        return [ 'id' => $this->conv, 'scheduled_at' => $scheduled, 'template_label' => 'Startgesprek' ];
    }

    /** The preparation as the player sees it in My PDP. */
    private function myPdp( ?string $scheduled = '' ): string {
        wp_set_current_user( $this->account );
        ob_start();
        $handled = PdpPreparationView::render( $this->entry( $scheduled ), true );
        $html    = (string) ob_get_clean();
        $this->assertTrue( $handled, 'a form talk is prepared on the form' );
        return $html;
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
