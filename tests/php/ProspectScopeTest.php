<?php
namespace TT\Tests\Php;

use TT\Modules\Prospects\ProspectScope;
use WP_UnitTestCase;

/**
 * #3160 — the prospect funnel follows the viewer's scope.
 *
 * `tt_view_prospects` is answered by `MatrixGate::canAnyScope`, and
 * head_coach's grant is `[ 'r', 'team' ]` — the seed's own comment says
 * it exists so a coach can follow their own age group's funnel. Neither
 * the board nor `GET /prospects` read that.
 */
final class ProspectScopeTest extends WP_UnitTestCase {

    private function read( string $rel ): string {
        $path = dirname( __DIR__, 2 ) . '/' . $rel;
        $this->assertFileExists( $path, "File moved or was renamed: {$rel}" );
        return (string) file_get_contents( $path );
    }

    /**
     * `isScoutOnly()` meant "holds view but not manage", which was the
     * scout's shape until v3.110.154 moved them to a global grant. After
     * that it caught the head coach instead — the only remaining persona
     * without `create_delete` — and narrowed their board to their own
     * discoveries. Both copies are gone; neither may come back.
     */
    public function test_the_inverted_helper_is_gone_from_both_consumers(): void {
        foreach ( [
            'src/Modules/Prospects/Frontend/FrontendOnboardingPipelineView.php',
            'src/Modules/PersonaDashboard/Widgets/OnboardingPipelineWidget.php',
        ] as $rel ) {
            $this->assertStringNotContainsString(
                'function isScoutOnly',
                $this->read( $rel ),
                "{$rel} grew back its own copy of the prospect scope rule."
            );
            $this->assertStringContainsString(
                'ProspectScope::sqlClause(',
                $this->read( $rel ),
                "{$rel} no longer narrows the funnel to the viewer's scope."
            );
        }
    }

    /**
     * The KPI counts above the kanban come from the same row set as the
     * columns below it, so the narrowing has to be in the WHERE. A
     * post-filter would leave the counts club-wide while the columns
     * narrowed — worse than either.
     */
    public function test_the_narrowing_is_applied_in_sql(): void {
        $source = $this->read( 'src/Modules/Prospects/Frontend/FrontendOnboardingPipelineView.php' );
        $where  = strpos( $source, 'WHERE p.club_id = %d' );
        $this->assertNotFalse( $where );
        $this->assertStringContainsString(
            '{$where_scope}',
            substr( $source, $where, 200 ),
            'The scope clause is no longer part of the kanban WHERE.'
        );
    }

    public function test_the_rest_list_and_detail_both_narrow(): void {
        $source = $this->read( 'src/Modules/Prospects/Rest/ProspectsRestController.php' );
        $this->assertStringContainsString(
            "\$search_args['scope_sql'] = ProspectScope::sqlClause(",
            $source,
            'GET /prospects returns the club-wide funnel again.'
        );
        $this->assertStringContainsString(
            'self::visibleTo(',
            $source,
            'GET /prospects/{id} no longer narrows to the same set as its list.'
        );
    }

    /**
     * A client filter may narrow the list further; it must never be able
     * to widen it past the server-resolved scope.
     */
    public function test_the_repository_scope_is_not_reachable_from_a_request_parameter(): void {
        $controller = $this->read( 'src/Modules/Prospects/Rest/ProspectsRestController.php' );
        $this->assertStringNotContainsString(
            "filter['scope_sql']",
            $controller,
            'scope_sql became a client-supplied filter, which makes it a bypass rather than a gate.'
        );
    }

    public function test_a_logged_out_viewer_sees_nothing_of_their_own(): void {
        $scope = ProspectScope::forUser( 0 );
        $this->assertIsArray( $scope );
        $this->assertSame( [], $scope['age_group_lookup_ids'] );
        $this->assertSame( [], $scope['team_ids'] );
        $this->assertSame( 0, $scope['user_id'] );
        $this->assertFalse( ProspectScope::canSeeAll( 0 ) );
    }

    /**
     * An academy-wide read means "do not narrow", which is a different
     * thing from "narrow to an empty set" — collapsing the two is how a
     * scoping fix turns into an empty board for the people who own the
     * funnel.
     */
    public function test_an_academy_wide_reader_is_not_narrowed(): void {
        $admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $this->assertTrue( ProspectScope::canSeeAll( $admin ) );
        $this->assertNull( ProspectScope::forUser( $admin ) );
        $this->assertSame( '', ProspectScope::sqlClause( $admin, 'p' ) );
    }

    /**
     * A viewer with no teams still keeps sight of what they logged
     * themselves, and the clause is always a bounded set of int-cast ids.
     */
    public function test_a_viewer_with_no_teams_falls_back_to_their_own_discoveries(): void {
        $nobody = self::factory()->user->create( [ 'role' => 'subscriber' ] );
        $clause = ProspectScope::sqlClause( $nobody, 'p' );

        $this->assertStringContainsString( 'p.discovered_by_user_id = ' . $nobody, $clause );
        $this->assertStringStartsWith( ' AND ( ', $clause );
        $this->assertStringNotContainsString( '%', $clause, 'The clause must carry no placeholders — it is interpolated.' );
    }
}
