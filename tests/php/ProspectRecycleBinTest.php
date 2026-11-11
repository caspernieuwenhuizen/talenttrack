<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Archive\ArchiveRepository;
use TT\Infrastructure\Audit\AuditService;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Prospects\Domain\ProspectDeletion;
use TT\Modules\Prospects\Repositories\ProspectConsentRequestsRepository;

/**
 * #4840 — a prospect is deleted to the recycle bin, restored back where it
 * was, and purged with its observations, consent log and tasks.
 *
 * The purge commits the per-test transaction, so the fixtures are swept with
 * `CommitsAfterCascade`.
 */
final class ProspectRecycleBinTest extends WP_UnitTestCase {

    use CommitsAfterCascade;

    private string $p;
    private int $scout = 0;
    private int $hod   = 0;
    private int $coach = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb, $wp_rest_server;
        $this->p = $wpdb->prefix;
        $this->markFixtureFloor();

        $roles = new RolesService();
        $roles->installRoles();
        $roles->ensureCapabilities();
        MatrixRepository::clearCache();

        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        $this->scout = self::factory()->user->create( [ 'role' => 'tt_scout' ] );
        $this->hod   = self::factory()->user->create( [ 'role' => 'tt_head_dev' ] );
        $this->coach = self::factory()->user->create( [ 'role' => 'tt_coach' ] );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        $this->cleanUpAndCommit();
        parent::tear_down();
    }

    public function test_a_scout_deletes_their_own_prospect_and_it_goes_to_the_bin(): void {
        $id = $this->prospect( 'Sem', 'Bakker', $this->scout );

        $response = $this->delete( $this->scout, $id );

        $this->assertSame( 200, $response->get_status() );
        $row = $this->row( $id );
        $this->assertNotNull( $row['trashed_at'] );
        $this->assertSame( (string) $this->scout, (string) $row['trashed_by'] );
        $this->assertSame( ProspectDeletion::ARCHIVE_REASON, $row['archive_reason'], 'archived by its own delete' );
    }

    public function test_the_head_of_development_deletes_any_prospect(): void {
        $id = $this->prospect( 'Noor', 'Visser', $this->scout );

        $this->assertSame( 200, $this->delete( $this->hod, $id )->get_status() );
        $this->assertNotNull( $this->row( $id )['trashed_at'] );
    }

    public function test_a_reader_without_the_delete_right_is_refused(): void {
        $id = $this->prospect( 'Daan', 'Smit', $this->scout );

        $this->assertFalse( ProspectDeletion::canDelete( $this->coach ) );
        $this->assertSame( 403, $this->delete( $this->coach, $id )->get_status() );
        $this->assertNull( $this->row( $id )['trashed_at'] );
    }

    public function test_a_prospect_who_became_a_player_answers_409(): void {
        global $wpdb;
        $id = $this->prospect( 'Luuk', 'Jansen', $this->scout );
        $wpdb->update( "{$this->p}tt_prospects", [ 'promoted_to_player_id' => 991 ], [ 'id' => $id ] );

        $response = $this->delete( $this->hod, $id );

        $this->assertSame( 409, $response->get_status() );
        $data = (array) $response->get_data();
        $this->assertSame( ProspectDeletion::ERR_IS_PLAYER, (string) ( $data['errors'][0]['code'] ?? '' ) );
        $this->assertNull( $this->row( $id )['trashed_at'] );
    }

    public function test_a_prospect_in_the_bin_is_not_found(): void {
        $id = $this->prospect( 'Mila', 'Bos', $this->scout );
        $this->delete( $this->hod, $id );

        wp_set_current_user( $this->hod );
        $this->assertSame( 404, rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/talenttrack/v1/prospects/' . $id ) )->get_status() );
        $list = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/talenttrack/v1/prospects' ) );
        $ids  = array_map( static fn( $r ): int => (int) ( (array) $r )['id'], (array) ( ( (array) $list->get_data() )['data']['rows'] ?? [] ) );
        $this->assertNotContains( $id, $ids );
        $this->assertSame( 404, $this->delete( $this->hod, $id )->get_status(), 'a second delete finds nothing' );
    }

    public function test_restoring_an_active_prospect_puts_it_back_on_the_pipeline_with_its_records(): void {
        $id = $this->prospect( 'Finn', 'Peters', $this->scout );
        $this->childRecords( $id );
        $this->delete( $this->hod, $id );

        $restored = ( new ArchiveRepository() )->restoreFromTrash( 'prospect', [ $id ], $this->hod );

        $this->assertSame( 1, $restored );
        $row = $this->row( $id );
        $this->assertNull( $row['trashed_at'] );
        $this->assertNull( $row['archived_at'], 'back on the pipeline' );
        $this->assertNull( $row['archive_reason'] );
        $this->assertSame( 'Quick feet, strong left foot.', $row['scouting_notes'] );
        $this->assertSame( [ 1, 1, 1 ], $this->childCounts( $id ), 'observations, consent requests and tasks are kept' );
    }

    public function test_restoring_an_archived_prospect_keeps_it_archived(): void {
        global $wpdb;
        $id = $this->prospect( 'Tess', 'Kok', $this->scout );
        $wpdb->update( "{$this->p}tt_prospects", [ 'archived_at' => '2026-10-01 10:00:00', 'archived_by' => $this->hod, 'archive_reason' => 'declined' ], [ 'id' => $id ] );
        $this->delete( $this->hod, $id );

        ( new ArchiveRepository() )->restoreFromTrash( 'prospect', [ $id ], $this->hod );

        $row = $this->row( $id );
        $this->assertNull( $row['trashed_at'] );
        $this->assertSame( '2026-10-01 10:00:00', $row['archived_at'] );
        $this->assertSame( 'declined', $row['archive_reason'] );
    }

    public function test_the_trashed_audit_row_carries_the_state_before_the_delete(): void {
        $id = $this->prospect( 'Jip', 'Mulder', $this->scout );
        $this->delete( $this->hod, $id );

        $events = ( new AuditService() )->recent( 5, [ 'action' => 'prospect.trashed', 'entity_type' => 'prospect' ] );
        $this->assertNotEmpty( $events );
        $payload = json_decode( (string) ( ( (array) $events[0] )['payload'] ?? '' ), true );
        $this->assertFalse( $payload['context']['previous']['archived'] ?? null, 'it was on the pipeline' );
    }

    public function test_the_preview_counts_and_the_purge_removes_every_record(): void {
        $id = $this->prospect( 'Roos', 'Vos', $this->scout );
        $this->childRecords( $id );
        $this->delete( $this->hod, $id );

        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        $preview = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/talenttrack/v1/recycle-bin/preview/prospect/' . $id ) );
        $this->assertSame( 200, $preview->get_status() );
        $removals = [];
        foreach ( (array) ( ( (array) $preview->get_data() )['data']['removals'] ?? [] ) as $r ) {
            $removals[ (string) $r['table'] ] = (int) $r['count'];
        }
        $this->assertSame( 1, $removals['tt_prospect_visit_observations'] ?? 0 );
        $this->assertSame( 1, $removals['tt_prospect_consent_requests'] ?? 0 );
        $this->assertSame( 1, $removals['tt_workflow_tasks'] ?? 0 );

        $audit  = new AuditService();
        $before = $audit->count( [ 'action' => 'prospect.purged' ] );
        $this->assertSame( 1, ( new ArchiveRepository() )->purge( 'prospect', [ $id ], get_current_user_id() ) );

        $this->assertNull( $this->row( $id ) );
        $this->assertSame( [ 0, 0, 0 ], $this->childCounts( $id ) );
        $this->assertSame( $before + 1, $audit->count( [ 'action' => 'prospect.purged' ] ) );
    }

    public function test_restore_writes_an_audit_row(): void {
        $id = $this->prospect( 'Bo', 'Dekker', $this->scout );
        $this->delete( $this->hod, $id );

        $audit  = new AuditService();
        $before = $audit->count( [ 'action' => 'prospect.restored' ] );
        ( new ArchiveRepository() )->restoreFromTrash( 'prospect', [ $id ], $this->hod );
        $this->assertSame( $before + 1, $audit->count( [ 'action' => 'prospect.restored' ] ) );
        $this->assertGreaterThan( 0, $audit->count( [ 'action' => 'prospect.trashed' ] ) );
    }

    private function delete( int $user_id, int $id ): WP_REST_Response {
        wp_set_current_user( $user_id );
        return rest_get_server()->dispatch( new WP_REST_Request( 'DELETE', '/talenttrack/v1/prospects/' . $id ) );
    }

    private function prospect( string $first, string $last, int $by ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_prospects", [
            'club_id'               => 1,
            'first_name'            => $first,
            'last_name'             => $last,
            'discovered_at'         => current_time( 'Y-m-d' ),
            'discovered_by_user_id' => $by,
            'scouting_notes'        => 'Quick feet, strong left foot.',
        ] );
        $id = (int) $wpdb->insert_id;
        $this->assertGreaterThan( 0, $id, 'prospect insert: ' . $wpdb->last_error );
        return $id;
    }

    private function childRecords( int $prospect_id ): void {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_prospect_visit_observations", [
            'uuid'              => wp_generate_uuid4(),
            'club_id'           => 1,
            'prospect_id'       => $prospect_id,
            'scouting_visit_id' => 4840,
            'observed_at'       => current_time( 'Y-m-d' ),
        ] );
        ( new ProspectConsentRequestsRepository() )->create( $prospect_id, current_time( 'Y-m-d' ), 'Youth coordinator', 'awaiting' );
        $wpdb->insert( "{$this->p}tt_workflow_tasks", [
            'club_id'          => 1,
            'template_key'     => 'request_consent',
            'assignee_user_id' => $this->hod,
            'status'           => 'open',
            'due_at'           => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
            'prospect_id'      => $prospect_id,
        ] );
        $this->assertSame( [ 1, 1, 1 ], $this->childCounts( $prospect_id ), 'fixtures written' );
    }

    /** @return array{0:int,1:int,2:int} observations, consent requests, tasks */
    private function childCounts( int $prospect_id ): array {
        global $wpdb;
        $out = [];
        foreach ( [ 'tt_prospect_visit_observations', 'tt_prospect_consent_requests', 'tt_workflow_tasks' ] as $table ) {
            $out[] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->p}{$table} WHERE prospect_id = %d", $prospect_id ) );
        }
        return [ $out[0], $out[1], $out[2] ];
    }

    /** @return array<string,mixed>|null */
    private function row( int $id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->p}tt_prospects WHERE id = %d", $id ), ARRAY_A );
        return is_array( $row ) ? $row : null;
    }
}
