<?php
namespace TT\Tests\Php;

use TT\Shared\Admin\AdminListScope;
use WP_UnitTestCase;

/**
 * #3158 — no wp-admin list or picker builds itself from the unscoped
 * `QueryHelpers::get_players()` / `get_teams()` helpers.
 *
 * Seven pages did, gated only by a menu capability every coach holds,
 * while the frontend and REST siblings of the same lists already narrowed.
 * The Players list was the sharpest: `pl.*` carries date of birth and the
 * guardian's name, email and phone.
 *
 * The source scan is the durable half. The regression is a *removed* call
 * — nothing throws when someone reintroduces `get_players()` on an admin
 * page, and a behavioural test would need a seeded academy plus a
 * team-scoped coach to notice. Reading the text catches the reintroduction
 * on the first run.
 */
final class AdminListScopeTest extends WP_UnitTestCase {

    /**
     * The pages the #3158 sweep covered, and the argument-less helper
     * calls that made each of them club-wide.
     *
     * @var string[]
     */
    private const SWEPT_PAGES = [
        'src/Modules/Players/Admin/PlayersPage.php',
        'src/Modules/Teams/Admin/TeamsPage.php',
        'src/Modules/Evaluations/Admin/EvaluationsPage.php',
        'src/Modules/Goals/Admin/GoalsPage.php',
        'src/Modules/Activities/Admin/ActivitiesPage.php',
        'src/Modules/Stats/Admin/PlayerRateCardsPage.php',
        'src/Modules/Reports/Admin/ReportsPage.php',
    ];

    private function pluginDir(): string {
        return dirname( __DIR__, 2 );
    }

    public function test_no_swept_admin_page_calls_the_unscoped_helpers(): void {
        $offenders = [];

        foreach ( self::SWEPT_PAGES as $rel ) {
            $path = $this->pluginDir() . '/' . $rel;
            $this->assertFileExists( $path, "Swept page moved or was renamed: {$rel}" );
            $source = (string) file_get_contents( $path );

            // `get_players( $team_id )` and `get_teams( true )` are fine —
            // they are already narrowed by their argument. The club-wide
            // form is the argument-less one.
            if ( preg_match( '/QueryHelpers::get_players\(\s*\)/', $source ) ) {
                $offenders[] = "{$rel} calls QueryHelpers::get_players() with no team";
            }
            if ( preg_match( '/QueryHelpers::get_teams\(\s*\)/', $source ) ) {
                $offenders[] = "{$rel} calls QueryHelpers::get_teams()";
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "A wp-admin list or picker went back to the club-wide query.\n"
            . "Use AdminListScope::players() / QueryHelpers::get_teams_in_scope()\n"
            . "so wp-admin narrows the way its REST sibling already does.\n"
            . implode( "\n", $offenders )
        );
    }

    public function test_every_swept_page_resolves_the_viewers_scope(): void {
        foreach ( self::SWEPT_PAGES as $rel ) {
            $source = (string) file_get_contents( $this->pluginDir() . '/' . $rel );
            $this->assertMatchesRegularExpression(
                '/AdminListScope::|get_teams_in_scope\(|canViewPlayer\(/',
                $source,
                "{$rel} resolves no viewer scope at all — it is club-wide again."
            );
        }
    }

    /**
     * The matrix entity for teams is `team`, singular, as seeded in
     * `config/authorization_seed.php`. `MatrixRepository::lookup()` is an
     * exact array-key match, so the plural asked for a row that does not
     * exist and always answered no — every global-read persona who is not
     * also a WordPress settings admin fell through to the coach-assignment
     * branch and got an empty picker.
     */
    public function test_team_scope_asks_the_matrix_for_the_seeded_entity(): void {
        $source = (string) file_get_contents(
            $this->pluginDir() . '/src/Infrastructure/Query/QueryHelpers.php'
        );
        $this->assertStringNotContainsString(
            "canSeeAllTeams( \$user_id, 'teams' )",
            $source,
            "get_teams_in_scope() asks for the entity 'teams', which is not seeded. It is 'team'."
        );

        $seed = (string) file_get_contents( $this->pluginDir() . '/config/authorization_seed.php' );
        $this->assertMatchesRegularExpression(
            "/'team'\s*=>\s*\[/",
            $seed,
            "The seeded teams entity is no longer 'team' — get_teams_in_scope() needs updating with it."
        );
    }

    public function test_a_logged_out_viewer_has_no_teams(): void {
        $this->assertSame( [], AdminListScope::teamIds( 0, 'team' ) );
        $this->assertFalse( AdminListScope::canOpenTeam( 0, 1 ) );
    }

    /**
     * An id of 0 is "no team given", never "any team": `canOpenTeam` must
     * not answer yes for it even when the viewer holds a global read.
     */
    public function test_a_missing_team_id_is_never_openable(): void {
        $admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $this->assertTrue( AdminListScope::canOpenTeam( $admin, 7 ) );
        $this->assertFalse( AdminListScope::canOpenTeam( $admin, 0 ) );
        $this->assertNull( AdminListScope::teamIds( $admin, 'team' ) );
    }

    /**
     * `null` (a global read) and `[]` (a viewer with no teams) must stay
     * distinguishable: the first means "do not narrow", the second means
     * "narrow to nothing". Collapsing them is how a scoping fix turns into
     * a club-wide list.
     */
    public function test_an_empty_scope_narrows_to_nothing_rather_than_everything(): void {
        $coach = self::factory()->user->create( [ 'role' => 'subscriber' ] );

        $this->assertSame( [], AdminListScope::teamIds( $coach, 'team' ) );
        $this->assertSame( ' AND 1=0', AdminListScope::teamIdClause( $coach, 'team', 't.id' ) );
        $this->assertSame( [], AdminListScope::players( $coach, 'players' ) );

        $admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $this->assertSame( '', AdminListScope::teamIdClause( $admin, 'team', 't.id' ) );
    }
}
