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
use TT\Modules\Pdp\Frontend\PdpFormConversationView;

/**
 * #4980 (epic #4975) — the trainer's side of a PDP conversation form: the
 * player's preparation beside the trainer's scores (nothing of it before
 * "klaar"), "Bespreken" on every difference, "aangepast" on what the player
 * moved after "klaar", and sign-off closed until the form is complete.
 */
final class PdpTrainerFormTest extends WP_UnitTestCase {

    // Sign-off and every form write commit their own transaction.
    use CommitsAfterCascade;

    private const OUTFIELD   = 'jg4it_elite_outfield';
    private const GOALKEEPER = 'jg4it_elite_goalkeeper';
    private const STRONGEST  = 'Zijn passing met links 4980';

    private string $p    = '';
    private int $club    = 0;
    private int $player  = 0;
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

    // ---- the player's preparation on the trainer's screen ---------------------

    public function test_before_klaar_the_trainer_sees_not_ready_on_every_score_and_no_answer(): void {
        wp_set_current_user( $this->account );
        $this->assertSame( 200, $this->send( 'PATCH', 'form/player', [ 'scores' => [ 'aannames' => 4 ], 'answers' => [ 'strongest_quality' => self::STRONGEST ] ] )[1] );

        wp_set_current_user( $this->trainer );
        $this->assertNull( $this->send( 'GET', 'form', [] )[0]['data']['player'], 'the route gives the trainer nothing of it' );

        $html  = $this->screen();
        $items = count( PdpFormRegistry::items( (array) PdpFormRegistry::definition( self::OUTFIELD ) ) );
        $this->assertSame( $items, substr_count( $html, 'Player: not ready yet' ), 'every player score reads not ready yet' );
        $this->assertStringNotContainsString( self::STRONGEST, $html, 'no answer of the player shows' );
        $this->assertStringNotContainsString( 'is-marked', $html, 'no player score is marked on a row' );
    }

    public function test_after_klaar_the_trainer_sees_every_player_score_and_the_strongest_quality(): void {
        $this->playerPrepares( [ 'aannames' => 4, 'technisch_rechts' => 2 ] );

        wp_set_current_user( $this->trainer );
        $data = $this->send( 'GET', 'form', [] )[0]['data'];
        $this->assertSame( 4, $data['player']['scores']['aannames'] );
        $this->assertSame( self::STRONGEST, $data['player']['answers']['strongest_quality'] );

        $html = $this->screen();
        $this->assertStringContainsString( 'Player: 4 · Goed', $html );
        $this->assertStringContainsString( 'Player: 2 · Matig', $html );
        $this->assertStringContainsString( self::STRONGEST, $html );
        $this->assertStringNotContainsString( 'Player: not ready yet', $html );
        $this->assertStringContainsString( 'aria-label="Trainer: 4 · Goed, the player&#039;s score"', $html, 'the player\'s score is marked on its choice, and named' );
    }

    // ---- Bespreken -----------------------------------------------------------

    public function test_discuss_shows_on_every_difference_and_on_no_match(): void {
        $this->playerPrepares( [ 'aannames' => 4, 'technisch_rechts' => 2, 'technisch_links' => 3 ] );

        wp_set_current_user( $this->trainer );
        $this->assertSame( 200, $this->send( 'PATCH', 'form/coach', [ 'scores' => [ 'aannames' => 4, 'technisch_rechts' => 3, 'technisch_links' => 5 ] ] )[1] );

        $html = $this->screen();
        $this->assertSame( [ 'technisch_rechts', 'technisch_links' ], $this->flagged( $html ), 'the two that differ, not the one that matches' );
    }

    // ---- aangepast -----------------------------------------------------------

    public function test_changed_marks_only_the_scores_the_player_moved_after_klaar(): void {
        global $wpdb;
        $this->playerPrepares( [ 'aannames' => 4, 'technisch_rechts' => 2 ] );
        // Move "klaar" and every stamp a minute back, so a later write is after it.
        $wpdb->query( $wpdb->prepare( "UPDATE {$this->p}tt_pdp_conversations SET player_prepared_at = DATE_SUB(player_prepared_at, INTERVAL 60 SECOND) WHERE id = %d", $this->conv ) );
        $wpdb->query( $wpdb->prepare( "UPDATE {$this->p}tt_pdp_form_scores SET updated_at = DATE_SUB(updated_at, INTERVAL 120 SECOND) WHERE conversation_id = %d", $this->conv ) );

        // The autosave sends the whole form: one score moved, one resent as it was.
        wp_set_current_user( $this->account );
        $this->assertSame( 200, $this->send( 'PATCH', 'form/player', [ 'scores' => [ 'aannames' => 5, 'technisch_rechts' => 2 ] ] )[1] );

        wp_set_current_user( $this->trainer );
        $state = $this->send( 'GET', 'form', [] )[0]['data']['state'];
        $this->assertSame( [ 'aannames' ], $state['player_changed_items'], 'a score resent unchanged is not a change' );
        $this->assertSame( 1, substr_count( $this->screen(), 'tt-pdp-coachform__changed' ) );
    }

    // ---- sign-off ------------------------------------------------------------

    public function test_an_incomplete_form_names_what_is_missing_and_cannot_be_signed(): void {
        wp_set_current_user( $this->trainer );
        $this->assertSame( 200, $this->send( 'PATCH', 'form/coach', [ 'scores' => [ 'aannames' => 3 ] ] )[1] );

        $html = $this->screen();
        $this->assertMatchesRegularExpression( '/data-tt-coach-signoff disabled/', $html, 'the button is closed' );
        $this->assertStringContainsString( 'Still needed before you can sign off:', $html );
        $this->assertStringContainsString( '<li>Technisch rechts</li>', $html, 'a missing score is named by its item' );
        $this->assertStringContainsString( 'Ontwikkelpunt 1 (SMART): deadline', $html, 'a missing SMART field is named' );

        $missing = $this->send( 'GET', 'form', [] )[0]['data']['state']['missing'];
        $this->assertContains( 'scores.technisch_rechts', array_column( $missing, 'field' ) );
        $this->assertNotContains( 'scores.aannames', array_column( $missing, 'field' ) );

        [ $data, $status ] = $this->send( 'POST', 'signoff', [] );
        $this->assertSame( 400, $status );
        $this->assertSame( 'missing_fields', $data['errors'][0]['code'] );
    }

    public function test_without_the_players_preparation_it_can_be_signed_and_nothing_is_written_before(): void {
        global $wpdb;
        wp_set_current_user( $this->trainer );
        $this->fillTrainerPart();

        $this->assertSame( [], $this->send( 'GET', 'form', [] )[0]['data']['state']['missing'], 'complete' );
        $html = $this->screen();
        $this->assertDoesNotMatchRegularExpression( '/data-tt-coach-signoff disabled/', $html, 'the button is open' );
        $this->assertStringContainsString( 'hidden', $this->between( $html, 'data-tt-coach-missing', '>' ), 'no missing list' );

        $evals = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->p}tt_evaluations WHERE player_id = %d", $this->player ) );
        $goals = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->p}tt_goals WHERE player_id = %d", $this->player ) );
        $this->assertSame( [ 0, 0 ], [ $evals, $goals ], 'nothing is written to evaluations or goals before sign-off' );

        [ $data, $status ] = $this->send( 'POST', 'signoff', [] );
        $this->assertSame( 201, $status, 'the player prepared nothing, and that does not block it: ' . wp_json_encode( $data ) );
        $this->assertSame( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->p}tt_evaluations WHERE player_id = %d", $this->player ) ) );
        $this->assertSame( 2, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->p}tt_goals WHERE player_id = %d", $this->player ) ) );

        $html = $this->screen();
        $this->assertStringContainsString( 'tt-pdp-coachform__signed', $html );
        $this->assertStringNotContainsString( 'data-tt-coach-signoff', $html, 'no sign-off control once signed' );
        $this->assertSame( 2, substr_count( $html, 'Goal created' ) );
        $this->assertStringNotContainsString( 'data-tt-save-state', $html, 'read-only: no autosave' );
    }

    public function test_the_sections_are_folds_and_the_view_answers_true_for_a_form_talk(): void {
        wp_set_current_user( $this->trainer );
        ob_start();
        $handled = PdpFormConversationView::render( $this->conv, $this->trainer );
        $html    = (string) ob_get_clean();
        $this->assertTrue( $handled );
        $definition = (array) PdpFormRegistry::definition( self::OUTFIELD );
        // A fold per section, the strongest quality and the SMART points.
        $this->assertSame( count( (array) $definition['sections'] ) + 2, substr_count( $html, '<details class="tt-pdp-coachform__section"' ) );
        $this->assertStringNotContainsString( 'role="tablist"', $html, 'no tab strip' );
        $this->assertStringNotContainsString( 'data-tt-autosave-form', $html, 'wired by its own script, so sign-off can flush first' );
    }

    // ---- helpers -------------------------------------------------------------

    /** @return list<string> the items flagged "Discuss" on the screen */
    private function flagged( string $html ): array {
        preg_match_all( '/data-tt-item="([^"]+)"><p class="tt-pdp-coachform__iname"><span>[^<]*<\/span><span class="tt-pdp-coachform__flag">/', $html, $m );
        return $m[1];
    }

    private function between( string $html, string $from, string $to ): string {
        $at = strpos( $html, $from );
        if ( $at === false ) return '';
        $end = strpos( $html, $to, $at );
        return substr( $html, $at, $end === false ? null : $end - $at );
    }

    /** @param array<string,int> $scores */
    private function playerPrepares( array $scores ): void {
        wp_set_current_user( $this->account );
        $this->assertSame( 200, $this->send( 'PATCH', 'form/player', [ 'scores' => $scores, 'answers' => [ 'strongest_quality' => self::STRONGEST ] ] )[1] );
        $this->assertSame( 200, $this->send( 'PUT', 'form/player/preparation', [] )[1] );
    }

    private function fillTrainerPart(): void {
        $definition = (array) PdpFormRegistry::definition( self::OUTFIELD );
        $scores     = [];
        foreach ( PdpFormRegistry::items( $definition ) as $item ) $scores[ $item['key'] ] = 3;
        [ $data, $status ] = $this->send( 'PATCH', 'form/coach', [
            'scores'  => $scores,
            'answers' => [ 'strongest_quality' => 'Afgesproken: passing met links.' ],
            'goals'   => [
                '1' => [ 'action' => 'Elke training 20 passes links.', 'support' => 'Extra oefenvorm.', 'due_date' => '2027-01-31', 'item_key' => 'technisch_links' ],
                '2' => [ 'action' => 'Kijken voor de aanname.', 'support' => 'Video na elke wedstrijd.', 'due_date' => '2027-02-28' ],
            ],
        ] );
        $this->assertSame( 200, $status, wp_json_encode( $data ) );
    }

    /** The trainer's form, as the conversation screen draws it. */
    private function screen(): string {
        wp_set_current_user( $this->trainer );
        ob_start();
        PdpFormConversationView::render( $this->conv, $this->trainer );
        return (string) ob_get_clean();
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
        $this->account = (int) self::factory()->user->create( [ 'role' => 'tt_player' ] );
        $wpdb->update( "{$this->p}tt_players", [ 'wp_user_id' => $this->account ], [ 'id' => $this->player ] );
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
