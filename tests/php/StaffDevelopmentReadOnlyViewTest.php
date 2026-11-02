<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\FunctionalRoleGrants;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Authorization\MatrixGate;
use TT\Modules\Authorization\PersonaResolver;
use TT\Modules\StaffDevelopment\Frontend\FrontendMyStaffCertificationsView;
use TT\Modules\StaffDevelopment\Frontend\FrontendMyStaffEvaluationsView;
use TT\Modules\StaffDevelopment\Frontend\FrontendMyStaffGoalsView;
use TT\Modules\StaffDevelopment\Frontend\FrontendMyStaffPdpView;
use TT\Modules\StaffDevelopment\StaffDevelopmentAccess;

/**
 * The four "My staff development" pages offer a form only where a Save
 * would succeed. A reader who may read their own record but not change it
 * gets the record, a notice and no form; a reader who may change it gets
 * the form as before.
 *
 * The fixture is an assistant coach, whose seeded `staff_development` row
 * is `rc` at self scope. Taking the `change` row away is what an operator
 * does in the matrix to grant read without change.
 */
final class StaffDevelopmentReadOnlyViewTest extends WP_UnitTestCase {

    private const NOTICE = 'You can view this, but not change it.';

    private int $uid = 0;
    private int $person = 0;

    /** @var string|null */
    private $method;

    public function set_up(): void {
        parent::set_up();
        $roles = new RolesService();
        $roles->installRoles();
        $roles->ensureCapabilities();

        global $wpdb;
        $club = (int) CurrentClub::id();
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => $club, 'name' => 'Staff O14-1' ] );
        $team = (int) $wpdb->insert_id;

        $this->uid = (int) self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $wpdb->insert( "{$wpdb->prefix}tt_people", [
            'club_id' => $club, 'first_name' => 'Read', 'last_name' => 'Only',
            'role_type' => 'coach', 'wp_user_id' => $this->uid, 'status' => 'active',
        ] );
        $this->person = (int) $wpdb->insert_id;
        $wpdb->insert( "{$wpdb->prefix}tt_team_people", [
            'team_id' => $team, 'person_id' => $this->person, 'role_in_team' => 'assistant_coach', 'is_head_coach' => 0,
        ] );
        $this->flush();

        $this->assertContains( 'assistant_coach', PersonaResolver::personasFor( $this->uid ) );
        $this->assertNotContains( 'head_coach', PersonaResolver::personasFor( $this->uid ) );

        $this->method = $_SERVER['REQUEST_METHOD'] ?? null;
        $_SERVER['REQUEST_METHOD'] = 'GET';
        wp_set_current_user( $this->uid );
    }

    public function tear_down(): void {
        if ( $this->method === null ) {
            unset( $_SERVER['REQUEST_METHOD'] );
        } else {
            $_SERVER['REQUEST_METHOD'] = $this->method;
        }
        wp_set_current_user( 0 );
        $this->flush();
        parent::tear_down();
    }

    /** @return array<string, array{0:class-string}> */
    public static function views(): array {
        return [
            'plan'           => [ FrontendMyStaffPdpView::class ],
            'goals'          => [ FrontendMyStaffGoalsView::class ],
            'certifications' => [ FrontendMyStaffCertificationsView::class ],
            'evaluations'    => [ FrontendMyStaffEvaluationsView::class ],
        ];
    }

    /**
     * @dataProvider views
     * @param class-string $view
     */
    public function test_a_reader_without_the_change_right_sees_no_form( string $view ): void {
        ( new MatrixRepository() )->removeRow( 'assistant_coach', 'staff_development', MatrixGate::CHANGE, MatrixGate::SCOPE_SELF );
        $this->flush();

        $this->assertTrue( StaffDevelopmentAccess::canRead( $this->uid, $this->person ), 'the fixture still reads its own record' );
        $this->assertFalse( StaffDevelopmentAccess::canWrite( $this->uid, $this->person ), 'the fixture may not change it' );

        $html = $this->render( $view );

        $this->assertStringContainsString( self::NOTICE, $html );
        $this->assertStringNotContainsString( '<form', $html, 'no form where a Save would not succeed' );
        $this->assertStringNotContainsString( 'tt-form-actions', $html, 'no Save button' );
        $this->assertStringContainsString( 'tt-breadcrumbs', $html, 'CLAUDE.md §5 — the chain still renders' );
    }

    /**
     * @dataProvider views
     * @param class-string $view
     */
    public function test_a_reader_with_the_change_right_sees_the_form( string $view ): void {
        $this->assertTrue( StaffDevelopmentAccess::canWrite( $this->uid, $this->person ) );

        $html = $this->render( $view );

        $this->assertStringContainsString( '<form', $html );
        $this->assertStringContainsString( 'tt-form-actions', $html );
        $this->assertStringNotContainsString( self::NOTICE, $html );
    }

    public function test_the_read_only_plan_shows_what_was_written(): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_staff_pdp", [
            'club_id' => (int) CurrentClub::id(), 'uuid' => wp_generate_uuid4(), 'person_id' => $this->person,
            'strengths' => 'Clear session plans.', 'development_areas' => 'Set pieces.',
        ] );
        ( new MatrixRepository() )->removeRow( 'assistant_coach', 'staff_development', MatrixGate::CHANGE, MatrixGate::SCOPE_SELF );
        $this->flush();

        $html = $this->render( FrontendMyStaffPdpView::class );

        $this->assertStringContainsString( 'Clear session plans.', $html );
        $this->assertStringContainsString( 'Set pieces.', $html );
        $this->assertStringNotContainsString( '<textarea', $html );
    }

    /** @param class-string $view */
    private function render( string $view ): string {
        ob_start();
        $view::render( $this->uid, false );
        return (string) ob_get_clean();
    }

    private function flush(): void {
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        FunctionalRoleGrants::clearCache();
    }
}
