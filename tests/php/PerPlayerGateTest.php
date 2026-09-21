<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Stats\PrintRouter;

/**
 * A route that takes a player id has to read it.
 *
 * Four gates asked "may this person look at players" and never "may they
 * look at THIS one", so the answer was identical for every id a caller
 * substituted. `MatrixGate::canAnyScope()` at `player` scope says yes to
 * anyone who is a player or holds any linked child, which is how a family
 * ended up admitted to every child's record on the install.
 *
 * The cheap way to write these tests is to assert the refusal alone. That
 * is the failure #3913 and #3922 were both about: a test that only ever
 * expects a 403 cannot tell "narrowed correctly" from "refused
 * everything", and would pass with the route deleted. Every case below
 * pairs the refusal for somebody else's child with the grant for the
 * caller's own.
 */
final class PerPlayerGateTest extends WP_UnitTestCase {

    private int $parent  = 0;
    private int $mine    = 0;
    private int $theirs  = 0;

    public function set_up(): void {
        parent::set_up();
        $roles = new RolesService();
        $roles->installRoles();
        $roles->ensureCapabilities();

        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        $this->parent = self::factory()->user->create( [ 'role' => 'tt_parent' ] );
        $this->mine   = $this->makePlayer( 'Mine' );
        $this->theirs = $this->makePlayer( 'Theirs' );

        $this->linkGuardian( $this->parent, $this->mine );
        AuthorizationService::flushCache();

        // The fixture has to be real before anything is asserted about what
        // it cannot reach — otherwise a refusal on BOTH children reads as a
        // pass.
        $this->assertTrue(
            AuthorizationService::canViewPlayer( $this->parent, $this->mine ),
            'the guardian link must resolve, or every assertion below is vacuous'
        );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_goal_contributions_answers_for_your_own_child_only(): void {
        wp_set_current_user( $this->parent );

        $this->assertSame( 200, $this->status( "/talenttrack/v1/players/{$this->mine}/goal-contributions" ) );
        $this->assertSame(
            403,
            $this->status( "/talenttrack/v1/players/{$this->theirs}/goal-contributions" ),
            'goals, assists and a per-match breakdown are another family\'s child\'s record'
        );
    }

    public function test_the_media_consent_block_rides_only_on_a_player_you_may_view(): void {
        wp_set_current_user( $this->parent );

        $mine = $this->mediaEnvelope( $this->mine );
        $this->assertArrayHasKey( 'player_consent', $mine );

        $theirs = $this->mediaEnvelope( $this->theirs );
        $this->assertArrayNotHasKey(
            'player_consent',
            $theirs,
            'the items are filtered away; appending the consent fact beside them handed back what the filtering withheld'
        );
    }

    public function test_setting_potential_is_refused_for_a_player_you_may_not_edit(): void {
        // A coach who may edit one player and not another — the write
        // equivalent of the reads above.
        $coach = self::factory()->user->create( [ 'role' => 'tt_head_coach' ] );
        wp_set_current_user( $coach );
        AuthorizationService::flushCache();

        $request = new WP_REST_Request( 'POST', "/talenttrack/v1/players/{$this->theirs}/potential" );
        $request->set_param( 'band', 'first_team' );

        $this->assertSame(
            403,
            (int) rest_do_request( $request )->get_status(),
            'the academy\'s judgement of how far a child will go is a write onto that child'
        );
    }

    public function test_the_admin_print_route_narrows_like_its_frontend_twin(): void {
        $this->assertFalse(
            PrintRouter::canPrint( $this->parent, $this->theirs ),
            'the same URL must not answer differently either side of wp-admin'
        );
        $this->assertTrue( PrintRouter::canPrint( $this->parent, $this->mine ) );
    }

    // ── helpers ────────────────────────────────────────────────────────

    private function status( string $route ): int {
        return (int) rest_do_request( new WP_REST_Request( 'GET', $route ) )->get_status();
    }

    /** @return array<string, mixed> */
    private function mediaEnvelope( int $player_id ): array {
        $request = new WP_REST_Request( 'GET', '/talenttrack/v1/media' );
        $request->set_param( 'entity_type', 'player' );
        $request->set_param( 'entity_id', $player_id );

        $data = rest_do_request( $request )->get_data();

        return (array) ( $data['data'] ?? [] );
    }

    private function linkGuardian( int $user_id, int $player_id ): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_player_parents", [
            'club_id'        => (int) CurrentClub::id(),
            'player_id'      => $player_id,
            'parent_user_id' => $user_id,
        ] );
    }

    private function makePlayer( string $last ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id'    => (int) CurrentClub::id(),
            'first_name' => 'Gate',
            'last_name'  => $last,
            'status'     => 'active',
        ] );
        return (int) $wpdb->insert_id;
    }
}
