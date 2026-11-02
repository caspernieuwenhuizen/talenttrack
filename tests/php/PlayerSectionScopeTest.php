<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Players\PlayerParentVisibilityRepository;
use TT\Infrastructure\REST\PlayerEvaluationsRestController;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Authorization\PersonaResolver;
use TT\Shared\Frontend\FrontendPlayerDetailView;

/**
 * #3958 — opening a player's record is not reading every section of it.
 *
 * `canViewPlayer()` answers "may they open this player". Each section —
 * evaluations, measurements, status, the journey, injuries — is its own
 * matrix entity, and `AuthorizationService::canReadPlayerSection()` asks
 * that entity about THIS player: global, the player's team, or the player.
 *
 * Every refusal below sits beside a grant in the same test. A test that only
 * ever expects a refusal cannot tell "narrowed correctly" from "refused
 * everything" (#3913, #3922).
 */
final class PlayerSectionScopeTest extends WP_UnitTestCase {

    private int $teamA   = 0;
    private int $teamB   = 0;
    private int $playerA = 0;
    private int $playerB = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;

        $roles = new RolesService();
        $roles->installRoles();
        $roles->ensureCapabilities();
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();

        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        $club = (int) CurrentClub::id();
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => $club, 'name' => 'Section A', 'age_group' => 'U14' ] );
        $this->teamA = (int) $wpdb->insert_id;
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => $club, 'name' => 'Section B', 'age_group' => 'U15' ] );
        $this->teamB = (int) $wpdb->insert_id;

        $this->playerA = $this->makePlayer( 'Alpha', $this->teamA );
        $this->playerB = $this->makePlayer( 'Bravo', $this->teamB );

        $this->assertGreaterThan( 0, $this->playerA, 'the fixture must write the player' );
        $this->assertGreaterThan( 0, $this->playerB, 'the fixture must write the player' );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    // ── the read-only observer ────────────────────────────────────────

    public function test_the_observer_opens_the_record_and_its_evaluations(): void {
        $observer = $this->makeUser( 'tt_readonly_observer', 'readonly_observer' );
        wp_set_current_user( $observer );

        $this->assertSame( 200, $this->status( "/talenttrack/v1/players/{$this->playerA}" ) );
        $this->assertSame( 200, $this->status( "/talenttrack/v1/players/{$this->playerA}/evaluations" ) );
        $this->assertSame( 200, $this->status( "/talenttrack/v1/players/{$this->playerB}/evaluations" ) );
    }

    public function test_the_observer_lists_every_player(): void {
        $observer = $this->makeUser( 'tt_readonly_observer', 'readonly_observer' );
        wp_set_current_user( $observer );

        $response = rest_do_request( new WP_REST_Request( 'GET', '/talenttrack/v1/players' ) );
        $this->assertSame( 200, (int) $response->get_status() );

        $ids = $this->collectIds( $response->get_data() );
        $this->assertContains( $this->playerA, $ids );
        $this->assertContains( $this->playerB, $ids );
    }

    public function test_the_observer_is_refused_sections_it_holds_no_row_for(): void {
        $observer = $this->makeUser( 'tt_readonly_observer', 'readonly_observer' );
        wp_set_current_user( $observer );

        // The grant, on the same player, first.
        $this->assertSame( 200, $this->status( "/talenttrack/v1/players/{$this->playerA}/evaluations" ) );
        $this->assertTrue( AuthorizationService::canReadPlayerSection( $observer, $this->playerA, 'evaluations' ) );

        $this->assertSame( 403, $this->status( "/talenttrack/v1/players/{$this->playerA}/measurements" ) );
        $this->assertSame( 403, $this->status( "/talenttrack/v1/players/{$this->playerA}/injuries" ) );
        $this->assertSame( 403, $this->status( "/talenttrack/v1/players/{$this->playerA}/timeline" ) );

        foreach ( [ 'measurements', 'player_injuries', 'safeguarding_notes', 'media', 'player_status', 'prospects' ] as $entity ) {
            $this->assertFalse(
                AuthorizationService::canReadPlayerSection( $observer, $this->playerA, $entity ),
                "the observer holds no {$entity} row; opening the record does not grant it"
            );
        }
    }

    public function test_the_observer_gains_no_write(): void {
        $observer = $this->makeUser( 'tt_readonly_observer', 'readonly_observer' );

        $this->assertTrue( AuthorizationService::canViewPlayer( $observer, $this->playerA ) );
        $this->assertFalse( AuthorizationService::canEditPlayer( $observer, $this->playerA ) );
        $this->assertFalse( AuthorizationService::canEvaluatePlayer( $observer, $this->playerA ) );
    }

    /**
     * The profile's staff cards ask their own entity, not "is this staff":
     * the Behaviour & potential card asks `player_status`, the Discovery
     * card `prospects`. A head of development holds both; the observer
     * holds neither.
     */
    public function test_the_profile_staff_cards_follow_their_own_entity(): void {
        $hod      = $this->makeUser( 'tt_head_dev', 'head_of_development' );
        $observer = $this->makeUser( 'tt_readonly_observer', 'readonly_observer' );

        $hod_html = $this->renderProfile( $hod, $this->playerA );
        $this->assertStringContainsString( 'tt-bp-card', $hod_html, 'the head of development must see the card, or the refusal below is vacuous' );
        $this->assertStringContainsString( 'Discovery</h3>', $hod_html );

        $observer_html = $this->renderProfile( $observer, $this->playerA );
        $this->assertStringContainsString( 'tt-player-profile-grid', $observer_html, 'the observer must reach the profile itself' );
        $this->assertStringNotContainsString( 'tt-bp-card', $observer_html );
        $this->assertStringNotContainsString( 'Discovery</h3>', $observer_html );
    }

    // ── scout ─────────────────────────────────────────────────────────

    public function test_a_scout_reads_evaluations_of_a_linked_player_only(): void {
        $scout = $this->makeUser( 'tt_scout', 'scout' );
        update_user_meta( $scout, 'tt_scout_player_ids', wp_json_encode( [ $this->playerA ] ) );
        AuthorizationService::flushCache();
        wp_set_current_user( $scout );

        $this->assertTrue( PlayerEvaluationsRestController::can_read( $this->evalRequest( $this->playerA ) ) );
        $this->assertFalse(
            PlayerEvaluationsRestController::can_read( $this->evalRequest( $this->playerB ) ),
            'a scout reads evaluations for the players it is linked to, not academy-wide (#1378, #3807)'
        );
    }

    // ── head coach ────────────────────────────────────────────────────

    public function test_a_head_coach_reads_their_squad_and_no_other(): void {
        $coach = $this->makeHeadCoach( $this->teamA );
        wp_set_current_user( $coach );

        $this->assertTrue( AuthorizationService::canViewPlayer( $coach, $this->playerA ) );
        $this->assertFalse( AuthorizationService::canViewPlayer( $coach, $this->playerB ) );

        foreach ( [ 'evaluations', 'measurements', 'player_status', 'player_injuries', 'player_timeline' ] as $entity ) {
            $this->assertTrue(
                AuthorizationService::canReadPlayerSection( $coach, $this->playerA, $entity ),
                "the head coach holds {$entity} on their own team"
            );
            $this->assertFalse(
                AuthorizationService::canReadPlayerSection( $coach, $this->playerB, $entity ),
                "a team-scoped {$entity} grant must not reach another team's player"
            );
        }

        $this->assertSame( 200, $this->status( "/talenttrack/v1/players/{$this->playerA}/measurements" ) );
        $this->assertSame( 403, $this->status( "/talenttrack/v1/players/{$this->playerB}/measurements" ) );
        $this->assertSame( 200, $this->status( "/talenttrack/v1/players/{$this->playerA}/evaluations" ) );
        $this->assertSame( 403, $this->status( "/talenttrack/v1/players/{$this->playerB}/evaluations" ) );
    }

    // ── parent ────────────────────────────────────────────────────────

    public function test_a_parent_reads_their_own_child_and_no_other(): void {
        $parent = $this->makeUser( 'tt_parent', 'parent' );
        $this->linkGuardian( $parent, $this->playerA );
        wp_set_current_user( $parent );

        $this->assertSame( 200, $this->status( "/talenttrack/v1/players/{$this->playerA}/evaluations" ) );
        $this->assertSame( 403, $this->status( "/talenttrack/v1/players/{$this->playerB}/evaluations" ) );
        $this->assertSame( 200, $this->status( "/talenttrack/v1/players/{$this->playerA}/measurements" ) );
        $this->assertSame( 403, $this->status( "/talenttrack/v1/players/{$this->playerB}/measurements" ) );
    }

    public function test_a_section_the_child_hid_is_refused_to_the_parent(): void {
        $parent = $this->makeUser( 'tt_parent', 'parent' );
        $this->linkGuardian( $parent, $this->playerA );

        $this->assertTrue(
            ( new PlayerParentVisibilityRepository() )->setVisibility( $this->playerA, 'measurements', false ),
            'the fixture must write the preference'
        );
        AuthorizationService::flushCache();
        wp_set_current_user( $parent );

        $this->assertSame( 200, $this->status( "/talenttrack/v1/players/{$this->playerA}/evaluations" ), 'a section the child still shares' );
        $this->assertSame( 403, $this->status( "/talenttrack/v1/players/{$this->playerA}/measurements" ), 'the section the child hid' );
    }

    // ── the player themselves ─────────────────────────────────────────

    public function test_the_player_reads_their_own_evaluations_through_the_my_twin(): void {
        $uid = $this->makeUser( 'tt_player', 'player' );
        global $wpdb;
        $wpdb->update( "{$wpdb->prefix}tt_players", [ 'wp_user_id' => $uid ], [ 'id' => $this->playerA ] );
        AuthorizationService::flushCache();

        $this->assertTrue(
            AuthorizationService::canReadPlayerSection( $uid, $this->playerA, 'evaluations' ),
            'the player holds `my_evaluations` at self, which #1482 split from `evaluations`'
        );
        $this->assertTrue( AuthorizationService::canReadPlayerSection( $uid, $this->playerA, 'measurements' ) );
        $this->assertFalse(
            AuthorizationService::canReadPlayerSection( $uid, $this->playerA, 'training_exposure' ),
            'the player persona holds no training_exposure row (D16)'
        );
        $this->assertFalse(
            AuthorizationService::canReadPlayerSection( $uid, $this->playerB, 'evaluations' ),
            'self scope is the own record only'
        );
    }

    // ── helpers ────────────────────────────────────────────────────────

    private function makeUser( string $wp_role, string $expected_persona ): int {
        $uid = self::factory()->user->create( [ 'role' => $wp_role ] );
        $this->assertContains(
            $expected_persona,
            PersonaResolver::personasFor( $uid ),
            "{$wp_role} must resolve to {$expected_persona} or nothing below means anything"
        );
        AuthorizationService::flushCache();
        return $uid;
    }

    private function makeHeadCoach( int $team_id ): int {
        global $wpdb;
        $p   = $wpdb->prefix;
        $uid = self::factory()->user->create( [ 'role' => 'tt_coach' ] );

        $wpdb->insert( "{$p}tt_people", [
            'club_id'    => (int) CurrentClub::id(),
            'first_name' => 'Hoofd',
            'last_name'  => 'Trainer',
            'role_type'  => 'head_coach',
            'wp_user_id' => $uid,
            'status'     => 'active',
        ] );
        $person_id = (int) $wpdb->insert_id;
        $this->assertGreaterThan( 0, $person_id, 'the fixture must write the person' );

        $wpdb->insert( "{$p}tt_team_people", [
            'team_id'       => $team_id,
            'person_id'     => $person_id,
            'role_in_team'  => 'head_coach',
            'is_head_coach' => 1,
        ] );
        $wpdb->insert( "{$p}tt_user_role_scopes", [
            'person_id'  => $person_id,
            'role_id'    => 1,
            'scope_type' => 'team',
            'scope_id'   => $team_id,
        ] );

        $this->assertContains( 'head_coach', PersonaResolver::personasFor( $uid ) );
        AuthorizationService::flushCache();
        return $uid;
    }

    private function makePlayer( string $last, int $team_id ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id'       => (int) CurrentClub::id(),
            'first_name'    => 'Section',
            'last_name'     => $last,
            'team_id'       => $team_id,
            'status'        => 'active',
            'date_of_birth' => '2011-04-02',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function linkGuardian( int $user_id, int $player_id ): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_player_parents", [
            'club_id'        => (int) CurrentClub::id(),
            'player_id'      => $player_id,
            'parent_user_id' => $user_id,
        ] );
        AuthorizationService::flushCache();
        $this->assertTrue(
            AuthorizationService::canViewPlayer( $user_id, $player_id ),
            'the guardian link must resolve, or every refusal is vacuous'
        );
    }

    private function status( string $route ): int {
        return (int) rest_do_request( new WP_REST_Request( 'GET', $route ) )->get_status();
    }

    private function evalRequest( int $player_id ): WP_REST_Request {
        $request = new WP_REST_Request( 'GET', '/talenttrack/v1/players/' . $player_id . '/evaluations' );
        $request->set_param( 'id', $player_id );
        return $request;
    }

    private function renderProfile( int $user_id, int $player_id ): string {
        wp_set_current_user( $user_id );
        AuthorizationService::flushCache();
        $had = $_GET['tab'] ?? null;
        unset( $_GET['tab'] );

        ob_start();
        FrontendPlayerDetailView::render( $player_id, $user_id, false, 'profile' );
        $html = (string) ob_get_clean();

        if ( $had !== null ) $_GET['tab'] = $had;
        return $html;
    }

    /**
     * Every `id` anywhere in a REST payload, as ints.
     *
     * @param mixed $data
     * @return list<int>
     */
    private function collectIds( $data ): array {
        $ids = [];
        if ( is_object( $data ) ) $data = (array) $data;
        if ( ! is_array( $data ) ) return $ids;
        foreach ( $data as $key => $value ) {
            if ( $key === 'id' && is_scalar( $value ) ) {
                $ids[] = (int) $value;
            } elseif ( is_array( $value ) || is_object( $value ) ) {
                $ids = array_merge( $ids, $this->collectIds( $value ) );
            }
        }
        return $ids;
    }
}
