<?php
namespace TT\Tests\Php;

use TT\Modules\Authorization\AllTeamsScope;
use WP_UnitTestCase;

/**
 * #3152 — a team id in a URL is checked against the caller's scope.
 *
 * `current_user_can( 'tt_view_teams' )` answers "may this person look at
 * teams". Four surfaces read it as "may this person look at **this** team"
 * and then took the id from the request. `tt_coach` holds `tt_view_teams`
 * club-wide, so a head coach could walk `?id=1,2,3…` and read every roster
 * in the academy — and `GET /teams` had been narrowing correctly the whole
 * time, which is what made the gap easy to miss.
 */
final class TeamReadScopeTest extends WP_UnitTestCase {

    /**
     * Surface => the call that must appear in it.
     *
     * A source scan, because the regression is a *removed* guard: nothing
     * throws when someone deletes it, and reproducing the leak needs a
     * seeded academy plus a team-scoped coach. Reading the text catches the
     * removal on the first run.
     *
     * @var string[]
     */
    private const GUARDED_SURFACES = [
        'src/Shared/Frontend/FrontendTeamDetailView.php',
        'src/Shared/Frontend/FrontendTeamsManageView.php',
        'src/Infrastructure/REST/PeekRestController.php',
        'src/Infrastructure/REST/TeamsRestController.php',
    ];

    private function pluginDir(): string {
        return dirname( __DIR__, 2 );
    }

    public function test_every_team_surface_checks_the_id_against_the_callers_scope(): void {
        foreach ( self::GUARDED_SURFACES as $rel ) {
            $path = $this->pluginDir() . '/' . $rel;
            $this->assertFileExists( $path, "Guarded surface moved or was renamed: {$rel}" );
            $this->assertStringContainsString(
                'canReadTeam(',
                (string) file_get_contents( $path ),
                "{$rel} no longer checks the team id against the caller's scope. "
                . "A club-wide capability is not an answer about one team."
            );
        }
    }

    /**
     * `loadTeam()` used to omit the club scope on the reasoning that tenancy
     * belongs at the request layer, while the detail page's own loader
     * (`QueryHelpers::get_team()`) has always carried it. The two disagreed
     * about which team a given id names.
     */
    public function test_the_teams_manage_loader_carries_the_club_scope(): void {
        $source = (string) file_get_contents(
            $this->pluginDir() . '/src/Shared/Frontend/FrontendTeamsManageView.php'
        );
        $this->assertMatchesRegularExpression(
            '/tt_teams t WHERE t\.id = %d AND t\.club_id = %d/',
            $source,
            'FrontendTeamsManageView::loadTeam() dropped the club scope again.'
        );
    }

    public function test_a_logged_out_caller_reads_no_team(): void {
        $this->assertFalse( AllTeamsScope::canReadTeam( 0, 1 ) );
    }

    /**
     * Id 0 is "no team given", never "any team".
     */
    public function test_a_missing_team_id_is_never_readable(): void {
        $admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $this->assertTrue( AllTeamsScope::canReadTeam( $admin, 12 ) );
        $this->assertFalse( AllTeamsScope::canReadTeam( $admin, 0 ) );
    }

    /**
     * A user with neither a global read nor a team assignment reads nothing —
     * the case that used to return the whole academy one id at a time.
     */
    public function test_a_caller_with_no_teams_reads_no_team(): void {
        $nobody = self::factory()->user->create( [ 'role' => 'subscriber' ] );
        $this->assertFalse( AllTeamsScope::canReadTeam( $nobody, 1 ) );
        $this->assertFalse( AllTeamsScope::canReadTeam( $nobody, 999 ) );
    }
}
