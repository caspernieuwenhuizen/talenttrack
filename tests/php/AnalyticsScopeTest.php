<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\AllTeamsScope;
use TT\Modules\Authorization\FunctionalRoleGrants;
use TT\Modules\Authorization\Matrix\MatrixRepository;

/**
 * #3832 — a team-scoped analytics grant must not reach the club-wide
 * analytics surfaces.
 *
 * `tt_view_analytics` bridges to `analytics: read` and is answered with
 * "any scope", so a **team**-scoped grant makes the capability true
 * everywhere — including on surfaces with no team to narrow to. It was
 * invisible while the only holders were head of development and academy
 * admin, both global; #3770's team manager is the first holder for whom
 * it is reachable, and #3770's own spec says team scope only.
 *
 * The rule is narrow-where-you-can, refuse-where-you-can't, and both
 * halves are asserted: the manager is refused evaluation coverage, the
 * explorer and scheduled reports, and the head of development is not.
 *
 * The refusal is asserted as a refusal, not as an empty page (#2893,
 * #3792): a screen that renders nothing is a screen that says this
 * academy has no coverage.
 */
final class AnalyticsScopeTest extends WP_UnitTestCase {

    private int $team = 0;
    private int $other_team = 0;
    private int $manager = 0;
    private int $hod = 0;

    public function set_up(): void {
        parent::set_up();

        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        MatrixRepository::clearCache();
        FunctionalRoleGrants::clearCache();
        AuthorizationService::flushCache();

        global $wpdb;
        $wpdb->hide_errors();
        $club = (int) CurrentClub::id();

        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => $club, 'name' => 'Scope U12' ] );
        $this->team = (int) $wpdb->insert_id;
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => $club, 'name' => 'Scope U15' ] );
        $this->other_team = (int) $wpdb->insert_id;

        // The persona comes from the WordPress role (PersonaResolver's map),
        // so the slugs are the real ones: `tt_head_dev`, not a made-up name
        // that would resolve to no persona and pass for the wrong reason.
        $this->manager = $this->makePersona( 'tt_team_manager', 'team_manager', $this->team );
        $this->hod     = $this->makePersona( 'tt_head_dev', 'head_of_development', $this->team );
    }

    public function tear_down(): void {
        MatrixRepository::clearCache();
        FunctionalRoleGrants::clearCache();
        AuthorizationService::flushCache();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    /** The helper the three club-wide views ask through. */
    public function test_a_team_scoped_grant_is_not_club_wide_analytics(): void {
        $this->assertFalse(
            AllTeamsScope::canSeeClubWideAnalytics( $this->manager ),
            'a team-scoped analytics grant answers the club-wide question'
        );
    }

    public function test_the_head_of_development_is_club_wide(): void {
        $this->assertTrue(
            AllTeamsScope::canSeeClubWideAnalytics( $this->hod ),
            'the head of development lost the club-wide analytics lens'
        );
    }

    /**
     * The capability itself is deliberately unchanged — the point of the
     * fix is that the capability is NOT the scope answer, and the
     * scopable surfaces still key off it.
     */
    public function test_the_manager_keeps_the_capability(): void {
        wp_set_current_user( $this->manager );

        $this->assertTrue( current_user_can( 'tt_view_analytics' ), '#3770 gave the manager the attendance reports' );
    }

    public function test_the_club_wide_views_refuse_the_manager_in_words(): void {
        wp_set_current_user( $this->manager );

        foreach ( [
            \TT\Modules\Analytics\Frontend\FrontendEvalCoverageView::class,
            \TT\Modules\Analytics\Frontend\FrontendExploreView::class,
            \TT\Modules\Analytics\Frontend\FrontendScheduledReportsView::class,
        ] as $view ) {
            $html = $this->renderOf( $view, $this->manager );

            $this->assertStringContainsString(
                'tt-notice',
                $html,
                "{$view} answered a refusal with something other than a notice"
            );
            $this->assertStringContainsString(
                'own teams',
                $html,
                "{$view} refuses without saying the access is team-limited"
            );
        }
    }

    public function test_the_club_wide_views_still_open_for_the_head_of_development(): void {
        wp_set_current_user( $this->hod );

        foreach ( [
            \TT\Modules\Analytics\Frontend\FrontendEvalCoverageView::class,
            \TT\Modules\Analytics\Frontend\FrontendScheduledReportsView::class,
        ] as $view ) {
            $html = $this->renderOf( $view, $this->hod );

            $this->assertStringNotContainsString(
                'own teams',
                $html,
                "{$view} refuses the head of development, who holds global analytics read"
            );
        }
    }

    /**
     * The hub narrows rather than refusing: the manager's own squad is in
     * the rail, the other team is not, and the academy-wide KPI grid is
     * replaced by a line saying why.
     */
    public function test_the_hub_narrows_to_the_managers_own_teams(): void {
        wp_set_current_user( $this->manager );
        $html = $this->renderOf( \TT\Modules\Analytics\Frontend\FrontendAnalyticsView::class, $this->manager );

        $this->assertStringContainsString( 'Scope U12', $html, 'the manager cannot see their own team' );
        $this->assertStringNotContainsString( 'Scope U15', $html, 'the hub lists a team the manager may not read' );
        $this->assertStringContainsString( 'Academy-wide KPIs need analytics access', $html );
    }

    /** An id in a URL is not an authorisation. */
    public function test_the_hub_refuses_an_entity_outside_the_managers_teams(): void {
        wp_set_current_user( $this->manager );
        $_GET['entity_type'] = 'team';
        $_GET['entity_id']   = (string) $this->other_team;

        $html = $this->renderOf( \TT\Modules\Analytics\Frontend\FrontendAnalyticsView::class, $this->manager );

        unset( $_GET['entity_type'], $_GET['entity_id'] );
        $this->assertStringContainsString( 'not one of them', $html, 'the hub opened a team the manager may not read' );
    }

    public function test_the_hub_is_unchanged_for_the_head_of_development(): void {
        wp_set_current_user( $this->hod );
        $html = $this->renderOf( \TT\Modules\Analytics\Frontend\FrontendAnalyticsView::class, $this->hod );

        $this->assertStringContainsString( 'Scope U12', $html );
        $this->assertStringContainsString( 'Scope U15', $html, 'the head of development lost a team from the rail' );
        $this->assertStringNotContainsString( 'Academy-wide KPIs need analytics access', $html );
    }

    /* ---- helpers -------------------------------------------------------- */

    /** @param class-string $view */
    private function renderOf( string $view, int $user_id ): string {
        ob_start();
        /** @var callable $render */
        $render = [ $view, 'render' ];
        $render( $user_id, false );
        return (string) ob_get_clean();
    }

    /**
     * A persona-backed user: the WordPress role, a `tt_people` row naming
     * the persona and a team grant. Capabilities come from the matrix, so
     * an `add_cap()` on a role that resolves to no persona would be
     * answered by nothing.
     */
    private function makePersona( string $role, string $role_type, int $team_id ): int {
        global $wpdb;
        if ( get_role( $role ) === null ) {
            add_role( $role, $role_type, [ 'read' => true ] );
        }
        $user_id = (int) self::factory()->user->create( [ 'role' => $role ] );

        $wpdb->insert( "{$wpdb->prefix}tt_people", [
            'club_id'    => (int) CurrentClub::id(),
            'first_name' => ucfirst( $role_type ),
            'last_name'  => 'Scope',
            'role_type'  => $role_type,
            'wp_user_id' => $user_id,
            'status'     => 'active',
        ] );
        $wpdb->insert( "{$wpdb->prefix}tt_user_role_scopes", [
            'club_id'    => (int) CurrentClub::id(),
            'person_id'  => (int) $wpdb->insert_id,
            'role_id'    => 1,
            'scope_type' => 'team',
            'scope_id'   => $team_id,
        ] );

        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        return $user_id;
    }
}
