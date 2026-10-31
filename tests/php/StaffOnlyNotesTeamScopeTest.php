<?php
namespace TT\Tests\Php;

use ReflectionMethod;
use WP_UnitTestCase;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Threads\Domain\ThreadAccess;

/**
 * A team-scope grant of `staff_only_notes: change` counts on the team the
 * thread belongs to, not on any team. A coach who holds it on their own
 * squad is not staff on another team's player thread, however they reached
 * that thread.
 */
final class StaffOnlyNotesTeamScopeTest extends WP_UnitTestCase {

    private int $teamA = 0;
    private int $teamB = 0;
    private int $coach = 0;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();

        global $wpdb;
        $p    = $wpdb->prefix;
        $club = (int) CurrentClub::id();

        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $club, 'name' => 'Notes JO13-1' ] );
        $this->teamA = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $club, 'name' => 'Notes JO15-1' ] );
        $this->teamB = (int) $wpdb->insert_id;

        $this->coach = (int) self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $wpdb->insert( "{$p}tt_people", [
            'club_id'    => $club,
            'first_name' => 'Notes',
            'last_name'  => 'Coach',
            'role_type'  => 'head_coach',
            'wp_user_id' => $this->coach,
            'status'     => 'active',
        ] );
        $wpdb->insert( "{$p}tt_user_role_scopes", [
            'club_id'    => $club,
            'person_id'  => (int) $wpdb->insert_id,
            'role_id'    => 1,
            'scope_type' => 'team',
            'scope_id'   => $this->teamA,
        ] );
        AuthorizationService::flushCache();
    }

    public function tear_down(): void {
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        parent::tear_down();
    }

    public function test_the_right_counts_on_the_coachs_own_team(): void {
        $this->assertTrue( $this->holdsRight( 'player', $this->player( $this->teamA ) ) );
    }

    public function test_the_right_does_not_carry_to_another_teams_thread(): void {
        $this->assertFalse( $this->holdsRight( 'player', $this->player( $this->teamB ) ) );
    }

    public function test_a_thread_without_a_team_answers_to_the_global_grant_only(): void {
        $this->assertFalse( $this->holdsRight( 'player', $this->player( 0 ) ) );

        // The head of development holds the right at global scope in the seed.
        $hod = (int) self::factory()->user->create( [ 'role' => 'tt_head_dev' ] );
        AuthorizationService::flushCache();
        $this->assertTrue( $this->holdsRight( 'player', $this->player( 0 ), $hod ) );
        $this->assertTrue( $this->holdsRight( 'player', $this->player( $this->teamB ), $hod ) );
    }

    private function player( int $team_id ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id'    => (int) CurrentClub::id(),
            'team_id'    => $team_id,
            'first_name' => 'Notes',
            'last_name'  => 'Player',
            'status'     => 'active',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function holdsRight( string $thread_type, int $thread_id, ?int $user = null ): bool {
        $method = new ReflectionMethod( ThreadAccess::class, 'holdsStaffOnlyRight' );
        $method->setAccessible( true );
        return (bool) $method->invoke( null, $thread_type, $thread_id, $user ?? $this->coach );
    }
}
