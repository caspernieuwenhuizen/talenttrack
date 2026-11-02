<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\CustomFields\CustomFieldsRepository;
use TT\Infrastructure\CustomFields\CustomValuesRepository;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RecordRedactor;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Shared\Frontend\FrontendPlayersManageView;

/**
 * #4691 / #4736 — the remaining player surfaces follow the sensitive-field
 * registry: the potential overview's rows (keyed `player_id`), the player
 * card at `?tt_view=players&player_id=N`, a guardian's person record, and
 * the player peek card.
 *
 * Every refusal is paired with a reader who keeps the value, and the
 * observer's read of the record is asserted first.
 */
final class PlayerSurfacesMaskingTest extends WP_UnitTestCase {

    private const DOB          = '2011-09-17';
    private const GUARDIAN     = 'ouder.4691@example.org';
    private const CUSTOM_VALUE = 'Tweede contact: oma Jansen';

    private int $club     = 0;
    private int $team     = 0;
    private int $player   = 0;
    private int $observer = 0;
    private int $hod      = 0;
    private int $coach    = 0;
    private int $parent   = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb, $wp_rest_server;
        $p          = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();

        $roles = new RolesService();
        $roles->installRoles();
        $roles->ensureCapabilities();
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();

        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $this->club, 'name' => 'JO15-1' ] );
        $this->team = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_players", [
            'club_id'       => $this->club,
            'team_id'       => $this->team,
            'first_name'    => 'Daan',
            'last_name'     => 'Smit',
            'status'        => 'active',
            'date_of_birth' => self::DOB,
        ] );
        $this->player = (int) $wpdb->insert_id;

        $this->observer = (int) self::factory()->user->create( [ 'role' => 'tt_readonly_observer' ] );
        $this->hod      = (int) self::factory()->user->create( [ 'role' => 'tt_head_dev' ] );
        $this->coach    = $this->makeHeadCoach();
        $this->parent   = (int) self::factory()->user->create( [ 'role' => 'tt_parent', 'user_email' => self::GUARDIAN ] );
        $wpdb->insert( "{$p}tt_player_parents", [
            'club_id' => $this->club, 'player_id' => $this->player, 'parent_user_id' => $this->parent,
        ] );
        AuthorizationService::flushCache();

        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        $this->assertTrue( AuthorizationService::canViewPlayer( $this->observer, $this->player ) );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        unset( $_GET['player_id'] );
        wp_set_current_user( 0 );
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        parent::tear_down();
    }

    // ---- the family rule reads the canonical resolver ----------------

    public function test_a_guardian_whose_access_ended_does_not_keep_the_date(): void {
        global $wpdb;
        $this->assertFalse( RecordRedactor::isMasked( 'players', 'date_of_birth', $this->parent, $this->player ), 'linked, live child' );

        $wpdb->update( "{$wpdb->prefix}tt_players", [ 'status' => 'released' ], [ 'id' => $this->player ] );
        AuthorizationService::flushCache();
        $this->assertTrue( RecordRedactor::isMasked( 'players', 'date_of_birth', $this->parent, $this->player ), 'released child' );
    }

    // ---- a) the potential overview ----------------------------------

    public function test_potential_overview_rows_follow_the_registry_by_player_id(): void {
        $route = '/talenttrack/v1/reports/potential-overview';
        $row   = [ 'player_id' => $this->player, 'team_id' => $this->team, 'date_of_birth' => self::DOB, 'birth_year' => 2011, 'band' => '' ];

        wp_set_current_user( $this->observer );
        $out = RecordRedactor::redactRows( 'players', [ $row ], $this->observer, 'player_id' );
        $this->assertArrayNotHasKey( 'date_of_birth', $out[0] );
        $this->assertSame( 2011, $out[0]['birth_year'] );

        // The safety net, keyed on the route's own id_key.
        $response = new WP_REST_Response( [ 'success' => true, 'data' => [ 'rows' => [ $row ] ], 'errors' => [] ] );
        $response->set_matched_route( $route );
        $data = (array) RecordRedactor::filterResponse( $response, rest_get_server(), new WP_REST_Request( 'GET', $route ) )->get_data();
        $this->assertArrayNotHasKey( 'date_of_birth', $data['data']['rows'][0] );

        $kept = RecordRedactor::redactRows( 'players', [ $row ], $this->hod, 'player_id' );
        $this->assertSame( self::DOB, $kept[0]['date_of_birth'] ?? null, 'HoD keeps the date' );
    }

    public function test_the_potential_overview_route_redacts_its_rows(): void {
        wp_set_current_user( $this->hod );
        AuthorizationService::flushCache();
        $request = new WP_REST_Request( 'GET', '/talenttrack/v1/reports/potential-overview' );
        $request->set_param( 'scope', 'team' );
        $request->set_param( 'team_id', $this->team );
        $response = $this->serve( $request );
        $this->assertSame( 200, $response->get_status() );
        $body = (string) wp_json_encode( $response->get_data() );
        $this->assertStringContainsString( self::DOB, $body, 'HoD keeps the date' );
        $this->assertStringContainsString( '"birth_year":2011', $body );
    }

    // ---- b) the player card at ?tt_view=players&player_id=N ---------

    public function test_the_player_card_keeps_custom_fields_for_staff_only(): void {
        $field_id = ( new CustomFieldsRepository() )->create( [
            'entity_type' => CustomFieldsRepository::ENTITY_PLAYER,
            'field_key'   => 'second_contact',
            'label'       => 'Second contact',
            'field_type'  => CustomFieldsRepository::TYPE_TEXT,
            'is_required' => 0,
            'is_active'   => 1,
        ] );
        ( new CustomValuesRepository() )->upsert( CustomFieldsRepository::ENTITY_PLAYER, $this->player, $field_id, self::CUSTOM_VALUE );

        $this->assertStringContainsString( 'oma Jansen', $this->renderCard( $this->hod ), 'HoD sees the club fields' );

        $html = $this->renderCard( $this->observer );
        $this->assertStringContainsString( 'Smit', $html, 'the card renders for the observer' );
        $this->assertStringNotContainsString( 'oma Jansen', $html );
    }

    // ---- c) a guardian's person record ------------------------------

    public function test_a_guardians_person_record_keeps_contact_for_the_guardian_and_staff(): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_people", [
            'club_id'    => $this->club,
            'first_name' => 'Ouder',
            'last_name'  => 'Smit',
            'email'      => self::GUARDIAN,
            'phone'      => '0611122233',
            'role_type'  => 'parent',
            'wp_user_id' => $this->parent,
            'status'     => 'active',
        ] );
        $guardian = (int) $wpdb->insert_id;

        $wpdb->insert( "{$wpdb->prefix}tt_people", [
            'club_id'    => $this->club,
            'first_name' => 'Fysio',
            'last_name'  => 'Staf',
            'email'      => 'fysio.4691@example.org',
            'role_type'  => 'staff',
            'status'     => 'active',
        ] );
        $staff_person = (int) $wpdb->insert_id;

        $this->assertStringContainsString( self::GUARDIAN, $this->personBody( $this->hod, $guardian ), 'HoD over REST' );
        // The head coach of the child's team and the guardian themselves keep
        // it too; asked of the rule, because the people route is not theirs.
        foreach ( [ 'head coach' => $this->coach, 'the guardian' => $this->parent ] as $label => $uid ) {
            AuthorizationService::flushCache();
            $this->assertFalse( RecordRedactor::isMasked( 'people', 'email', $uid, $guardian ), $label );
        }
        $other_coach = (int) self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        AuthorizationService::flushCache();
        $this->assertTrue( RecordRedactor::isMasked( 'people', 'email', $other_coach, $guardian ), 'a coach of no linked child' );

        $observer = $this->personBody( $this->observer, $guardian );
        $this->assertStringContainsString( 'Ouder', $observer, 'the observer gets the person' );
        $this->assertStringNotContainsString( self::GUARDIAN, $observer );
        $this->assertStringNotContainsString( '0611122233', $observer );

        $this->assertStringContainsString( 'fysio.4691@example.org', $this->personBody( $this->observer, $staff_person ), 'not a guardian: unchanged' );

        // The list carries the same rule.
        wp_set_current_user( $this->observer );
        AuthorizationService::flushCache();
        $list = (string) wp_json_encode( $this->serve( new WP_REST_Request( 'GET', '/talenttrack/v1/people' ) )->get_data() );
        $this->assertStringNotContainsString( self::GUARDIAN, $list );
    }

    // ---- d) the player peek card (#4736) ------------------------------

    public function test_the_peek_card_shows_the_date_to_staff_and_the_year_to_the_observer_and_a_scout(): void {
        foreach ( [ 'admin' => (int) self::factory()->user->create( [ 'role' => 'administrator' ] ), 'HoD' => $this->hod, 'head coach' => $this->coach ] as $label => $uid ) {
            $facts = $this->peekFacts( $uid );
            $this->assertSame( \TT\Shared\Dates\TTDate::date( self::DOB ), $facts['Date of birth'] ?? null, $label );
            $this->assertArrayNotHasKey( 'Birth year', $facts, $label );
        }

        $scout = (int) self::factory()->user->create( [ 'role' => 'tt_scout' ] );
        update_user_meta( $scout, 'tt_scout_player_ids', wp_json_encode( [ $this->player ] ) );
        AuthorizationService::flushCache();
        $this->assertTrue( AuthorizationService::canViewPlayer( $scout, $this->player ), 'the scout is linked' );

        foreach ( [ 'observer' => $this->observer, 'linked scout' => $scout ] as $label => $uid ) {
            $body = $this->peekBody( $uid );
            $this->assertStringContainsString( '2011', $body, $label );
            foreach ( [ self::DOB, \TT\Shared\Dates\TTDate::date( self::DOB ), date_i18n( 'd-m-Y', (int) strtotime( self::DOB ) ) ] as $form ) {
                $this->assertStringNotContainsString( $form, $body, "{$label}: no full date ({$form})" );
            }
            $this->assertSame( '2011', $this->peekFacts( $uid )['Birth year'] ?? null, $label );
        }
    }

    // ---- fixtures ------------------------------------------------------

    private function serve( WP_REST_Request $request ): WP_REST_Response {
        $server   = rest_get_server();
        $response = rest_ensure_response( $server->dispatch( $request ) );
        $response = apply_filters( 'rest_post_dispatch', $response, $server, $request );
        $this->assertInstanceOf( WP_REST_Response::class, $response );
        return $response;
    }

    private function personBody( int $user_id, int $person_id ): string {
        wp_set_current_user( $user_id );
        AuthorizationService::flushCache();
        $response = $this->serve( new WP_REST_Request( 'GET', '/talenttrack/v1/people/' . $person_id ) );
        $this->assertSame( 200, $response->get_status(), 'user ' . $user_id . ' reads the person' );
        return (string) wp_json_encode( $response->get_data() );
    }

    private function peekBody( int $user_id ): string {
        wp_set_current_user( $user_id );
        AuthorizationService::flushCache();
        $response = $this->serve( new WP_REST_Request( 'GET', '/talenttrack/v1/players/' . $this->player . '/summary' ) );
        $this->assertSame( 200, $response->get_status(), 'user ' . $user_id . ' opens the peek card' );
        return (string) wp_json_encode( $response->get_data() );
    }

    /** @return array<string, string> label => value */
    private function peekFacts( int $user_id ): array {
        $data  = (array) json_decode( $this->peekBody( $user_id ), true );
        $facts = [];
        foreach ( (array) ( $data['facts'] ?? [] ) as $fact ) {
            $facts[ (string) ( $fact['label'] ?? '' ) ] = (string) ( $fact['value'] ?? '' );
        }
        return $facts;
    }

    private function renderCard( int $user_id ): string {
        wp_set_current_user( $user_id );
        AuthorizationService::flushCache();
        $_GET['player_id'] = (string) $this->player;
        ob_start();
        FrontendPlayersManageView::render( $user_id, user_can( $user_id, 'administrator' ) );
        return (string) ob_get_clean();
    }

    private function makeHeadCoach(): int {
        global $wpdb;
        $p   = $wpdb->prefix;
        $uid = (int) self::factory()->user->create( [ 'role' => 'tt_coach' ] );

        $role_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}tt_roles WHERE role_key = %s AND club_id = %d LIMIT 1",
            'head_coach',
            $this->club
        ) );
        $this->assertGreaterThan( 0, $role_id );

        $wpdb->insert( "{$p}tt_people", [
            'club_id'    => $this->club,
            'first_name' => 'Hoofd',
            'last_name'  => 'Trainer',
            'role_type'  => 'head_coach',
            'wp_user_id' => $uid,
            'status'     => 'active',
        ] );
        $person_id = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_team_people", [
            'club_id'       => $this->club,
            'team_id'       => $this->team,
            'person_id'     => $person_id,
            'role_in_team'  => 'head_coach',
            'is_head_coach' => 1,
        ] );
        $wpdb->insert( "{$p}tt_user_role_scopes", [
            'club_id'    => $this->club,
            'person_id'  => $person_id,
            'role_id'    => $role_id,
            'scope_type' => 'team',
            'scope_id'   => $this->team,
        ] );
        AuthorizationService::flushCache();
        return $uid;
    }
}
