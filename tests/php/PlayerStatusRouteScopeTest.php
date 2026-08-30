<?php
namespace TT\Tests\Php;

use TT\Modules\Authorization\AllTeamsScope;
use WP_UnitTestCase;

/**
 * #3154 — the player-status routes look at the id they were given.
 *
 * Three routes gated on a bare `current_user_can`, took an id from the
 * path and never checked it. `tt_view_player_status` goes to both coach
 * roles, scouts and parents, and the response is the full verdict object
 * per player — not a traffic-light colour. The `POST` was the same missing
 * check on a write: its gate is a feature-flag-plus-capability call that
 * takes no player id at all.
 */
final class PlayerStatusRouteScopeTest extends WP_UnitTestCase {

    private function controllerSource(): string {
        return (string) file_get_contents(
            dirname( __DIR__, 2 ) . '/src/Infrastructure/REST/PlayerStatusRestController.php'
        );
    }

    /**
     * A permission callback that takes no `$r` cannot be checking an id.
     * That signature is the whole bug, so the signature is what is asserted.
     */
    public function test_no_id_bearing_route_has_an_argument_less_permission_callback(): void {
        $source = $this->controllerSource();

        // Capture each route's permission_callback expression.
        preg_match_all(
            "/'permission_callback'\s*=>\s*static fn\(([^)]*)\)/",
            $source,
            $matches
        );
        $this->assertNotEmpty( $matches[1], 'No permission callbacks found — the controller changed shape.' );

        // `/players/{id}/potential` is the one route that legitimately takes
        // no id: `tt_set_player_potential` goes only to Head of Development,
        // Club Admin and administrator — academy-wide roles by design, for
        // whom "any player" is the correct scope. Everything else must
        // receive the request.
        $argument_less = array_values( array_filter(
            $matches[1],
            static fn( string $args ): bool => trim( $args ) === ''
        ) );
        $this->assertLessThanOrEqual(
            1,
            count( $argument_less ),
            'A player-status route gates on a capability alone and never sees the id it was handed.'
        );
    }

    public function test_the_per_player_routes_ask_about_the_player(): void {
        $source = $this->controllerSource();
        $this->assertStringContainsString(
            'AuthorizationService::canViewPlayer(',
            $source,
            'GET /players/{id}/status no longer asks whether the caller may view that player.'
        );
        $this->assertStringContainsString(
            'AuthorizationService::canEditPlayer(',
            $source,
            'POST /players/{id}/behaviour-ratings no longer asks whether the caller may edit that player.'
        );
    }

    public function test_the_team_route_asks_about_the_team(): void {
        $this->assertStringContainsString(
            "AllTeamsScope::canReadTeamFor( get_current_user_id(), (int) \$r['id'], 'player_status' )",
            $this->controllerSource(),
            'GET /teams/{id}/player-statuses no longer checks the team id, or stopped scoping on the '
            . 'player_status entity (scoping it on `team` would refuse a persona granted academy-wide '
            . 'status read but only team-scoped team read).'
        );
    }

    /**
     * The point of lifting the predicate: one implementation, two callers.
     */
    public function test_the_cohort_board_reuses_the_shared_predicate(): void {
        $source = (string) file_get_contents(
            dirname( __DIR__, 2 ) . '/src/Infrastructure/REST/CohortBoardRestController.php'
        );
        $this->assertStringContainsString( 'AllTeamsScope::canReadTeamFor(', $source );
        $this->assertStringNotContainsString(
            'get_teams_for_coach(',
            $source,
            'CohortBoardRestController grew its own copy of the team-scope rule again.'
        );
    }

    public function test_the_shared_predicate_refuses_a_missing_id_or_entity(): void {
        $admin = self::factory()->user->create( [ 'role' => 'administrator' ] );

        $this->assertTrue( AllTeamsScope::canReadTeamFor( $admin, 4, 'player_status' ) );
        $this->assertFalse( AllTeamsScope::canReadTeamFor( $admin, 0, 'player_status' ) );
        $this->assertFalse( AllTeamsScope::canReadTeamFor( $admin, 4, '' ) );
        $this->assertFalse( AllTeamsScope::canReadTeamFor( 0, 4, 'player_status' ) );
    }

    public function test_a_caller_with_no_teams_reads_no_board(): void {
        $nobody = self::factory()->user->create( [ 'role' => 'subscriber' ] );
        $this->assertFalse( AllTeamsScope::canReadTeamFor( $nobody, 4, 'player_status' ) );
        $this->assertFalse( AllTeamsScope::canReadTeamFor( $nobody, 4, 'activities' ) );
    }
}
