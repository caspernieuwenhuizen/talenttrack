<?php
namespace TT\Tests\Php;

use TT\Modules\TeamDevelopment\TeamChemistryAccess;
use WP_UnitTestCase;

/**
 * #3181 — no `{id}`-bearing blueprint / formation route gates on
 * `hasAuthorityAnyScope`.
 *
 * #3153 scoped the five chemistry routes and left their neighbours alone.
 * `canRead()` / `canManage()` answer "do you hold `team_chemistry`
 * anywhere", which a **team**-scoped grant satisfies for every team — so
 * `GET /blueprints/{id}` returned any squad's full match-day lineup (slot
 * label, tier, player id) and its write siblings let a coach rewrite or
 * delete another squad's.
 *
 * Sibling of TeamChemistryScopeTest, asserted per route rather than
 * counted so moving a gate cannot pass by arithmetic.
 */
final class TeamBlueprintScopeTest extends WP_UnitTestCase {

    private function controllerSource(): string {
        return (string) file_get_contents(
            dirname( __DIR__, 2 )
            . '/src/Modules/TeamDevelopment/Rest/TeamDevelopmentRestController.php'
        );
    }

    /**
     * Routes whose `{id}` names a **blueprint**, so the team has to be
     * resolved from the row before the scope can be checked.
     *
     * @var array<string, list<string>>
     */
    private const BLUEPRINT_ROUTES = [
        "'/blueprints/(?P<id>\\d+)'"             => [ 'can_view_blueprint', 'can_manage_blueprint' ],
        "'/blueprints/(?P<id>\\d+)/assignment'"  => [ 'can_manage_blueprint' ],
        "'/blueprints/(?P<id>\\d+)/assignments'" => [ 'can_manage_blueprint' ],
        "'/blueprints/(?P<id>\\d+)/status'"      => [ 'can_manage_blueprint' ],
        "'/blueprints/(?P<id>\\d+)/clone'"       => [ 'can_manage_blueprint' ],
    ];

    /**
     * Routes whose `{id}` is the team itself, so it is passed straight
     * through to the scoped predicate.
     *
     * @var array<string, list<string>>
     */
    private const TEAM_ROUTES = [
        "'/teams/(?P<id>\\d+)/formation'"  => [ 'can_view_team', 'can_manage_team' ],
        "'/teams/(?P<id>\\d+)/style'"      => [ 'can_view_team', 'can_manage_team' ],
        "'/teams/(?P<id>\\d+)/blueprints'" => [ 'can_view_team', 'can_manage_team' ],
    ];

    public function test_each_blueprint_route_resolves_the_team_from_the_row(): void {
        $source = $this->controllerSource();

        foreach ( self::BLUEPRINT_ROUTES as $route => $callbacks ) {
            $at = strpos( $source, $route );
            $this->assertNotFalse( $at, "Route {$route} is gone or was renamed." );
            $block = substr( $source, $at, 900 );
            foreach ( $callbacks as $callback ) {
                $this->assertStringContainsString(
                    $callback,
                    $block,
                    "Route {$route} no longer gates on {$callback} — the id "
                    . 'names a blueprint, so its team has to be looked up first.'
                );
            }
        }
    }

    public function test_each_team_scoped_route_checks_the_team_id_it_is_given(): void {
        $source = $this->controllerSource();

        foreach ( self::TEAM_ROUTES as $route => $callbacks ) {
            $at = strpos( $source, $route );
            $this->assertNotFalse( $at, "Route {$route} is gone or was renamed." );
            $block = substr( $source, $at, 900 );
            foreach ( $callbacks as $callback ) {
                $this->assertStringContainsString(
                    $callback,
                    $block,
                    "Route {$route} no longer gates on {$callback} — a club-wide "
                    . 'answer is not an answer about one team.'
                );
            }
        }
    }

    /**
     * `/formation-templates` is the one route left on the scope-less pair:
     * its payload is the seeded template library, not a team's data. So
     * exactly one registration may still point there, and it is that one.
     */
    public function test_only_the_template_library_still_uses_the_scope_less_pair(): void {
        $source = $this->controllerSource();

        $this->assertSame(
            1,
            preg_match_all( "/'permission_callback'\s*=.\s*\[\s*__CLASS__,\s*'can_(view|manage)'\s*\]/", $source ),
            'A blueprint or formation route went back to the hasAuthorityAnyScope gate.'
        );

        $at = strpos( $source, "'/formation-templates'" );
        $this->assertNotFalse( $at );
        $this->assertStringContainsString( "'can_view'", substr( $source, $at, 900 ) );
    }

    public function test_the_predicate_refuses_a_missing_team(): void {
        $admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $this->assertFalse( TeamChemistryAccess::canReadForTeam( $admin, 0 ) );
        $this->assertFalse( TeamChemistryAccess::canManageForTeam( $admin, 0 ) );
        $this->assertFalse( TeamChemistryAccess::canReadForTeam( 0, 7 ) );
    }

    public function test_a_caller_with_no_team_chemistry_grant_reads_no_team(): void {
        $nobody = self::factory()->user->create( [ 'role' => 'subscriber' ] );
        $this->assertFalse( TeamChemistryAccess::canReadForTeam( $nobody, 7 ) );
        $this->assertFalse( TeamChemistryAccess::canManageForTeam( $nobody, 7 ) );
    }

    /**
     * An unknown blueprint id must resolve to team 0 — which fails the
     * scope check rather than falling through it.
     */
    public function test_an_unknown_blueprint_resolves_to_no_team(): void {
        $repo = new \TT\Modules\TeamDevelopment\Repositories\TeamBlueprintsRepository();
        $this->assertSame( 0, $repo->teamIdFor( 0 ) );
        $this->assertSame( 0, $repo->teamIdFor( 999999 ) );
    }
}
