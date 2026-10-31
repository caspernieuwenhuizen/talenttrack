<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Authorization\PersonaResolver;
use TT\Modules\Export\Domain\ExportRequest;
use TT\Modules\Export\Exporters\KpiSnapshotXlsxExporter;

/**
 * The KPI snapshot is for readers of all teams' reports, and its
 * per-player potential sheet for those who also read potential
 * academy-wide.
 *
 * Every figure in the snapshot is academy-wide, and sheet 2 lists every
 * active player with name, team and potential band. The exporter asked only
 * `tt_view_reports`, which coaches, scouts and observers hold for their own
 * scope. It now asks all-teams reports for the export, and a global
 * `player_potential` read for the band sheet.
 */
final class KpiSnapshotAccessTest extends WP_UnitTestCase {

    private int $team = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $roles = new RolesService();
        $roles->installRoles();
        $roles->ensureCapabilities();
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();

        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => (int) CurrentClub::id(), 'name' => 'KPI U12' ] );
        $this->team = (int) $wpdb->insert_id;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id' => (int) CurrentClub::id(), 'first_name' => 'Kpi', 'last_name' => 'Player',
            'team_id' => $this->team, 'status' => 'active',
        ] );
    }

    public function tear_down(): void {
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        parent::tear_down();
    }

    /** @return array<string,mixed> */
    private function sheetsFor( int $user_id ): array {
        $payload = ( new KpiSnapshotXlsxExporter() )->collect( new ExportRequest(
            'kpi_snapshot', 'xlsx', (int) CurrentClub::id(), $user_id, null,
            [ 'date_from' => '2026-01-01', 'date_to' => '2026-12-31' ]
        ) );
        return (array) ( $payload['sheets'] ?? [] );
    }

    private function seesPotential( int $user_id ): bool {
        return array_key_exists( KpiSnapshotXlsxExporter::potentialSheetName(), $this->sheetsFor( $user_id ) );
    }

    public function test_the_head_of_development_and_the_club_admin_get_the_full_snapshot(): void {
        foreach ( [ 'tt_head_dev', 'tt_club_admin' ] as $role ) {
            $uid = self::factory()->user->create( [ 'role' => $role ] );
            $this->assertTrue( ( new KpiSnapshotXlsxExporter() )->isAvailableFor( $uid ), $role );
            $this->assertTrue( $this->seesPotential( $uid ), $role . ' reads the potential sheet' );
        }
    }

    public function test_scout_and_observer_never_see_the_potential_sheet(): void {
        foreach ( [ 'tt_scout', 'tt_readonly_observer' ] as $role ) {
            $uid = self::factory()->user->create( [ 'role' => $role ] );
            $this->assertFalse( $this->seesPotential( $uid ), $role );
        }
    }

    public function test_the_coaches_cannot_take_an_academy_wide_snapshot(): void {
        foreach ( [ true, false ] as $head ) {
            $uid = $this->makeCoach( $head );
            $this->assertFalse( ( new KpiSnapshotXlsxExporter() )->isAvailableFor( $uid ), $head ? 'head coach' : 'assistant coach' );
            $this->assertSame( [], $this->sheetsFor( $uid ), 'collect() refuses too' );
        }
    }

    public function test_the_potential_sheet_needs_a_global_potential_read(): void {
        $this->assertFalse( KpiSnapshotXlsxExporter::mayReadPotential( $this->makeCoach( true ) ), 'team-scoped potential is not academy-wide' );
        $this->assertTrue( KpiSnapshotXlsxExporter::mayReadPotential( self::factory()->user->create( [ 'role' => 'tt_head_dev' ] ) ) );
    }

    private function makeCoach( bool $is_head_coach ): int {
        global $wpdb;
        $p   = $wpdb->prefix;
        $uid = self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $wpdb->insert( "{$p}tt_people", [
            'club_id' => (int) CurrentClub::id(), 'first_name' => 'Kpi', 'last_name' => 'Coach',
            'role_type' => $is_head_coach ? 'head_coach' : 'assistant_coach', 'wp_user_id' => $uid, 'status' => 'active',
        ] );
        $person = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_team_people", [
            'team_id' => $this->team, 'person_id' => $person,
            'role_in_team' => $is_head_coach ? 'head_coach' : 'assistant_coach', 'is_head_coach' => $is_head_coach ? 1 : 0,
        ] );
        $wpdb->insert( "{$p}tt_user_role_scopes", [
            'person_id' => $person, 'role_id' => 1, 'scope_type' => 'team', 'scope_id' => $this->team,
        ] );
        $this->assertContains( $is_head_coach ? 'head_coach' : 'assistant_coach', PersonaResolver::personasFor( $uid ) );
        AuthorizationService::flushCache();
        return $uid;
    }
}
