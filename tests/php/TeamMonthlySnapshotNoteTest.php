<?php
namespace TT\Tests\Php;

use WPDieException;
use WP_UnitTestCase;
use TT\Infrastructure\Config\ConfigService;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Analytics\Domain\FactScope;
use TT\Modules\Analytics\Frontend\TeamMonthlyReportSnapshotPage;
use TT\Modules\Analytics\Reports\TeamReportAccess;
use TT\Modules\Analytics\Reports\TeamReportSnapshotRepository;
use TT\Modules\Authorization\FunctionalRoleGrants;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\ReportStudio\Domain\SharedReads;
use TT\Shared\Frontend\DashboardShortcode;
use TT\Shared\Tiles\TileRegistry;

/**
 * Notes on a team monthly report snapshot: written by a reader with the right
 * to take that team's snapshot (`TeamReportAccess::canCreate()`), each write
 * audit-logged; a reader without it is refused and nothing is written; a post
 * without the note changes nothing.
 */
final class TeamMonthlySnapshotNoteTest extends WP_UnitTestCase {

    private const NOTE = 'Agreed to rotate the captaincy.';

    private int $team = 0;
    private int $hod = 0;
    private int $manager = 0;
    private string $matrix_was = '';

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $wpdb->hide_errors();

        $roles = new RolesService();
        $roles->installRoles();
        $roles->ensureCapabilities();
        if ( get_role( 'tt_team_manager' ) === null ) {
            add_role( 'tt_team_manager', 'Team Manager', [ 'read' => true ] );
        }
        $config           = new ConfigService();
        $this->matrix_was = (string) $config->get( 'tt_authorization_active', '' );
        $config->set( 'tt_authorization_active', '1' );
        $this->resetMatrixActiveCaches();

        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => (int) CurrentClub::id(), 'name' => 'Notes U14' ] );
        $this->team    = (int) $wpdb->insert_id;
        $this->hod     = $this->persona( 'tt_head_dev', 'head_of_development', 0 );
        $this->manager = $this->persona( 'tt_team_manager', 'team_manager', $this->team );
        $this->flush();
    }

    public function tear_down(): void {
        $_POST    = [];
        $_REQUEST = [];
        wp_set_current_user( 0 );
        ( new ConfigService() )->set( 'tt_authorization_active', $this->matrix_was );
        $this->resetMatrixActiveCaches();
        $this->flush();
        parent::tear_down();
    }

    public function test_a_reader_without_the_snapshot_right_is_refused_and_nothing_is_written(): void {
        $uuid = $this->snapshot();
        $this->assertTrue( TeamReportAccess::canRead( $this->manager, $this->team ), 'Precondition: the team manager reads the report.' );
        $this->assertFalse( TeamReportAccess::canCreate( $this->manager, $this->team ), 'Precondition: but may not take its snapshot.' );

        $before = $this->auditRows();
        $this->assertFalse( TeamMonthlyReportSnapshotPage::note( $uuid, 'kpi', self::NOTE, $this->manager ) );

        wp_set_current_user( $this->manager );
        $this->assertSame( 'You do not have access to this snapshot.', $this->post( $uuid, [ 'note' => self::NOTE ] ) );

        $this->assertSame( [], TeamReportSnapshotRepository::notesOf( ( new TeamReportSnapshotRepository() )->find( $uuid ) ) );
        $this->assertSame( $before, $this->auditRows() );
    }

    public function test_a_note_by_a_reader_with_the_right_is_written_and_audited(): void {
        $uuid = $this->snapshot();
        $this->assertTrue( TeamReportAccess::canCreate( $this->hod, $this->team ), 'Precondition: the head of development takes snapshots.' );

        $before = $this->auditRows();
        $this->assertTrue( TeamMonthlyReportSnapshotPage::note( $uuid, 'kpi', self::NOTE, $this->hod ) );

        $notes = TeamReportSnapshotRepository::notesOf( ( new TeamReportSnapshotRepository() )->find( $uuid ) );
        $this->assertSame( self::NOTE, $notes['kpi']['body'] ?? null );
        $this->assertSame( $before + 1, $this->auditRows() );
    }

    public function test_a_post_without_the_note_changes_nothing(): void {
        $uuid = $this->snapshot();
        $this->assertTrue( TeamMonthlyReportSnapshotPage::note( $uuid, 'kpi', self::NOTE, $this->hod ) );

        wp_set_current_user( $this->hod );
        $this->assertSame( 'These fields are required: note.', $this->post( $uuid, [] ) );

        $notes = TeamReportSnapshotRepository::notesOf( ( new TeamReportSnapshotRepository() )->find( $uuid ) );
        $this->assertSame( self::NOTE, $notes['kpi']['body'] ?? null, 'the note is left alone' );
    }

    // ---- helpers ---------------------------------------------------------------------

    private function snapshot(): string {
        $report = [
            'team_id' => $this->team,
            'from'    => '2026-08-01',
            'to'      => '2026-08-31',
            'blocks'  => [ 'letterhead', 'kpi' ],
            'data'    => [ 'letterhead' => [ 'squad_size' => 14 ], 'kpi' => [ 'evaluations' => 3 ] ],
        ];
        $uuid = ( new TeamReportSnapshotRepository() )->create( $this->team, [ 'team_id' => $this->team, 'layout' => 'A', 'blocks' => [ 'kpi' ] ], $report, 'August', $this->hod );
        $this->assertNotSame( '', $uuid );
        return $uuid;
    }

    /**
     * The note form's POST, as the page receives it. Returns the refusal's
     * message; a write that gets through redirects, which a test cannot follow.
     *
     * @param array<string,string> $fields
     */
    private function post( string $uuid, array $fields ): string {
        $_POST = [
            'tt_action' => TeamMonthlyReportSnapshotPage::ACTION_NOTE,
            '_wpnonce'  => wp_create_nonce( TeamMonthlyReportSnapshotPage::ACTION_NOTE ),
            'snapshot'  => $uuid,
            'section'   => 'kpi',
        ] + $fields;
        $_REQUEST = $_POST;
        try {
            TeamMonthlyReportSnapshotPage::handlePost();
        } catch ( WPDieException $e ) {
            $this->flush();
            return $e->getMessage();
        }
        $this->fail( 'The post was expected to be refused.' );
    }

    private function auditRows(): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_audit_log WHERE action = %s AND entity_type = 'team' AND entity_id = %d",
            'team_report.snapshot_noted',
            $this->team
        ) );
    }

    private function persona( string $role, string $role_type, int $team ): int {
        global $wpdb;
        $club = (int) CurrentClub::id();
        $uid  = (int) self::factory()->user->create( [ 'role' => $role ] );
        $wpdb->insert( "{$wpdb->prefix}tt_people", [
            'club_id' => $club, 'first_name' => 'Notes', 'last_name' => 'Persona' . $uid,
            'role_type' => $role_type, 'wp_user_id' => $uid, 'status' => 'active',
        ] );
        $person = (int) $wpdb->insert_id;
        if ( $team > 0 ) {
            $wpdb->insert( "{$wpdb->prefix}tt_team_people", [
                'club_id' => $club, 'team_id' => $team, 'person_id' => $person,
                'role_in_team' => $role_type, 'is_head_coach' => 0,
            ] );
            $wpdb->insert( "{$wpdb->prefix}tt_user_role_scopes", [
                'club_id' => $club, 'person_id' => $person, 'role_id' => 1, 'scope_type' => 'team', 'scope_id' => $team,
            ] );
        }
        $this->flush();
        return $uid;
    }

    private function flush(): void {
        MatrixRepository::clearCache();
        \TT\Modules\Authorization\MatrixGate::clearCache();
        FunctionalRoleGrants::clearCache();
        AuthorizationService::flushCache();
        FactScope::flush();
        SharedReads::flush();
        wp_cache_flush();
    }

    private function resetMatrixActiveCaches(): void {
        foreach ( [ TileRegistry::class, DashboardShortcode::class ] as $class ) {
            $prop = new \ReflectionProperty( $class, 'matrix_active_cache' );
            $prop->setAccessible( true );
            $prop->setValue( null, null );
        }
    }
}
