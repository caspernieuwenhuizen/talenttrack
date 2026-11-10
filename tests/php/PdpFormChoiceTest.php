<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Players\PlayerPosition;
use TT\Infrastructure\Query\LabelTranslator;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Pdp\Forms\PdpFormCategorySeeder;
use TT\Modules\Pdp\Forms\PdpFormChoice;
use TT\Modules\Pdp\Forms\PdpFormRegistry;

/**
 * #4978 (epic #4975) — the conversation form follows the player's position,
 * is pinned at the first write, and the trainer can switch until the
 * player's first save (owner decision 6).
 */
final class PdpFormChoiceTest extends WP_UnitTestCase {

    // Every form write takes the conversation's row lock in a transaction,
    // whose commit ends the per-test one; the sweep keeps the rows here.
    use CommitsAfterCascade;

    private const OUTFIELD   = 'jg4it_elite_outfield';
    private const GOALKEEPER = 'jg4it_elite_goalkeeper';

    private string $p    = '';
    private int $club    = 0;
    private int $file    = 0;
    private int $trainer = 0;

    /** @var list<string> files a test added to the form catalogue */
    private array $added = [];

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
    }

    public function tear_down(): void {
        global $wp_rest_server;
        foreach ( $this->added as $path ) {
            if ( is_file( $path ) ) unlink( $path );
        }
        PdpFormRegistry::reset();
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        $this->cleanUpAndCommit();
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        parent::tear_down();
    }

    // ---- the one position owner ------------------------------------------

    public function test_the_position_owner_reads_every_stored_shape(): void {
        $this->assertSame( [ 'GK', 'CB' ], PlayerPosition::codes( '["GK","CB"]' ) );
        $this->assertSame( [ 'GK', 'CB' ], PlayerPosition::codes( 'GK, CB' ), 'a legacy comma list' );
        $this->assertSame( [], PlayerPosition::codes( '' ) );
        $this->assertSame( [], PlayerPosition::codes( '[]' ) );
        $this->assertSame( 'CB', PlayerPosition::primaryCode( '[" ","CB"]' ), 'an empty first code is skipped' );

        $this->assertTrue( PlayerPosition::isGoalkeeper( (object) [ 'preferred_positions' => '["GK","CB"]' ] ) );
        $this->assertTrue( PlayerPosition::isGoalkeeper( (object) [ 'preferred_positions' => 'gk' ] ), 'case does not matter' );
        $this->assertFalse( PlayerPosition::isGoalkeeper( (object) [ 'preferred_positions' => '["CB","GK"]' ] ), 'a second position is not the primary one' );
        $this->assertFalse( PlayerPosition::isGoalkeeper( (object) [ 'preferred_positions' => null ] ), 'no position is not a goalkeeper' );
    }

    public function test_the_label_surfaces_read_the_same_primary_position(): void {
        $this->assertSame( LabelTranslator::positionLabel( 'GK' ), LabelTranslator::primaryPositionLabel( '["GK","CB"]' ) );
        $this->assertSame( LabelTranslator::positionLabel( 'GK' ), LabelTranslator::primaryPositionLabel( 'GK,CB' ) );
        $this->assertSame( '', LabelTranslator::primaryPositionLabel( '' ) );
    }

    // ---- the form follows the position ---------------------------------------

    public function test_a_goalkeeper_gets_the_keeper_form_and_everyone_else_the_outfield_form(): void {
        $cases = [ 'goalkeeper' => '["GK"]', 'outfield' => '["CB","GK"]', 'no position' => null ];
        $expect = [ 'goalkeeper' => self::GOALKEEPER, 'outfield' => self::OUTFIELD, 'no position' => self::OUTFIELD ];
        wp_set_current_user( $this->trainer );
        foreach ( $cases as $label => $positions ) {
            [ $player, $conv ] = $this->conversation( $positions );
            $this->assertSame( $expect[ $label ], PdpFormChoice::formFor( $this->row( $conv ), $player )['key'] ?? null, $label );

            [ $data, $status ] = $this->send( 'GET', $conv, 'form', [] );
            $this->assertSame( 200, $status );
            $this->assertSame( $expect[ $label ], $data['data']['state']['form_key'], "{$label}: the form shows before anything is saved" );
            $this->assertFalse( $data['data']['state']['form_pinned'] );
        }
    }

    public function test_the_first_write_pins_the_form_and_its_version(): void {
        [ , $conv ] = $this->conversation( '["GK"]' );
        wp_set_current_user( $this->trainer );
        $this->assertSame( 200, $this->send( 'PATCH', $conv, 'form/coach', [ 'scores' => [ 'positionering' => 4 ] ] )[1] );

        $row = $this->row( $conv );
        $this->assertSame( self::GOALKEEPER, (string) $row->form_key );
        $this->assertSame( 1, (int) $row->form_version );
    }

    // ---- the trainer's switch --------------------------------------------------

    public function test_the_trainer_switches_freely_until_the_players_first_save(): void {
        [ , $conv, $account ] = $this->conversation( '["CB"]', true );
        wp_set_current_user( $this->trainer );

        $this->assertSame( self::GOALKEEPER, $this->send( 'GET', $conv, 'form', [] )[0]['data']['state']['form_switch_to'] );
        $this->assertStringContainsString( 'Switch to the goalkeeper form', $this->switchControl( $conv ) );
        $this->assertSame( 200, $this->send( 'PATCH', $conv, 'form', [ 'form_key' => self::GOALKEEPER ] )[1] );
        $this->assertSame( 200, $this->send( 'PATCH', $conv, 'form', [ 'form_key' => self::OUTFIELD ] )[1], 'and back' );
        $this->assertSame( 200, $this->send( 'PATCH', $conv, 'form/coach', [ 'scores' => [ 'aannames' => 3 ] ] )[1] );
        $this->assertSame( 200, $this->send( 'PATCH', $conv, 'form', [ 'form_key' => self::GOALKEEPER ] )[1], 'the trainer\'s own entries do not fix the form' );
        $this->assertSame( 200, $this->send( 'PATCH', $conv, 'form', [ 'form_key' => self::OUTFIELD ] )[1] );

        wp_set_current_user( $account );
        $this->assertSame( 200, $this->send( 'PATCH', $conv, 'form/player', [ 'scores' => [ 'aannames' => 4 ] ] )[1] );

        wp_set_current_user( $this->trainer );
        [ $data, $status ] = $this->send( 'PATCH', $conv, 'form', [ 'form_key' => self::GOALKEEPER ] );
        $this->assertSame( 409, $status );
        $this->assertSame( 'form_fixed', $data['errors'][0]['code'] );
        $this->assertNull( $this->send( 'GET', $conv, 'form', [] )[0]['data']['state']['form_switch_to'], 'the reader offers no switch' );
        $control = $this->switchControl( $conv );
        $this->assertStringContainsString( 'This talk uses the outfield form.', $control );
        $this->assertStringNotContainsString( 'Switch to the', $control, 'and the control is gone' );
    }

    public function test_the_player_and_a_parent_are_offered_no_switch(): void {
        global $wpdb;
        [ $player, $conv, $account ] = $this->conversation( '["CB"]', true );
        wp_set_current_user( $account );
        $this->assertNull( $this->send( 'GET', $conv, 'form', [] )[0]['data']['state']['form_switch_to'] );
        $this->assertSame( 403, $this->send( 'PATCH', $conv, 'form', [ 'form_key' => self::GOALKEEPER ] )[1] );

        $parent = (int) self::factory()->user->create( [ 'role' => 'tt_parent' ] );
        $wpdb->insert( "{$this->p}tt_player_parents", [ 'club_id' => $this->club, 'player_id' => $player, 'parent_user_id' => $parent ] );
        wp_set_current_user( $parent );
        $this->assertSame( 403, $this->send( 'GET', $conv, 'form', [] )[1], 'a parent reads nothing before sign-off' );
        $this->assertSame( 403, $this->send( 'PATCH', $conv, 'form', [ 'form_key' => self::GOALKEEPER ] )[1], 'and switches nothing' );
        $this->assertSame( self::OUTFIELD, PdpFormChoice::formFor( $this->row( $conv ), $player )['key'] ?? null );
    }

    public function test_staff_who_may_see_the_file_but_not_edit_it_get_no_switch(): void {
        [ , $conv ] = $this->conversation( '["CB"]' );
        $reader = (int) self::factory()->user->create( [ 'role' => 'tt_team_manager' ] );
        ( new MatrixRepository() )->setRow( 'team_manager', 'pdp_file', \TT\Modules\Authorization\MatrixGate::READ, \TT\Modules\Authorization\MatrixGate::SCOPE_GLOBAL, '' );
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        $this->assertTrue( \TT\Modules\Pdp\PdpAccess::canSeeFile( $reader, $this->playerOf( $conv ) ), 'precondition: the seat reads the file' );
        $this->assertFalse( \TT\Modules\Pdp\PdpAccess::canEditFile( $reader, $this->playerOf( $conv ) ), 'precondition: and does not edit it' );

        wp_set_current_user( $reader );
        [ $data, $status ] = $this->send( 'GET', $conv, 'form', [] );
        $this->assertSame( 200, $status );
        $this->assertNull( $data['data']['state']['form_switch_to'], 'no switch is offered' );
        $this->assertSame( 403, $this->send( 'PATCH', $conv, 'form', [ 'form_key' => self::GOALKEEPER ] )[1] );
        $this->assertStringNotContainsString( 'Switch to the', $this->switchControl( $conv ) );
    }

    public function test_klaar_fixes_the_form_too(): void {
        [ , $conv, $account ] = $this->conversation( '["CB"]', true );
        wp_set_current_user( $account );
        $this->assertSame( 200, $this->send( 'PUT', $conv, 'form/player/preparation', [] )[1], 'ready, with nothing filled in' );

        wp_set_current_user( $this->trainer );
        $this->assertNull( $this->send( 'GET', $conv, 'form', [] )[0]['data']['state']['form_switch_to'] );
        [ $data, $status ] = $this->send( 'PATCH', $conv, 'form', [ 'form_key' => self::GOALKEEPER ] );
        $this->assertSame( 409, $status );
        $this->assertSame( 'form_fixed', $data['errors'][0]['code'] );
    }

    public function test_a_talk_marked_not_held_takes_no_switch(): void {
        global $wpdb;
        [ , $conv ] = $this->conversation( '["CB"]' );
        $wpdb->update( "{$this->p}tt_pdp_conversations", [ 'not_held_at' => gmdate( 'Y-m-d H:i:s' ), 'not_held_reason' => 'Ziek' ], [ 'id' => $conv ] );

        wp_set_current_user( $this->trainer );
        $this->assertNull( $this->send( 'GET', $conv, 'form', [] )[0]['data']['state']['form_switch_to'] );
        [ $data, $status ] = $this->send( 'PATCH', $conv, 'form', [ 'form_key' => self::GOALKEEPER ] );
        $this->assertSame( 409, $status );
        $this->assertSame( 'conversation_not_held', $data['errors'][0]['code'] );
    }

    // ---- a form switched under a write ---------------------------------------------

    public function test_a_write_that_finds_another_form_pinned_is_refused(): void {
        $method = new \ReflectionMethod( \TT\Modules\Pdp\Rest\PdpConversationFormRestController::class, 'pin' );
        $method->setAccessible( true );
        $definition = PdpFormRegistry::definition( self::OUTFIELD );
        $this->assertNotNull( $definition );

        $this->assertNull( $method->invoke( null, [ 'form_key' => self::OUTFIELD ], 1, $definition ), 'the same form: nothing to do' );
        $refused = $method->invoke( null, [ 'form_key' => self::GOALKEEPER ], 1, $definition );
        $this->assertInstanceOf( \WP_REST_Response::class, $refused );
        $this->assertSame( 409, $refused->get_status() );
        $this->assertSame( 'form_changed', $refused->get_data()['errors'][0]['code'] );
    }

    public function test_sign_off_against_a_form_switched_meanwhile_writes_nothing(): void {
        global $wpdb;
        [ $player, $conv ] = $this->conversation( '["CB"]' );
        wp_set_current_user( $this->trainer );
        $this->assertSame( 200, $this->send( 'PATCH', $conv, 'form/coach', [ 'scores' => [ 'aannames' => 3 ] ] )[1] );

        // The sign-off loaded this row; then the switch landed.
        $stale = $this->row( $conv );
        $this->assertSame( 200, $this->send( 'PATCH', $conv, 'form', [ 'form_key' => self::GOALKEEPER ] )[1] );

        $result = \TT\Modules\Pdp\Forms\PdpFormSignoff::signOff( $this->trainer, $stale, $player );
        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'form_changed', $result->get_error_code() );
        $this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->p}tt_evaluations WHERE pdp_conversation_id = %d", $conv ) ), 'no evaluation' );
        $this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->p}tt_goals WHERE player_id = %d", $player ) ), 'no goals' );
        $this->assertSame( '', (string) $this->row( $conv )->coach_signoff_at, 'not signed off' );
    }

    // ---- the rate card's primary position -----------------------------------------

    public function test_the_rate_card_shows_no_position_for_an_empty_list(): void {
        $method = new \ReflectionMethod( \TT\Modules\PersonaDashboard\Widgets\RateCardHeroWidget::class, 'primaryPosition' );
        $method->setAccessible( true );
        $this->assertSame( '', $method->invoke( null, (object) [ 'preferred_positions' => '[]' ] ), 'used to print the literal "[]"' );
        $this->assertSame( LabelTranslator::positionLabel( 'CB' ), $method->invoke( null, (object) [ 'preferred_positions' => '["","CB"]' ] ), 'a blank first code gives the next one' );
    }

    // ---- versions ------------------------------------------------------------------

    public function test_the_pinned_version_stays_when_a_new_version_ships(): void {
        [ , $pinned_conv ] = $this->conversation( '["CB"]' );
        wp_set_current_user( $this->trainer );
        $this->assertSame( 200, $this->send( 'PATCH', $pinned_conv, 'form/coach', [ 'scores' => [ 'aannames' => 3 ] ] )[1] );

        $this->shipVersion2();
        $this->assertSame( 2, PdpFormRegistry::latestVersion( self::OUTFIELD ), 'precondition: v2 ships' );

        [ $data ] = $this->send( 'GET', $pinned_conv, 'form', [] );
        $this->assertSame( 1, $data['data']['state']['form_version'], 'a conversation already held on v1 keeps v1' );
        $this->assertSame( 'Aannames', $this->itemLabel( $data['data']['form']['definition'], 'aannames' ) );

        [ , $new_conv ] = $this->conversation( '["CB"]' );
        [ $data ] = $this->send( 'GET', $new_conv, 'form', [] );
        $this->assertSame( 2, $data['data']['state']['form_version'], 'a new conversation takes the new version' );
        $this->assertSame( 'Aannames (v2)', $this->itemLabel( $data['data']['form']['definition'], 'aannames' ) );
    }

    // ---- fixtures --------------------------------------------------------------------

    /** A copy of outfield v1 as v2, with one label changed; removed in tear_down. */
    private function shipVersion2(): void {
        $dir  = PdpFormRegistry::directory() . '/' . self::OUTFIELD;
        $code = (string) file_get_contents( $dir . '/v1.php' );
        $code = str_replace( "'version'         => 1,", "'version'         => 2,", $code );
        $code = str_replace( "'label' => 'Aannames',", "'label' => 'Aannames (v2)',", $code );
        $path = $dir . '/v2.php';
        file_put_contents( $path, $code );
        $this->added[] = $path;
        PdpFormRegistry::reset();
    }

    /**
     * @param array<string,mixed> $definition
     */
    private function itemLabel( array $definition, string $key ): string {
        foreach ( PdpFormRegistry::items( $definition ) as $item ) {
            if ( $item['key'] === $key ) return $item['label'];
        }
        return '';
    }

    private function switchControl( int $conv ): string {
        $method = new \ReflectionMethod( \TT\Modules\Pdp\Frontend\FrontendPdpManageView::class, 'renderFormSwitch' );
        $method->setAccessible( true );
        ob_start();
        $method->invoke( null, $conv, get_current_user_id() );
        return (string) ob_get_clean();
    }

    /**
     * A player with these positions, a PDP file and its first conversation.
     *
     * @return array{0:int,1:int,2:int} player id, conversation id, the player's account (0 unless asked)
     */
    private function conversation( ?string $positions, bool $with_account = false ): array {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_teams", [ 'club_id' => $this->club, 'name' => 'O17-1' ] );
        $team = (int) $wpdb->insert_id;
        $wpdb->insert( "{$this->p}tt_players", [
            'club_id' => $this->club, 'team_id' => $team, 'first_name' => 'Sem', 'last_name' => 'de Vries',
            'status' => 'active', 'wp_user_id' => null, 'preferred_positions' => $positions,
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
        $conv = (int) $wpdb->insert_id;

        $account = 0;
        if ( $with_account ) {
            $account = (int) self::factory()->user->create( [ 'role' => 'tt_player' ] );
            $wpdb->update( "{$this->p}tt_players", [ 'wp_user_id' => $account ], [ 'id' => $player ] );
        }
        return [ $player, $conv, $account ];
    }

    private function playerOf( int $conv ): int {
        return \TT\Modules\Pdp\Prep\PdpPrepAccess::playerFor( $conv );
    }

    private function row( int $conv ): object {
        $row = ( new \TT\Modules\Pdp\Repositories\PdpConversationsRepository() )->find( $conv );
        $this->assertNotNull( $row );
        return $row;
    }

    /**
     * @param array<string,mixed> $body
     * @return array{0:array<string,mixed>,1:int}
     */
    private function send( string $method, int $conv, string $route, array $body ): array {
        $request = new WP_REST_Request( $method, '/talenttrack/v1/pdp-conversations/' . $conv . '/' . $route );
        if ( $body !== [] ) {
            $request->set_header( 'content-type', 'application/json' );
            $request->set_body( (string) wp_json_encode( $body ) );
        }
        $response = rest_get_server()->dispatch( $request );
        $data     = $response->get_data();
        return [ is_array( $data ) ? $data : [], (int) $response->get_status() ];
    }
}
