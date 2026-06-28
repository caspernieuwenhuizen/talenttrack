<?php
namespace TT\Tests\Php;

use ReflectionMethod;
use WP_REST_Request;
use WP_UnitTestCase;
use TT\Infrastructure\REST\RecycleBinRestController;
use TT\Infrastructure\Archive\ArchiveRepository;
use TT\Infrastructure\RecycleBin\RecycleBinEntities;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * #2024 (epic #2018) — recycle-bin REST surface, security-critical.
 *
 * These rows are soft-deleted minors' PII, and this controller owns the only
 * permanent-deletion path the bin exposes. The tests assert the design
 * review's security items AT THE REST BOUNDARY (the domain invariants are
 * covered in RecycleBinLifecycleTest):
 *
 *   #1 IDOR — the mutating routes' permission_callback (canMutate) denies a
 *      forged / foreign-tenant id BEFORE the handler runs; a 0-row target is
 *      not a pass.
 *   #3 cross-academy aggregation — GET /recycle-bin never returns a club_id=2
 *      trashed row for CurrentClub::id()=1.
 *   #7 {entity} allowlist — the route validate_callback rejects an unknown
 *      entity (→ 400), accepts every entity-map key.
 */
final class RecycleBinRestControllerTest extends WP_UnitTestCase {

    private string $p;
    private ArchiveRepository $repo;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->p = $wpdb->prefix;
        $this->repo = new ArchiveRepository();
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
    }

    // ---- #7 allowlist ---------------------------------------------------

    public function test_entity_validate_callback_rejects_unknown_entity(): void {
        $validate = $this->entityValidator();

        $this->assertFalse( $validate( 'not_an_entity' ), 'an unknown entity is rejected (→ 400)' );
        $this->assertFalse( $validate( 'tt_players' ), 'a raw table name is not an entity key' );
        $this->assertFalse( $validate( '' ), 'empty entity is rejected' );
    }

    public function test_entity_validate_callback_accepts_every_entity_map_key(): void {
        $validate = $this->entityValidator();
        foreach ( RecycleBinEntities::keys() as $entity ) {
            $this->assertTrue( $validate( $entity ), "entity-map key '{$entity}' validates" );
        }
    }

    // ---- #1 IDOR / ownership backstop -----------------------------------

    public function test_canMutate_denies_a_foreign_tenant_id(): void {
        // A club_id=2 row: owned by another tenant, never by club 1.
        $foreign = $this->insertTeam( 'Other club', 2 );

        $allowed = $this->callCanMutate( 'team', $foreign );

        $this->assertFalse(
            $allowed,
            '#1 IDOR: a club_id=2 id fails ownership at the permission gate — never a 0-row success'
        );
    }

    public function test_canMutate_denies_an_absent_id(): void {
        $this->assertFalse( $this->callCanMutate( 'team', 9_999_999 ), 'an absent id is denied' );
        $this->assertFalse( $this->callCanMutate( 'team', 0 ), 'a zero id is denied' );
    }

    public function test_canMutate_denies_an_unknown_entity(): void {
        $id = $this->insertTeam( 'Mine' );
        $this->assertFalse(
            $this->callCanMutate( 'bogus_entity', $id ),
            'an unknown entity is denied at the gate even for a real id'
        );
    }

    public function test_canMutate_allows_an_owned_row_for_an_admin(): void {
        $id = $this->insertTeam( 'Mine' );
        $this->assertTrue(
            $this->callCanMutate( 'team', $id ),
            'an academy admin acting on an owned row passes the gate'
        );
    }

    public function test_canMutate_denies_a_user_without_the_cap(): void {
        $id = $this->insertTeam( 'Mine' );
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

        $this->assertFalse(
            $this->callCanMutate( 'team', $id ),
            'a user lacking tt_manage_recycle_bin is denied even on an owned row'
        );
    }

    // ---- #3 cross-academy aggregation -----------------------------------

    public function test_list_bin_never_leaks_a_foreign_club_trashed_row(): void {
        // A trashed row for club 1 …
        $mine = $this->insertTeam( 'Mine binned' );
        $this->archiveRow( 'tt_teams', $mine );
        $this->repo->trash( 'team', [ $mine ], get_current_user_id() );

        // … and a directly-binned row for club 2.
        $theirs = $this->insertTrashedTeam( 'Theirs binned', 2 );

        $resp = RecycleBinRestController::list_bin();
        $data = $resp->get_data();

        $this->assertTrue( $data['success'] );
        $ids = [];
        foreach ( $data['data']['groups'] as $group ) {
            if ( $group['entity'] === 'team' ) {
                foreach ( $group['rows'] as $row ) {
                    $ids[] = (int) $row['id'];
                }
            }
        }

        $this->assertContains( $mine, $ids, 'my trashed row surfaces in the REST list' );
        $this->assertNotContains(
            $theirs,
            $ids,
            '#3: a club_id=2 trashed row never appears for CurrentClub::id()=1'
        );
    }

    public function test_list_bin_row_carries_an_identity_anchor(): void {
        $id = $this->insertTeam( 'Identifiable team' );
        $this->archiveRow( 'tt_teams', $id );
        $this->repo->trash( 'team', [ $id ], get_current_user_id() );

        $data = RecycleBinRestController::list_bin()->get_data();

        $found = null;
        foreach ( $data['data']['groups'] as $group ) {
            foreach ( $group['rows'] as $row ) {
                if ( (int) $row['id'] === $id ) { $found = $row; break 2; }
            }
        }
        $this->assertNotNull( $found );
        $this->assertSame( 'Identifiable team', $found['identity'], 'row carries the resolved identity' );
        $this->assertArrayHasKey( 'days_until_purge', $found );
    }

    // ---- restore / purge handlers ---------------------------------------

    public function test_restore_of_a_non_binned_row_is_a_404(): void {
        // Owned but not in the bin → handler returns 404 (never false success).
        $id = $this->insertTeam( 'Archived only' );
        $this->archiveRow( 'tt_teams', $id );

        $req = new WP_REST_Request( 'POST' );
        $req->set_param( 'entity', 'team' );
        $req->set_param( 'id', $id );

        $resp = RecycleBinRestController::restore( $req );
        $this->assertSame( 404, $resp->get_status() );
        $this->assertFalse( $resp->get_data()['success'] );
    }

    public function test_restore_of_a_binned_row_succeeds(): void {
        $id = $this->insertTeam( 'Binned' );
        $this->archiveRow( 'tt_teams', $id );
        $this->repo->trash( 'team', [ $id ], get_current_user_id() );

        $req = new WP_REST_Request( 'POST' );
        $req->set_param( 'entity', 'team' );
        $req->set_param( 'id', $id );

        $resp = RecycleBinRestController::restore( $req );
        $this->assertSame( 200, $resp->get_status() );
        $this->assertTrue( $resp->get_data()['success'] );
        // Back in the archive tier, out of the bin.
        $this->assertNull( $this->col( 'tt_teams', 'trashed_at', $id ) );
        $this->assertNotNull( $this->col( 'tt_teams', 'archived_at', $id ) );
    }

    public function test_purge_of_a_binned_row_deletes_it(): void {
        $id = $this->insertTeam( 'To purge' );
        $this->archiveRow( 'tt_teams', $id );
        $this->repo->trash( 'team', [ $id ], get_current_user_id() );

        $req = new WP_REST_Request( 'DELETE' );
        $req->set_param( 'entity', 'team' );
        $req->set_param( 'id', $id );

        $resp = RecycleBinRestController::purge( $req );
        $this->assertSame( 200, $resp->get_status() );
        $this->assertTrue( $resp->get_data()['success'] );
        $this->assertNull( $this->row( 'tt_teams', $id ), 'the row is gone after purge' );
    }

    // ---- helpers --------------------------------------------------------

    private function entityValidator(): callable {
        $m = new ReflectionMethod( RecycleBinRestController::class, 'entityArg' );
        $m->setAccessible( true );
        $args = $m->invoke( null );
        return $args['entity']['validate_callback'];
    }

    private function callCanMutate( string $entity, int $id ): bool {
        $m = new ReflectionMethod( RecycleBinRestController::class, 'canMutate' );
        $m->setAccessible( true );
        $req = new WP_REST_Request( 'POST' );
        $req->set_param( 'entity', $entity );
        $req->set_param( 'id', $id );
        return (bool) $m->invoke( null, $req );
    }

    private function insertTeam( string $name, int $club = null ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_teams", [
            'club_id' => $club ?? CurrentClub::id(),
            'name'    => $name,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function insertTrashedTeam( string $name, int $club ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_teams", [
            'club_id'     => $club,
            'name'        => $name,
            'archived_at' => current_time( 'mysql' ),
            'archived_by' => get_current_user_id(),
            'trashed_at'  => current_time( 'mysql' ),
            'trashed_by'  => get_current_user_id(),
        ] );
        return (int) $wpdb->insert_id;
    }

    private function archiveRow( string $table, int $id ): void {
        global $wpdb;
        $wpdb->update( "{$this->p}{$table}", [
            'archived_at' => current_time( 'mysql' ),
            'archived_by' => get_current_user_id(),
        ], [ 'id' => $id ] );
    }

    /** @return string|null */
    private function col( string $table, string $column, int $id ) {
        global $wpdb;
        $v = $wpdb->get_var( $wpdb->prepare(
            "SELECT {$column} FROM {$this->p}{$table} WHERE id = %d", $id
        ) );
        return $v === null ? null : (string) $v;
    }

    private function row( string $table, int $id ): ?object {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT id FROM {$this->p}{$table} WHERE id = %d", $id
        ) );
    }
}
