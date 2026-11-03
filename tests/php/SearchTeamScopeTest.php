<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;

/**
 * #3159 — team search asks whose teams; #4671 moved it into the team
 * provider.
 *
 * `tt_view_teams` is club-wide on `tt_coach`, so a team search that gated on
 * it and then queried every team enumerated every squad in the academy. The
 * team provider narrows to the caller's teams unless they read every team,
 * and a caller with no teams gets nothing rather than the club-wide query.
 * The behaviour per persona is in `SearchScopeTest`; these guard the shape.
 */
final class SearchTeamScopeTest extends WP_UnitTestCase {

    private function source( string $file ): string {
        return (string) file_get_contents(
            dirname( __DIR__, 2 ) . '/src/Infrastructure/Search/Providers/' . $file
        );
    }

    public function test_the_team_search_narrows_to_the_callers_teams(): void {
        $source = $this->source( 'TeamSearchProvider.php' );
        $this->assertStringContainsString(
            "user_has_global_entity_read( \$user_id, 'team' )",
            $source,
            'The global-read bypass is gone; a scout or head of development would be narrowed wrongly.'
        );
        $this->assertStringContainsString(
            'get_teams_for_coach( $user_id )',
            $source,
            'The team search no longer resolves the caller\'s own teams.'
        );
    }

    /**
     * An empty scope must return no scope at all, never fall through to the
     * unnarrowed query.
     */
    public function test_an_empty_team_scope_returns_early(): void {
        $this->assertMatchesRegularExpression(
            '/if \(\s*\$mine === \[\]\s*\)\s*return null;/',
            $this->source( 'TeamSearchProvider.php' ),
            'A caller with no teams no longer short-circuits, so they fall through to the club-wide query.'
        );
    }

    /** Player search confirms every hit with the player read rule. */
    public function test_player_search_still_authorises_every_row(): void {
        $this->assertStringContainsString(
            'AuthorizationService::canViewPlayers( $user_id',
            $this->source( 'PlayerSearchProvider.php' ),
            'Player search stopped authorising per row.'
        );
    }
}
