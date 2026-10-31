<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Core\ModuleRegistry;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\FunctionalRoleGrants;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Shared\Frontend\FrontendReportDetailView;
use TT\Shared\Frontend\FrontendReportsLauncherView;

/**
 * #4384 — Team rating averages and Coach activity list every team in the
 * academy. Staff scoped to their own teams were offered both tiles and
 * shown both reports. They are now academy-wide: the launcher hides the
 * tiles and the detail view refuses a typed URL with the same notice and
 * breadcrumbs the capability refusal renders.
 */
final class AcademyWideReportsScopeTest extends WP_UnitTestCase {

    private const TYPES = [ 'team_ratings', 'coach_activity' ];

    private const REFUSAL = 'Your role does not have access to reports.';

    private int $team = 0;

    public function set_up(): void {
        parent::set_up();
        $_GET = [];

        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        MatrixRepository::clearCache();
        FunctionalRoleGrants::clearCache();
        AuthorizationService::flushCache();
        ModuleRegistry::setEnabled( 'TT\\Modules\\Reports\\ReportsModule', true );

        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => (int) CurrentClub::id(), 'name' => 'Scope U13' ] );
        $this->team = (int) $wpdb->insert_id;
    }

    public function tear_down(): void {
        MatrixRepository::clearCache();
        FunctionalRoleGrants::clearCache();
        AuthorizationService::flushCache();
        wp_set_current_user( 0 );
        $_GET = [];
        parent::tear_down();
    }

    public function test_a_team_scoped_coach_is_refused_both_reports(): void {
        $coach = $this->makePersona( 'tt_coach', 'coach' );
        wp_set_current_user( $coach );
        $this->assertTrue( current_user_can( 'tt_view_reports' ), 'precondition: the coach passes the reports capability' );

        foreach ( self::TYPES as $type ) {
            $html = $this->renderDetail( $type );
            $this->assertStringContainsString( self::REFUSAL, $html, "{$type} refuses a team-scoped coach" );
            $this->assertStringContainsString( 'tt-breadcrumbs', $html, "{$type} refusal keeps the breadcrumb chain" );
            $this->assertStringNotContainsString( 'tt-print-area', $html, "{$type} renders no report body" );
        }
    }

    public function test_a_team_scoped_coach_is_not_offered_either_tile(): void {
        $coach = $this->makePersona( 'tt_coach', 'coach' );
        wp_set_current_user( $coach );

        $html = $this->renderLauncher( $coach );

        $this->assertStringContainsString( 'tt-breadcrumbs', $html, 'precondition: the launcher rendered' );
        foreach ( self::TYPES as $type ) {
            $this->assertStringNotContainsString( 'type=' . $type, $html, "the {$type} tile is hidden" );
        }
    }

    public function test_the_head_of_development_keeps_both_reports_and_tiles(): void {
        $hod = $this->makePersona( 'tt_head_dev', 'head_of_development' );
        wp_set_current_user( $hod );

        foreach ( self::TYPES as $type ) {
            $html = $this->renderDetail( $type );
            $this->assertStringNotContainsString( self::REFUSAL, $html, "{$type} opens for the head of development" );
            $this->assertStringContainsString( 'tt-print-area', $html, "{$type} renders its report body" );
        }

        $launcher = $this->renderLauncher( $hod );
        foreach ( self::TYPES as $type ) {
            $this->assertStringContainsString( 'type=' . $type, $launcher, "the {$type} tile is offered" );
        }
    }

    private function renderDetail( string $type ): string {
        ob_start();
        FrontendReportDetailView::render( $type );
        return (string) ob_get_clean();
    }

    private function renderLauncher( int $user_id ): string {
        ob_start();
        FrontendReportsLauncherView::render( $user_id, current_user_can( 'tt_edit_settings' ) );
        return (string) ob_get_clean();
    }

    private function makePersona( string $role, string $role_type ): int {
        global $wpdb;
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
            'scope_id'   => $this->team,
        ] );

        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        return $user_id;
    }
}
