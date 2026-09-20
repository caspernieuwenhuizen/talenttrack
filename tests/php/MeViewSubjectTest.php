<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Shared\Frontend\FrontendMyEvaluationsView;

/**
 * #3859 — a Me-view refuses a subject the viewer may not see, and names
 * the subject it does show.
 *
 * A parent opened `?tt_view=my-evaluations&player_id=<their child>`, edited
 * the id to a teammate, and got 200 with their own child's rows under a
 * heading that named nobody. No other family's data was shown — but nothing
 * on screen said so, and the reasonable conclusion is that another child's
 * ratings had just been read.
 *
 * Two defects, one test file:
 *
 *  1. `resolveMePlayer()` replaced an unauthorised `player_id` with one the
 *     viewer may see, which made `requirePlayerOrDeny()`'s stated contract
 *     unreachable. Asserted on the dispatcher's source, in the same style as
 *     `UrlParameterScopeTest` — the rule lives in a private method reached
 *     only through a full dispatch, and the shape is what matters.
 *  2. My evaluations was the one Me-view never routed through `SubjectVoice`,
 *     so it never named the child. Asserted behaviourally: the view is
 *     directly callable.
 */
final class MeViewSubjectTest extends WP_UnitTestCase {

    private function read( string $rel ): string {
        $path = dirname( __DIR__, 2 ) . '/' . $rel;
        $this->assertFileExists( $path, "Surface moved or was renamed: {$rel}" );
        return (string) file_get_contents( $path );
    }

    private function makePlayer( string $first, string $last ): object {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_players', [
            'club_id'    => 1,
            'first_name' => $first,
            'last_name'  => $last,
            'status'     => 'active',
        ] );
        $id = (int) $wpdb->insert_id;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tt_players WHERE id = %d",
            $id
        ) );
    }

    private function render( object $player ): string {
        ob_start();
        FrontendMyEvaluationsView::render( $player );
        return (string) ob_get_clean();
    }

    // -----------------------------------------------------------------
    // 1. the dispatcher refuses rather than substitutes
    // -----------------------------------------------------------------

    public function test_an_unauthorised_player_id_is_refused_not_swapped(): void {
        $source = $this->read( 'src/Shared/Frontend/DashboardShortcode.php' );

        $this->assertMatchesRegularExpression(
            '/if\s*\(\s*!\s*\\\\?TT\\\\Infrastructure\\\\Security\\\\AuthorizationService::canViewPlayer\([^)]*\)\s*\)\s*\{\s*return null;/s',
            $source,
            'resolveMePlayer() must return null for a player_id the viewer may not see, so '
            . 'requirePlayerOrDeny() can emit its notice. Falling through to the viewer\'s own '
            . 'subject is what let a parent believe they had read another child\'s record.'
        );
    }

    public function test_the_no_player_id_path_is_unchanged(): void {
        $source = $this->read( 'src/Shared/Frontend/DashboardShortcode.php' );

        $this->assertStringContainsString(
            'ParentChildResolver::defaultChild( $user_id )',
            $source,
            'with no ?player_id, a parent must still resolve to their default child'
        );
    }

    // -----------------------------------------------------------------
    // 2. the view names its subject
    // -----------------------------------------------------------------

    public function test_a_staff_reader_sees_the_player_named(): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        $player = $this->makePlayer( 'Bas', 'Jansen' );

        $html = $this->render( $player );

        $this->assertStringContainsString(
            'Bas',
            $html,
            'a reader who is not the player must be told whose evaluations these are'
        );
        $this->assertStringNotContainsString(
            'My evaluations',
            $html,
            '"My evaluations" to somebody who is not the player is the bug this fixes'
        );
    }

    public function test_the_player_themselves_still_reads_my_evaluations(): void {
        global $wpdb;
        $uid    = self::factory()->user->create( [ 'role' => 'tt_player' ] );
        $player = $this->makePlayer( 'Own', 'Record' );
        $wpdb->update( $wpdb->prefix . 'tt_players', [ 'wp_user_id' => $uid ], [ 'id' => (int) $player->id ] );
        $player->wp_user_id = $uid;
        wp_set_current_user( $uid );

        $html = $this->render( $player );

        $this->assertStringContainsString(
            'My evaluations',
            $html,
            'the player reading their own record still gets the first-person heading'
        );
    }

    /**
     * The onward links are built from the current URL (`add_query_arg` /
     * `remove_query_arg` with no explicit base), so `player_id` rides along
     * by construction. This pins that: switching them to an explicit base
     * would silently drop the subject on the next click.
     */
    public function test_onward_links_are_built_from_the_current_url(): void {
        $source = $this->read( 'src/Shared/Frontend/FrontendMyEvaluationsView.php' );

        $this->assertStringContainsString(
            "add_query_arg( 'eval_scope', PlayerEvaluationsReader::SCOPE_ALL )",
            $source,
            'the earlier-seasons link must keep the current query string, which carries player_id'
        );
        $this->assertStringContainsString(
            "remove_query_arg( 'eval_scope' )",
            $source,
            'and so must the link back to the current season'
        );
    }
}
