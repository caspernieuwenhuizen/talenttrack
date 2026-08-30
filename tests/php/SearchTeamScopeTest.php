<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;

/**
 * #3159 — `GET /search?types=team` asks whose teams.
 *
 * The controller docblock says filtering happens "per row, through the
 * same authorization service the detail views use". That was true of
 * `players()`, which over-fetches and runs `canViewPlayer` per row, and
 * not of `teams()`, which gated on `tt_view_teams` — club-wide on
 * `tt_coach` — and then queried every team in the club.
 */
final class SearchTeamScopeTest extends WP_UnitTestCase {

    private function source(): string {
        return (string) file_get_contents(
            dirname( __DIR__, 2 ) . '/src/Infrastructure/REST/SearchRestController.php'
        );
    }

    /**
     * The signature is the fix: a `teams()` that never receives the user id
     * cannot be asking whose teams they are.
     */
    public function test_the_team_search_receives_the_caller(): void {
        $this->assertMatchesRegularExpression(
            '/private static function teams\(\s*int \$user_id,\s*string \$q\s*\)/',
            $this->source(),
            'SearchRestController::teams() no longer takes the caller — it cannot be scoping to them.'
        );
    }

    public function test_the_team_search_narrows_to_the_callers_teams(): void {
        $source = $this->source();
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
     * An empty scope must return an empty list, never fall through to the
     * unnarrowed query. That fall-through is how a scoping fix silently
     * becomes a no-op.
     */
    public function test_an_empty_team_scope_returns_early(): void {
        $source = $this->source();
        $at     = strpos( $source, 'get_teams_for_coach( $user_id )' );
        $this->assertNotFalse( $at );
        $block = substr( $source, $at, 300 );
        $this->assertMatchesRegularExpression(
            '/if \(\s*! \$ids \)\s*return \[\];/',
            $block,
            'A caller with no teams no longer short-circuits, so they fall through to the club-wide query.'
        );
    }

    /**
     * Player search was already right. Guard it, because the obvious way to
     * "simplify" the two into one shape is to drop the per-row check.
     */
    public function test_player_search_still_authorises_every_row(): void {
        $this->assertStringContainsString(
            'AuthorizationService::canViewPlayer( $user_id, $player_id )',
            $this->source(),
            'Player search stopped authorising per row.'
        );
    }
}
