<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Export\Domain\ExportRequest;
use TT\Modules\Export\ExporterRegistry;
use TT\Modules\Export\ExportException;
use TT\Modules\Export\ExportScope;
use TT\Modules\Export\ScopeGatedExporter;

/**
 * Bulk exports follow the caller's squads, not the club.
 *
 * The coarse gate asked for a `tt_view_*` capability, which the matrix
 * bridge answers at ANY scope — so a family's grant over their own child was
 * enough, and none of the exporters narrowed. `ExportScope` draws three
 * lines: unrestricted for global holders, narrowed for squad holders,
 * refused for everyone else.
 *
 * Every refusal below is paired with a grant for a caller who should get
 * through, and the fixture proves it resolves before anything is asserted
 * about what it cannot reach. A suite of refusals alone would pass with the
 * exporters deleted.
 */
final class ExportScopeTest extends WP_UnitTestCase {

    private const SEVEN = [
        'players_list'        => 'players',
        'federation_json'     => 'players',
        'team_roster_stats'   => 'players',
        'player_evaluations'  => 'evaluations',
        'evaluations_xlsx'    => 'evaluations',
        'goals_list'          => 'goals',
        'attendance_register' => 'activities',
    ];

    private int $parent = 0;
    private int $admin  = 0;
    private int $child  = 0;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        MatrixRepository::clearCache();

        $this->admin  = self::factory()->user->create( [ 'role' => 'tt_club_admin' ] );
        $this->parent = self::factory()->user->create( [ 'role' => 'tt_parent' ] );
        $this->child  = $this->makePlayer( 'Kind' );

        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_player_parents", [
            'club_id'        => (int) CurrentClub::id(),
            'player_id'      => $this->child,
            'parent_user_id' => $this->parent,
        ] );
        AuthorizationService::flushCache();

        $this->assertTrue(
            AuthorizationService::canViewPlayer( $this->parent, $this->child ),
            'the guardian link must resolve, or the refusals below prove nothing'
        );
    }

    public function test_a_parent_is_refused_every_bulk_export_not_narrowed(): void {
        foreach ( self::SEVEN as $key => $entity ) {
            $this->assertFalse(
                ExportScope::mayExport( $this->parent, $entity ),
                "{$key}: a family has no squad to export"
            );

            $exporter = ExporterRegistry::get( $key );
            $this->assertInstanceOf(
                ScopeGatedExporter::class,
                $exporter,
                "{$key} answers the coarse gate through its scope, not the bare capability"
            );
            $this->assertFalse(
                $exporter->isAvailableFor( $this->parent ),
                "{$key}: refused at the coarse gate, so it is not offered either"
            );
            $this->assertTrue( $exporter->isAvailableFor( $this->admin ), "{$key}: and still offered to an administrator" );
        }
    }

    public function test_an_administrator_is_unrestricted_on_every_bulk_export(): void {
        foreach ( self::SEVEN as $key => $entity ) {
            $this->assertTrue( ExportScope::mayExport( $this->admin, $entity ), "{$key}" );
            $this->assertNull(
                ExportScope::teamIdsFor( $this->admin, $entity ),
                "{$key}: no narrowing for an academy-wide reader"
            );
        }
    }

    public function test_the_authoritative_check_throws_for_a_parent_even_past_the_coarse_gate(): void {
        // `collect()` must refuse on its own: the coarse gate is a courtesy,
        // and a future caller of an exporter must not be able to skip it.
        $exporter = ExporterRegistry::get( 'players_list' );
        $this->assertNotNull( $exporter );
        $filters = [ 'team_id' => 0, 'status' => 'all' ];

        $caught = null;
        try {
            $exporter->collect( new ExportRequest( 'players_list', 'csv', (int) CurrentClub::id(), $this->parent, null, $filters ) );
        } catch ( ExportException $e ) {
            $caught = $e->errorKey;
        }
        $this->assertSame( 'forbidden', $caught, 'a parent must not reach the rows' );

        $rows = $exporter->collect( new ExportRequest( 'players_list', 'csv', (int) CurrentClub::id(), $this->admin, null, $filters ) )['rows'];
        $this->assertNotEmpty( $rows, 'the same export still works for an administrator' );
    }

    public function test_an_empty_team_list_never_becomes_no_filter(): void {
        $this->assertSame( '1 = 0', ExportScope::inClause( 'pl.team_id', [] ) );
        $this->assertSame( 'pl.team_id IN (3,7)', ExportScope::inClause( 'pl.team_id', [ 3, '7', 0, -2 ] ) );
    }

    private function makePlayer( string $last ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id'    => (int) CurrentClub::id(),
            'first_name' => 'Export',
            'last_name'  => $last,
            'status'     => 'active',
        ] );
        return (int) $wpdb->insert_id;
    }
}
