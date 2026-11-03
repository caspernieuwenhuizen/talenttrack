<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Core\FeatureRegistry;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Analytics\Domain\FactScope;
use TT\Modules\Authorization\FunctionalRoleGrants;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\License\FeatureMap;
use TT\Modules\License\LicenseGate;
use TT\Modules\License\LicenseMode;
use TT\Modules\ReportStudio\Domain\SharedReads;
use TT\Modules\ReportStudio\Frontend\FrontendReportStudioView;

/**
 * #4737 — a rename waits for publish. The builder renames their draft;
 * readers keep the published name until the next publish, which copies the
 * draft's name onto the version and the report. A report never published
 * takes the name at once. Migration 0311 adds the two name columns and fills
 * them once.
 */
final class ReportDraftNameTest extends WP_UnitTestCase {

    // Publishing and the migration's ALTERs commit, so every test cleans up.
    use CommitsAfterCascade;

    private const MIGRATION = '/database/migrations/0311_report_draft_names.php';

    private int $team_a = 0;
    private int $coach = 0;
    private int $hod = 0;

    public function set_up(): void {
        parent::set_up();
        $this->markFixtureFloor();
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();

        global $wpdb, $wp_rest_server;
        $wpdb->hide_errors();
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => CurrentClub::id(), 'name' => 'Naming U12' ] );
        $this->team_a = (int) $wpdb->insert_id;
        $this->coach  = $this->headCoachOf( $this->team_a );
        $this->hod    = (int) self::factory()->user->create( [ 'role' => 'tt_head_dev' ] );
        $this->flush();

        FeatureRegistry::setEnabled( 'report_studio', true );
        LicenseMode::overrideForTests( true );
        LicenseGate::overrideTierForTests( FeatureMap::TIER_PRO );

        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );
        wp_set_current_user( $this->hod );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        LicenseMode::overrideForTests( null );
        LicenseGate::overrideTierForTests( null );
        wp_set_current_user( 0 );
        $this->cleanUpAndCommit();
        $this->flush();
        parent::tear_down();
    }

    public function test_a_rename_waits_for_publish(): void {
        $id = $this->create( [ 'scope' => 'team', 'binding' => 'on_open', 'sharing' => 'role', 'name' => 'First' ] );
        $this->assertSame( 201, $this->send( 'POST', "reports/{$id}/versions", [ 'note' => 'One', 'persona_keys' => [ 'head_coach' ] ] )->get_status() );

        $renamed = $this->send( 'PATCH', "reports/{$id}", [ 'name' => 'Second' ] );
        $this->assertSame( 200, $renamed->get_status() );
        $data = $renamed->get_data()['data'];
        $this->assertSame( 'First', $data['name'], 'readers still see the published name' );
        $this->assertSame( 'Second', $data['draft']['name'], 'the builder sees their draft name' );
        $this->assertTrue( $data['draft']['name_changed'] );

        wp_set_current_user( $this->coach );
        $reader = $this->send( 'GET', "reports/{$id}" )->get_data()['data'];
        $this->assertSame( 'First', $reader['name'], 'the recipient reads the published name' );
        $this->assertArrayNotHasKey( 'draft', $reader );
        $listed = array_column( $this->send( 'GET', 'reports', [], [ 'owner' => 'published' ] )->get_data()['data']['rows'], 'name', 'id' );
        $this->assertSame( 'First', $listed[ $id ] ?? null, 'and so does "Shared with me"' );

        wp_set_current_user( $this->hod );
        $this->assertSame( 201, $this->send( 'POST', "reports/{$id}/versions", [ 'note' => 'Two', 'persona_keys' => [ 'head_coach' ] ] )->get_status() );
        $after = $this->send( 'GET', "reports/{$id}" )->get_data()['data'];
        $this->assertSame( 'Second', $after['name'] );
        $this->assertFalse( $after['draft']['name_changed'] );
        $this->assertSame( [ 'Second', 'First' ], array_column( $this->send( 'GET', "reports/{$id}/versions" )->get_data()['data']['versions'], 'name' ), 'each version keeps the name it was published with' );

        wp_set_current_user( $this->coach );
        $this->assertSame( 'Second', $this->send( 'GET', "reports/{$id}" )->get_data()['data']['name'], 'after publish, readers see the new name' );
    }

    public function test_a_report_never_published_takes_the_name_at_once(): void {
        $id  = $this->create( [ 'scope' => 'team', 'team_id' => $this->team_a, 'name' => 'Draft one' ] );
        $res = $this->send( 'PATCH', "reports/{$id}", [ 'name' => 'Draft two' ] );
        $this->assertSame( 200, $res->get_status() );
        $this->assertSame( 'Draft two', $res->get_data()['data']['name'] );
        $this->assertSame( 'Draft two', $res->get_data()['data']['draft']['name'] );
        $this->assertFalse( $res->get_data()['data']['draft']['name_changed'], 'no readers, nothing to differ from' );
    }

    public function test_a_rename_is_a_draft_write_with_its_revision(): void {
        $id = $this->create( [ 'scope' => 'team', 'team_id' => $this->team_a, 'name' => 'Rev' ] );
        $one = $this->send( 'PATCH', "reports/{$id}", [ 'name' => 'Rev 1' ], [ 'revision' => 0 ] );
        $this->assertSame( 1, $one->get_data()['data']['draft']['revision'], 'a rename raises the revision' );
        $this->assertSame( 409, $this->send( 'PATCH', "reports/{$id}", [ 'name' => 'Stale' ], [ 'revision' => 0 ] )->get_status(), 'a stale rename is refused' );
        $this->assertSame( 'Rev 1', $this->send( 'GET', "reports/{$id}" )->get_data()['data']['draft']['name'] );

        $both = $this->send( 'PATCH', "reports/{$id}", [ 'name' => 'Rev 2', 'definition' => [ 'blocks' => [ [ 'key' => 'attendance_per_week' ] ] ] ], [ 'revision' => 1 ] );
        $this->assertSame( 200, $both->get_status() );
        $this->assertSame( 2, $both->get_data()['data']['draft']['revision'], 'one write for both fields' );
        $this->assertSame( 'attendance_per_week', $both->get_data()['data']['draft']['definition']['blocks'][0]['key'] );

        $only_def = $this->send( 'PATCH', "reports/{$id}", [ 'definition' => [ 'blocks' => [] ] ] );
        $this->assertSame( 'Rev 2', $only_def->get_data()['data']['draft']['name'], 'a definition write keeps the draft name' );
    }

    public function test_restoring_a_version_brings_its_name_into_the_draft(): void {
        $id = $this->create( [ 'scope' => 'team', 'binding' => 'on_open', 'sharing' => 'role', 'name' => 'Original' ] );
        $this->assertSame( 201, $this->send( 'POST', "reports/{$id}/versions", [ 'note' => 'One' ] )->get_status() );
        $this->send( 'PATCH', "reports/{$id}", [ 'name' => 'Experiment' ] );

        $back = $this->send( 'POST', "reports/{$id}/draft", [ 'from_version' => 1 ] );
        $this->assertSame( 200, $back->get_status() );
        $this->assertSame( 'Original', $back->get_data()['data']['draft']['name'] );
    }

    public function test_the_builder_sees_the_draft_name_with_the_marker(): void {
        $id = $this->create( [ 'scope' => 'team', 'binding' => 'on_open', 'sharing' => 'role', 'name' => 'Listed' ] );
        $this->assertSame( 201, $this->send( 'POST', "reports/{$id}/versions", [ 'note' => 'One' ] )->get_status() );
        $this->send( 'PATCH', "reports/{$id}", [ 'name' => 'Listed again' ] );

        $list = $this->render( 0 );
        $this->assertStringContainsString( 'Listed again', $list );
        $this->assertStringContainsString( 'Changed since publish', $list );

        $canvas = $this->render( $id );
        $this->assertStringContainsString( 'value="Listed again"', $canvas );
        $this->assertMatchesRegularExpression( '/data-tt-rs-name-changed\s*>/', $canvas, 'the marker is shown, not hidden' );
    }

    public function test_the_migration_adds_the_columns_once_and_fills_them_once(): void {
        global $wpdb;
        $p  = $wpdb->prefix;
        $id = $this->create( [ 'scope' => 'team', 'team_id' => $this->team_a, 'name' => 'Before 0311' ] );

        // An install from before the migration.
        $wpdb->query( "ALTER TABLE {$p}tt_report_drafts DROP COLUMN name" );
        $wpdb->query( "ALTER TABLE {$p}tt_report_versions DROP COLUMN name" );

        ( require dirname( __DIR__, 2 ) . self::MIGRATION )->up();
        foreach ( [ 'tt_report_drafts', 'tt_report_versions' ] as $table ) {
            $this->assertSame( 'name', $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$p}{$table} LIKE %s", 'name' ) ), $table );
        }
        $draft_name = static fn(): string => (string) $wpdb->get_var( $wpdb->prepare( "SELECT name FROM {$p}tt_report_drafts WHERE report_id = %d", $id ) );
        $this->assertSame( 'Before 0311', $draft_name(), 'the backfill gives the draft the report\'s name' );

        $wpdb->update( "{$p}tt_report_drafts", [ 'name' => '' ], [ 'report_id' => $id ] );
        ( require dirname( __DIR__, 2 ) . self::MIGRATION )->up();
        $this->assertSame( '', $draft_name(), 'a second run fills nothing in again' );
        $this->assertSame( 1, (int) $wpdb->get_var( $wpdb->prepare(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
            "{$p}tt_report_drafts", 'name'
        ) ), 'and adds no second column' );
    }

    // ---- helpers ------------------------------------------------------------------

    /** @param array<string,mixed> $body */
    private function create( array $body ): int {
        $res = $this->send( 'POST', 'reports', $body );
        $this->assertSame( 201, $res->get_status(), (string) wp_json_encode( $res->get_data() ) );
        return (int) $res->get_data()['data']['id'];
    }

    private function render( int $id ): string {
        $this->flush();
        if ( $id > 0 ) $_GET['id'] = (string) $id; else unset( $_GET['id'] );
        ob_start();
        FrontendReportStudioView::render( $this->hod, false );
        $out = (string) ob_get_clean();
        unset( $_GET['id'] );
        return $out;
    }

    /**
     * @param array<string,mixed> $body
     * @param array<string,mixed> $query
     */
    private function send( string $method, string $route, array $body = [], array $query = [] ): WP_REST_Response {
        $req = new WP_REST_Request( $method, '/talenttrack/v1/' . $route );
        if ( $query !== [] ) $req->set_query_params( $query );
        if ( $method !== 'GET' ) {
            $req->set_header( 'Content-Type', 'application/json' );
            $req->set_body( (string) wp_json_encode( (object) $body ) );
        }
        $this->flush();
        return rest_do_request( $req );
    }

    private function headCoachOf( int $team ): int {
        global $wpdb;
        $user = (int) self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $wpdb->insert( "{$wpdb->prefix}tt_people", [
            'club_id' => CurrentClub::id(), 'first_name' => 'Head', 'last_name' => 'Coach',
            'role_type' => 'head_coach', 'wp_user_id' => $user, 'status' => 'active',
        ] );
        $person = (int) $wpdb->insert_id;
        $wpdb->insert( "{$wpdb->prefix}tt_team_people", [
            'team_id' => $team, 'person_id' => $person, 'role_in_team' => 'head_coach', 'is_head_coach' => 1,
        ] );
        $wpdb->insert( "{$wpdb->prefix}tt_user_role_scopes", [
            'club_id' => CurrentClub::id(), 'person_id' => $person, 'role_id' => 1, 'scope_type' => 'team', 'scope_id' => $team,
        ] );
        return $user;
    }

    private function flush(): void {
        MatrixRepository::clearCache();
        FunctionalRoleGrants::clearCache();
        AuthorizationService::flushCache();
        FactScope::flush();
        SharedReads::flush();
        wp_cache_flush();
    }
}
