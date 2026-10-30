<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_UnitTestCase;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Authorization\PersonaResolver;

/**
 * Archiving a team, restoring a team and restoring a player act on one
 * record, so they ask about that record, the way `PUT` does.
 *
 * `tt_edit_teams` and `tt_edit_players` are held by every head coach for
 * their own squad. On their own they answered yes for any team or player
 * in the academy. The routes now ask `canManageTeam()` / `canEditPlayer()`
 * on the record in the URL: a head coach archives and restores their own
 * team and player, and is refused on another team's.
 */
final class ArchiveRestoreRecordCheckTest extends WP_UnitTestCase {

    private int $ownTeam     = 0;
    private int $otherTeam   = 0;
    private int $ownPlayer   = 0;
    private int $otherPlayer = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;

        $roles = new RolesService();
        $roles->installRoles();
        $roles->ensureCapabilities();
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();

        $club = (int) CurrentClub::id();
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => $club, 'name' => 'Record-check own', 'age_group' => 'U13' ] );
        $this->ownTeam = (int) $wpdb->insert_id;
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => $club, 'name' => 'Record-check other', 'age_group' => 'U14' ] );
        $this->otherTeam = (int) $wpdb->insert_id;
        $this->ownPlayer   = $this->makePlayer( 'Ownsquad', $this->ownTeam );
        $this->otherPlayer = $this->makePlayer( 'Othersquad', $this->otherTeam );

        do_action( 'rest_api_init' );
    }

    public function tear_down(): void {
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_a_head_coach_archives_and_restores_their_own_team_and_not_another(): void {
        $coach = $this->makeHeadCoach( $this->ownTeam );
        wp_set_current_user( $coach );

        $this->assertSame( 403, $this->status( 'DELETE', '/teams/' . $this->otherTeam ), 'archive another team' );
        $this->assertNull( $this->archivedAt( 'tt_teams', $this->otherTeam ), 'the other team is untouched' );
        $this->assertSame( 200, $this->status( 'DELETE', '/teams/' . $this->ownTeam ), 'archive own team' );

        $this->archiveAsAdmin( '/teams/' . $this->otherTeam );
        wp_set_current_user( $coach );
        $this->assertSame( 403, $this->status( 'POST', '/teams/' . $this->otherTeam . '/restore' ), 'restore another team' );
        $this->assertNotNull( $this->archivedAt( 'tt_teams', $this->otherTeam ), 'the other team stays archived' );
        $this->assertSame( 200, $this->status( 'POST', '/teams/' . $this->ownTeam . '/restore' ), 'restore own team' );
    }

    public function test_a_head_coach_restores_their_own_player_and_not_another_teams(): void {
        $this->archiveAsAdmin( '/players/' . $this->ownPlayer );
        $this->archiveAsAdmin( '/players/' . $this->otherPlayer );

        wp_set_current_user( $this->makeHeadCoach( $this->ownTeam ) );

        $this->assertSame( 403, $this->status( 'POST', '/players/' . $this->otherPlayer . '/restore' ), 'restore another team\'s player' );
        $this->assertNotNull( $this->archivedAt( 'tt_players', $this->otherPlayer ), 'that player stays archived' );
        $this->assertSame( 200, $this->status( 'POST', '/players/' . $this->ownPlayer . '/restore' ), 'restore own player' );
    }

    public function test_the_head_of_development_still_archives_and_restores_any_team(): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'tt_head_dev' ] ) );

        $this->assertSame( 200, $this->status( 'DELETE', '/teams/' . $this->otherTeam ) );
        $this->assertSame( 200, $this->status( 'POST', '/teams/' . $this->otherTeam . '/restore' ) );
    }

    // ── fixtures ───────────────────────────────────────────────────────

    private function status( string $method, string $route ): int {
        return rest_do_request( new WP_REST_Request( $method, '/talenttrack/v1' . $route ) )->get_status();
    }

    private function archiveAsAdmin( string $route ): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        $this->assertSame( 200, $this->status( 'DELETE', $route ), 'the fixture archives ' . $route );
    }

    private function archivedAt( string $table, int $id ): ?string {
        global $wpdb;
        $v = $wpdb->get_var( $wpdb->prepare( "SELECT archived_at FROM {$wpdb->prefix}{$table} WHERE id = %d", $id ) );
        return $v === null ? null : (string) $v;
    }

    private function makePlayer( string $last, int $team_id ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id'    => (int) CurrentClub::id(),
            'first_name' => 'Record',
            'last_name'  => $last,
            'team_id'    => $team_id,
            'status'     => 'active',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function makeHeadCoach( int $team_id ): int {
        global $wpdb;
        $p   = $wpdb->prefix;
        $uid = self::factory()->user->create( [ 'role' => 'tt_coach' ] );

        $wpdb->insert( "{$p}tt_people", [
            'club_id'    => (int) CurrentClub::id(),
            'first_name' => 'Hoofd',
            'last_name'  => 'Trainer',
            'role_type'  => 'head_coach',
            'wp_user_id' => $uid,
            'status'     => 'active',
        ] );
        $person_id = (int) $wpdb->insert_id;

        $wpdb->insert( "{$p}tt_team_people", [
            'team_id'       => $team_id,
            'person_id'     => $person_id,
            'role_in_team'  => 'head_coach',
            'is_head_coach' => 1,
        ] );
        $wpdb->insert( "{$p}tt_user_role_scopes", [
            'person_id'  => $person_id,
            'role_id'    => 1,
            'scope_type' => 'team',
            'scope_id'   => $team_id,
        ] );

        $this->assertContains( 'head_coach', PersonaResolver::personasFor( $uid ) );
        AuthorizationService::flushCache();
        return $uid;
    }
}
