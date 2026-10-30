<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Journey\InjuryRepository;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Activities\Services\AttendanceDateRule;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Journey\Frontend\FrontendInjuriesView;
use TT\Modules\Journey\JourneyModule;

/**
 * #4290 — the squad overview's audit row names the players it showed.
 *
 * `docs/injuries.md` promises that every read of a player's injuries is in
 * the log with which player. The overview wrote a row with a count and no
 * players, so a subject-access request for one child could not be answered
 * for reads made there. One row per read, carrying the ids of the rows the
 * page actually rendered, after its filters.
 */
final class InjuryOverviewAuditTest extends WP_UnitTestCase {

    private int $club = 0;
    private int $team = 0;
    private int $other_team = 0;
    private int $out = 0;
    private int $back = 0;
    private int $elsewhere = 0;

    public function set_up(): void {
        parent::set_up();
        $this->club = (int) CurrentClub::id();

        $roles = new RolesService();
        $roles->installRoles();
        $roles->ensureCapabilities();
        JourneyModule::ensureCapabilities();
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();

        $this->team       = $this->insertTeam( 'JO12-1' );
        $this->other_team = $this->insertTeam( 'JO12-2' );
        $this->out        = $this->insertPlayer( $this->team );
        $this->back       = $this->insertPlayer( $this->team );
        $this->elsewhere  = $this->insertPlayer( $this->other_team );

        $repo  = new InjuryRepository();
        $today = AttendanceDateRule::today();
        $repo->create( [ 'player_id' => $this->out, 'started_on' => $today ] );
        $repo->create( [ 'player_id' => $this->back, 'started_on' => '2026-08-01', 'actual_return' => '2026-08-20' ] );
        $repo->create( [ 'player_id' => $this->elsewhere, 'started_on' => $today ] );
    }

    public function tear_down(): void {
        unset( $_GET['team_id'], $_GET['status'] );
        wp_set_current_user( 0 );
        MatrixRepository::clearCache();
        parent::tear_down();
    }

    public function test_a_coach_opening_the_overview_logs_one_row_with_the_players_shown(): void {
        $coach = $this->makeHeadCoach( $this->team );
        wp_set_current_user( $coach );

        $before = $this->auditCount();
        $html   = $this->render( $coach, false );
        $this->assertSame( $before + 1, $this->auditCount(), 'one audit row per read, not one per player' );
        $this->assertStringContainsString( 'tt-injuries-table', $html );

        $payload = $this->lastPayload();
        $this->assertSame( 'injuries_overview', $payload['surface'] );
        $this->assertSame( [ $this->out ], $payload['player_ids'], 'the open injury on the coach\'s own team, nobody else' );
    }

    public function test_a_filtered_overview_logs_only_the_rows_it_shows(): void {
        $admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $admin );

        $this->render( $admin, true );
        $all_open = $this->lastPayload()['player_ids'];
        sort( $all_open );
        $this->assertSame( [ $this->out, $this->elsewhere ], $all_open, 'unfiltered: every open injury' );

        $_GET['team_id'] = (string) $this->other_team;
        $this->render( $admin, true );
        $this->assertSame( [ $this->elsewhere ], $this->lastPayload()['player_ids'], 'team filter' );

        $_GET['team_id'] = (string) $this->team;
        $_GET['status']  = 'recovered';
        $this->render( $admin, true );
        $this->assertSame( [ $this->back ], $this->lastPayload()['player_ids'], 'status filter' );
    }

    // ---- fixtures ------------------------------------------------------

    private function render( int $user_id, bool $is_admin ): string {
        ob_start();
        FrontendInjuriesView::render( $user_id, $is_admin );
        return (string) ob_get_clean();
    }

    private function auditCount(): int {
        global $wpdb;
        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_audit_log
              WHERE action = 'player.injuries_viewed' AND entity_type = 'club'"
        );
    }

    /** @return array<string, mixed> */
    private function lastPayload(): array {
        global $wpdb;
        $payload = json_decode( (string) $wpdb->get_var(
            "SELECT payload FROM {$wpdb->prefix}tt_audit_log
              WHERE action = 'player.injuries_viewed' AND entity_type = 'club'
              ORDER BY id DESC LIMIT 1"
        ), true );
        $this->assertIsArray( $payload, 'the overview read must be audit-logged' );
        $this->assertArrayHasKey( 'player_ids', $payload );
        return $payload;
    }

    private function insertTeam( string $name ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => $this->club, 'name' => $name ] );
        return (int) $wpdb->insert_id;
    }

    private function insertPlayer( int $team_id ): int {
        global $wpdb;
        $ok = $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id'    => $this->club,
            'team_id'    => $team_id,
            'first_name' => 'Audit',
            'last_name'  => 'Player',
            'status'     => 'active',
        ] );
        $this->assertNotFalse( $ok, 'player insert must succeed' );
        return (int) $wpdb->insert_id;
    }

    /** A head coach on one team, built the way production builds one (see PlayerSuspensionTest). */
    private function makeHeadCoach( int $team_id ): int {
        global $wpdb;
        $p   = $wpdb->prefix;
        $uid = self::factory()->user->create( [ 'role' => 'tt_coach' ] );

        $role_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}tt_roles WHERE role_key = %s AND club_id = %d LIMIT 1",
            'head_coach',
            $this->club
        ) );
        $this->assertGreaterThan( 0, $role_id, 'the head coach auth role must be seeded' );

        $wpdb->insert( "{$p}tt_people", [
            'club_id'    => $this->club,
            'first_name' => 'Head',
            'last_name'  => 'Coach',
            'role_type'  => 'head_coach',
            'wp_user_id' => $uid,
            'status'     => 'active',
        ] );
        $person_id = (int) $wpdb->insert_id;

        $wpdb->insert( "{$p}tt_team_people", [
            'club_id'       => $this->club,
            'team_id'       => $team_id,
            'person_id'     => $person_id,
            'role_in_team'  => 'head_coach',
            'is_head_coach' => 1,
        ] );
        $wpdb->insert( "{$p}tt_user_role_scopes", [
            'club_id'    => $this->club,
            'person_id'  => $person_id,
            'role_id'    => $role_id,
            'scope_type' => 'team',
            'scope_id'   => $team_id,
        ] );

        AuthorizationService::flushCache();
        return $uid;
    }
}
