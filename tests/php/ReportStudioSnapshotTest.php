<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Core\FeatureRegistry;
use TT\Infrastructure\Players\ParentChildResolver;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Analytics\Domain\FactScope;
use TT\Modules\Analytics\Reports\PlayerReportAudience;
use TT\Modules\Analytics\Reports\PlayerReportSnapshotRepository;
use TT\Modules\Analytics\Reports\PlayerReportSnapshots;
use TT\Modules\Analytics\Reports\TeamReportSnapshotRepository;
use TT\Modules\Authorization\FunctionalRoleGrants;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\License\FeatureMap;
use TT\Modules\License\LicenseGate;
use TT\Modules\License\LicenseMode;
use TT\Modules\ReportStudio\Document\StudioDocument;
use TT\Modules\ReportStudio\Domain\SharedReads;

/**
 * #5021 — Report Studio snapshots in the two existing snapshot tables, and
 * the document route.
 *
 * - a snapshot of a family report appears in the parent's "shared with me"
 *   list next to the player reports shared with them, and reads the same
 *   after the report is changed, republished and archived;
 * - a note on a staff snapshot is kept in the snapshot's section notes,
 *   never in a draft or a version, and a family snapshot takes none;
 * - a group or an academy report has no snapshot (400);
 * - a team report's snapshot stays off the monthly report's list;
 * - the document route's HTML is the document of the data route's answer;
 * - migration 0327 adds its columns, can run twice and changes no row.
 */
final class ReportStudioSnapshotTest extends WP_UnitTestCase {

    // Publishing commits, so every test cleans up what it wrote.
    use CommitsAfterCascade;

    private const PERIOD = '2026-08-01/2026-08-31';
    private const NOTE   = 'Talked about the first touch under pressure.';

    private int $team   = 0;
    private int $child  = 0;
    private int $parent = 0;
    private int $hod    = 0;

    public function set_up(): void {
        parent::set_up();
        $this->markFixtureFloor();
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();

        global $wpdb, $wp_rest_server;
        $wpdb->hide_errors();
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => CurrentClub::id(), 'name' => 'Snapshot U13', 'age_group' => 'U13' ] );
        $this->team = (int) $wpdb->insert_id;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id' => CurrentClub::id(), 'team_id' => $this->team, 'first_name' => 'Sam', 'last_name' => 'Snapshot', 'status' => 'active',
        ] );
        $this->child  = (int) $wpdb->insert_id;
        $this->hod    = (int) self::factory()->user->create( [ 'role' => 'tt_head_dev' ] );
        $this->parent = (int) self::factory()->user->create( [ 'role' => 'tt_parent' ] );
        $wpdb->insert( "{$wpdb->prefix}tt_player_parents", [ 'club_id' => CurrentClub::id(), 'player_id' => $this->child, 'parent_user_id' => $this->parent ] );
        $this->flush();
        $this->assertTrue( ParentChildResolver::isParentOf( $this->parent, $this->child ), 'fixture: the guardian link resolves' );

        FeatureRegistry::setEnabled( 'report_studio', true );
        LicenseMode::overrideForTests( true );
        LicenseGate::overrideTierForTests( FeatureMap::TIER_PRO );

        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );
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

    public function test_a_family_snapshot_is_shared_with_me_next_to_the_player_reports_and_stays_readable(): void {
        // A player report shared with the family before.
        $earlier = ( new PlayerReportSnapshotRepository() )->create(
            $this->child,
            [ 'player_id' => $this->child, 'audience' => PlayerReportAudience::FAMILY ],
            [ 'player_id' => $this->child, 'from' => '2026-07-01', 'to' => '2026-07-31', 'blocks' => [ 'letterhead' ], 'data' => [] ],
            'July report',
            $this->hod
        );
        $this->assertNotSame( '', $earlier, 'fixture: the earlier shared report' );

        $id   = $this->published( $this->familyChoices(), [
            [ 'id' => 'b1', 'key' => 'letterhead' ],
            [ 'id' => 'b2', 'key' => 'text', 'options' => [ 'heading' => 'For Sam', 'text' => 'Well done this month.' ] ],
        ] );
        $uuid = $this->snapshot( $id );

        $listed = array_column( PlayerReportSnapshots::sharedWithFamily( $this->child, $this->parent ), 'uuid' );
        $this->assertContains( $uuid, $listed, 'the Studio snapshot is shared with the parent' );
        $this->assertContains( $earlier, $listed, 'next to the player report shared before' );

        $before = PlayerReportSnapshots::read( $uuid, $this->parent );
        $this->assertNotNull( $before, 'the parent reads it' );
        $this->assertNotNull( $before['document'] );
        $this->assertSame( [ 'letterhead', 'text' ], array_column( $before['document']['blocks'], 'key' ) );
        $this->assertSame( [], $before['notes'], 'a family reader gets no notes' );
        $this->assertStringContainsString( 'Well done this month.', StudioDocument::body( $before['document'], $this->parent ) );

        // The report changes, is published again and archived; the copy holds.
        wp_set_current_user( $this->hod );
        $this->assertSame( 200, $this->send( 'PATCH', "reports/{$id}", [ 'definition' => [ 'blocks' => [ [ 'id' => 'b9', 'key' => 'text', 'options' => [ 'heading' => 'Changed', 'text' => 'Something else.' ] ] ] ] ] )->get_status() );
        $this->assertSame( 201, $this->send( 'POST', "reports/{$id}/versions", [ 'note' => 'Second' ] )->get_status() );
        $this->assertSame( 200, $this->send( 'DELETE', "reports/{$id}" )->get_status() );

        $after = PlayerReportSnapshots::read( $uuid, $this->parent );
        $this->assertNotNull( $after, 'still readable after the report changed and was archived' );
        $this->assertSame( $before['document'], $after['document'], 'and it reads the same' );
        $this->assertContains( $uuid, array_column( PlayerReportSnapshots::sharedWithFamily( $this->child, $this->parent ), 'uuid' ) );

        wp_set_current_user( $this->parent );
        $res = $this->send( 'GET', "report-snapshots/{$uuid}" );
        $this->assertSame( 200, $res->get_status() );
        $this->assertStringContainsString( 'Well done this month.', (string) $res->get_data()['data']['html'] );
    }

    public function test_a_note_is_kept_with_the_snapshot_never_in_the_report(): void {
        $id   = $this->published( $this->staffChoices(), [ [ 'id' => 'b1', 'key' => 'text', 'options' => [ 'heading' => 'Talk', 'text' => 'For the meeting.' ] ] ] );
        $uuid = $this->snapshot( $id );

        wp_set_current_user( $this->hod );
        $res = $this->send( 'PUT', "report-snapshots/{$uuid}/notes/b1", [ 'body' => self::NOTE ] );
        $this->assertSame( 200, $res->get_status(), (string) wp_json_encode( $res->get_data() ) );
        $notes = (array) $res->get_data()['data']['notes'];
        $this->assertSame( self::NOTE, $notes['b1']['body'] ?? null, 'the note is on the block' );
        $this->assertStringContainsString( self::NOTE, (string) $res->get_data()['data']['html'], 'the note prints under its block' );

        global $wpdb;
        $notes = (string) $wpdb->get_var( $wpdb->prepare( "SELECT notes_json FROM {$wpdb->prefix}tt_player_report_snapshots WHERE uuid = %s", $uuid ) );
        $this->assertStringContainsString( self::NOTE, $notes, 'in the snapshot\'s section notes' );
        foreach ( [ 'tt_report_drafts', 'tt_report_versions' ] as $table ) {
            $stored = (string) $wpdb->get_var( $wpdb->prepare( "SELECT GROUP_CONCAT(definition_json) FROM {$wpdb->prefix}{$table} WHERE report_id = %d", $id ) );
            $this->assertStringNotContainsString( self::NOTE, $stored, "never in {$table}" );
        }

        $this->assertSame( 400, $this->send( 'PUT', "report-snapshots/{$uuid}/notes/b7", [ 'body' => 'x' ] )->get_status(), 'a block not on the snapshot' );
    }

    public function test_a_family_snapshot_takes_no_note(): void {
        $id   = $this->published( $this->familyChoices(), [ [ 'id' => 'b1', 'key' => 'text', 'options' => [ 'heading' => 'For Sam', 'text' => 'Hi.' ] ] ] );
        $uuid = $this->snapshot( $id );

        wp_set_current_user( $this->hod );
        $this->assertSame( 403, $this->send( 'PUT', "report-snapshots/{$uuid}/notes/b1", [ 'body' => self::NOTE ] )->get_status() );
    }

    public function test_a_group_or_academy_report_has_no_snapshot(): void {
        $academy = $this->published( [ 'kind' => 'report', 'scope' => 'academy', 'period_mode' => 'fixed', 'period_value' => self::PERIOD ], [ [ 'id' => 'b1', 'key' => 'heading', 'options' => [ 'heading' => 'Academy' ] ] ] );
        $group   = $this->published( [ 'kind' => 'report', 'scope' => 'group', 'cohort' => [ 'team_ids' => [ $this->team ] ], 'period_mode' => 'fixed', 'period_value' => self::PERIOD ], [ [ 'id' => 'b1', 'key' => 'heading', 'options' => [ 'heading' => 'Group' ] ] ] );

        wp_set_current_user( $this->hod );
        foreach ( [ 'academy' => $academy, 'group' => $group ] as $scope => $id ) {
            $res = $this->send( 'POST', "reports/{$id}/snapshots" );
            $this->assertSame( 400, $res->get_status(), "{$scope}: " . wp_json_encode( $res->get_data() ) );
            $this->assertSame( 'invalid_field', $this->code( $res ) );
        }
    }

    public function test_a_team_reports_snapshot_stays_off_the_monthly_reports_list(): void {
        $id   = $this->published( [ 'kind' => 'report', 'scope' => 'team', 'binding' => 'fixed', 'team_id' => $this->team, 'period_mode' => 'fixed', 'period_value' => self::PERIOD ], [ [ 'id' => 'b1', 'key' => 'heading', 'options' => [ 'heading' => 'Team' ] ] ] );
        $uuid = $this->snapshot( $id );

        $repo = new TeamReportSnapshotRepository();
        $this->assertNull( $repo->find( $uuid ), 'the monthly report does not open it' );
        $this->assertNotNull( $repo->findStudio( $uuid ) );
        $this->assertNotContains( $uuid, array_column( $repo->listForTeam( $this->team ), 'uuid' ) );
        $this->assertSame( [ $uuid ], array_column( $repo->listForReport( $id ), 'uuid' ) );

        wp_set_current_user( $this->hod );
        $list = $this->send( 'GET', "reports/{$id}/snapshots" );
        $this->assertSame( [ $uuid ], array_column( $list->get_data()['data']['snapshots'], 'uuid' ) );
    }

    public function test_the_document_route_draws_the_data_routes_answer(): void {
        $id = $this->published( $this->staffChoices(), [
            [ 'id' => 'b1', 'key' => 'letterhead' ],
            [ 'id' => 'b2', 'key' => 'text', 'options' => [ 'heading' => 'Read me', 'text' => 'The same on paper.' ] ],
        ] );

        wp_set_current_user( $this->hod );
        $data     = $this->send( 'GET', "reports/{$id}/data", [ 'source' => 'published' ] );
        $document = $this->send( 'GET', "reports/{$id}/document", [ 'source' => 'published' ] );
        $this->assertSame( 200, $document->get_status() );
        $this->assertSame( StudioDocument::body( $data->get_data()['data'], $this->hod ), $document->get_data()['data']['html'] );
        $this->assertSame( $data->get_data()['data']['page_fit'], $document->get_data()['data']['page_fit'] );

        wp_set_current_user( $this->parent );
        $this->assertSame( 403, $this->send( 'GET', "reports/{$id}/document" )->get_status(), 'a reader the report is not shared with' );
    }

    public function test_the_migration_adds_its_columns_runs_twice_and_changes_no_row(): void {
        global $wpdb;
        $uuid = ( new PlayerReportSnapshotRepository() )->create(
            $this->child,
            [ 'player_id' => $this->child ],
            [ 'player_id' => $this->child, 'from' => '2026-07-01', 'to' => '2026-07-31', 'blocks' => [], 'data' => [] ],
            'Before 0327',
            $this->hod
        );
        $read   = fn(): array => (array) $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}tt_player_report_snapshots WHERE uuid = %s", $uuid ), ARRAY_A );
        $before = $read();

        $migration = require dirname( __DIR__, 2 ) . '/database/migrations/0327_report_snapshots_studio_report.php';
        $this->assertSame( '0327_report_snapshots_studio_report', $migration->getName() );
        $migration->up();
        $migration->up();

        $this->assertSame( $before, $read(), 'the row is unchanged' );
        $this->assertNull( $before['report_id'] );
        $this->assertNull( $before['report_version_id'] );
        foreach ( [ 'tt_player_report_snapshots', 'tt_team_report_snapshots' ] as $table ) {
            $columns = (array) $wpdb->get_col( "SHOW COLUMNS FROM {$wpdb->prefix}{$table}" );
            $this->assertContains( 'report_id', $columns, $table );
            $this->assertContains( 'report_version_id', $columns, $table );
            $this->assertNotEmpty( $wpdb->get_results( "SHOW INDEX FROM {$wpdb->prefix}{$table} WHERE Key_name = 'idx_report'" ), "{$table} has its index once" );
        }
    }

    // ---- helpers ------------------------------------------------------------------

    /** @return array<string,mixed> */
    private function familyChoices(): array {
        return [ 'kind' => 'report', 'scope' => 'player', 'audience' => 'family', 'binding' => 'fixed', 'player_id' => $this->child, 'sharing' => 'family', 'period_mode' => 'fixed', 'period_value' => self::PERIOD ];
    }

    /** @return array<string,mixed> */
    private function staffChoices(): array {
        return [ 'kind' => 'report', 'scope' => 'player', 'audience' => 'internal', 'binding' => 'fixed', 'player_id' => $this->child, 'period_mode' => 'fixed', 'period_value' => self::PERIOD ];
    }

    /**
     * A report by the head of development, published for themselves.
     *
     * @param array<string,mixed>       $choices
     * @param list<array<string,mixed>> $blocks
     */
    private function published( array $choices, array $blocks ): int {
        wp_set_current_user( $this->hod );
        $res = $this->send( 'POST', 'reports', $choices );
        $this->assertSame( 201, $res->get_status(), (string) wp_json_encode( $res->get_data() ) );
        $id  = (int) $res->get_data()['data']['id'];
        $res = $this->send( 'PATCH', "reports/{$id}", [ 'definition' => [ 'blocks' => $blocks ] ] );
        $this->assertSame( 200, $res->get_status(), (string) wp_json_encode( $res->get_data() ) );
        $this->assertSame( 201, $this->send( 'POST', "reports/{$id}/versions", [ 'note' => 'First' ] )->get_status() );
        wp_set_current_user( 0 );
        return $id;
    }

    private function snapshot( int $id ): string {
        wp_set_current_user( $this->hod );
        $res = $this->send( 'POST', "reports/{$id}/snapshots" );
        $this->assertSame( 201, $res->get_status(), (string) wp_json_encode( $res->get_data() ) );
        wp_set_current_user( 0 );
        return (string) $res->get_data()['data']['uuid'];
    }

    /** @param array<string,mixed> $params */
    private function send( string $method, string $route, array $params = [] ): WP_REST_Response {
        $req = new WP_REST_Request( $method, '/talenttrack/v1/' . $route );
        if ( $method === 'GET' ) {
            if ( $params !== [] ) $req->set_query_params( $params );
        } else {
            $req->set_header( 'Content-Type', 'application/json' );
            $req->set_body( (string) wp_json_encode( (object) $params ) );
        }
        $this->flush();
        return rest_do_request( $req );
    }

    private function code( WP_REST_Response $res ): string {
        return (string) ( $res->get_data()['errors'][0]['code'] ?? '' );
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
