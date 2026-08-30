<?php
namespace TT\Tests\Php;

use TT\Modules\TeamDevelopment\TeamChemistryAccess;
use WP_UnitTestCase;

/**
 * #3153 — no `{id}`-bearing chemistry route gates on `canAnyScope`.
 *
 * `MatrixGate::canAnyScope` answers "do you hold this permission
 * anywhere", which is the right question for a tile and the wrong one for
 * a route that takes a team id. head_coach holds `team_chemistry
 * [rc, team]`, so a team-scoped grant answered yes for every team and five
 * routes returned another squad's suggested XI, depth chart and pairings.
 */
final class TeamChemistryScopeTest extends WP_UnitTestCase {

    private function controllerSource(): string {
        return (string) file_get_contents(
            dirname( __DIR__, 2 )
            . '/src/Modules/TeamDevelopment/Rest/TeamDevelopmentRestController.php'
        );
    }

    /**
     * The five chemistry routes and the callback each must carry. Named
     * per route rather than counted, so moving a gate from one route to
     * another cannot pass by arithmetic.
     *
     * @var array<string, string>
     */
    private const EXPECTED_GATES = [
        "'/teams/(?P<id>\\d+)/chemistry'"         => 'can_view_chemistry_team',
        "'/teams/(?P<id>\\d+)/chemistry/preview'" => 'can_view_chemistry_team',
        "'/players/(?P<id>\\d+)/team-fit'"        => 'can_view_chemistry_player',
        "'/pairings/(?P<id>\\d+)'"                => 'can_manage_pairing',
    ];

    public function test_each_id_bearing_chemistry_route_gates_on_a_team_aware_callback(): void {
        $source = $this->controllerSource();

        foreach ( self::EXPECTED_GATES as $route => $callback ) {
            $at = strpos( $source, $route );
            $this->assertNotFalse( $at, "Route {$route} is gone or was renamed." );
            // The permission_callback for a route sits within the register
            // call that follows its path literal.
            $block = substr( $source, $at, 900 );
            $this->assertStringContainsString(
                $callback,
                $block,
                "Route {$route} no longer gates on {$callback} — a club-wide "
                . 'answer is not an answer about one team.'
            );
        }
    }

    /**
     * `/teams/{id}/pairings` carries both a read and a write, so it is
     * asserted as a pair rather than through the map above.
     */
    public function test_the_pairings_collection_gates_read_and_write_per_team(): void {
        $source = $this->controllerSource();
        $at     = strpos( $source, "'/teams/(?P<id>\\d+)/pairings'" );
        $this->assertNotFalse( $at );
        $block = substr( $source, $at, 900 );
        $this->assertStringContainsString( 'can_view_chemistry_team', $block );
        $this->assertStringContainsString( 'can_manage_chemistry_team', $block );
    }

    /**
     * The tile-visibility callers still need the scope-less pair, so they
     * stay — but nothing that receives an id may use them.
     */
    public function test_no_route_registration_still_points_at_the_scope_less_pair(): void {
        $source = $this->controllerSource();
        $this->assertSame(
            0,
            preg_match_all( "/'permission_callback'\s*=>\s*\[\s*__CLASS__,\s*'can_(view|manage)_chemistry'\s*\]/", $source ),
            'A chemistry route went back to the canAnyScope gate.'
        );
    }

    public function test_the_predicate_refuses_a_missing_team(): void {
        $admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $this->assertFalse( TeamChemistryAccess::canReadChemistryForTeam( $admin, 0 ) );
        $this->assertFalse( TeamChemistryAccess::canManageChemistryForTeam( $admin, 0 ) );
        $this->assertFalse( TeamChemistryAccess::canReadChemistryForTeam( 0, 7 ) );
    }

    public function test_a_caller_with_no_chemistry_grant_reads_no_team(): void {
        $nobody = self::factory()->user->create( [ 'role' => 'subscriber' ] );
        $this->assertFalse( TeamChemistryAccess::canReadChemistryForTeam( $nobody, 7 ) );
        $this->assertFalse( TeamChemistryAccess::canManageChemistryForTeam( $nobody, 7 ) );
    }
}
