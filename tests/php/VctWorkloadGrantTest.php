<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Authorization\MatrixGate;
use TT\Modules\Authorization\Matrix\MatrixRepository;

/**
 * #3854 — the coach's workload grant is asserted, not assumed.
 *
 * #3706 gave the head coach and the assistant coach `vct_workload` at
 * team scope, and migration 0276 tops it up on installed matrices. Then
 * a reproduction said a coach still got 403, and nobody could tell from
 * the outside whether the top-up had run, had failed silently, or had
 * never been needed — because the seed file declaring a row is not
 * evidence that the row exists, and the top-up could not report either
 * way.
 *
 * (The reproduction turned out to predate the migration: on a current
 * build the grant resolves. That is exactly why this file exists — the
 * question "is the grant actually there?" should be answerable by
 * running the suite, not by querying somebody's database.)
 *
 * These tests fail if the seed row is dropped, if the scope changes, or
 * if the entity is renamed.
 */
final class VctWorkloadGrantTest extends WP_UnitTestCase {

    private int $teamId  = 0;
    private int $otherId = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;

        ( new RolesService() )->installRoles();
        MatrixRepository::clearCache();

        $wpdb->insert( $wpdb->prefix . 'tt_teams', [ 'club_id' => 1, 'name' => 'Load Mine' ] );
        $this->teamId = (int) $wpdb->insert_id;
        $wpdb->insert( $wpdb->prefix . 'tt_teams', [ 'club_id' => 1, 'name' => 'Load Theirs' ] );
        $this->otherId = (int) $wpdb->insert_id;
    }

    /** A coach user with a person row and an active scope on one team. */
    private function coachOn( int $team_id ): int {
        global $wpdb;
        $uid = self::factory()->user->create( [ 'role' => 'tt_coach' ] );

        $wpdb->insert( $wpdb->prefix . 'tt_people', [
            'club_id'    => 1,
            'first_name' => 'Load',
            'last_name'  => 'Coach',
            'role_type'  => 'head_coach',
            'wp_user_id' => $uid,
            'status'     => 'active',
        ] );
        $wpdb->insert( $wpdb->prefix . 'tt_user_role_scopes', [
            'person_id'  => (int) $wpdb->insert_id,
            'role_id'    => 1,
            'scope_type' => 'team',
            'scope_id'   => $team_id,
        ] );
        return $uid;
    }

    /** The seed must carry the row; without it the grant is theoretical. */
    private function grantExists(): bool {
        return ( new MatrixRepository() )->lookup(
            'head_coach',
            'vct_workload',
            MatrixGate::READ,
            MatrixGate::SCOPE_TEAM
        );
    }

    public function test_the_head_coach_seed_row_exists(): void {
        $this->assertTrue(
            $this->grantExists(),
            'head_coach must hold vct_workload read at team scope — #3706 put it there and 0276 tops it up'
        );
    }

    public function test_a_coach_reads_their_own_teams_workload(): void {
        $uid = $this->coachOn( $this->teamId );

        $this->assertTrue(
            MatrixGate::can( $uid, 'vct_workload', MatrixGate::READ, MatrixGate::SCOPE_TEAM, $this->teamId ),
            'a coach reads the load of the team they are assigned to'
        );
    }

    public function test_a_coach_does_not_read_another_teams_workload(): void {
        $uid = $this->coachOn( $this->teamId );

        $this->assertFalse(
            MatrixGate::can( $uid, 'vct_workload', MatrixGate::READ, MatrixGate::SCOPE_TEAM, $this->otherId ),
            'the grant is team-scoped; it must not reach a team the coach does not hold'
        );
    }

    /**
     * Planning stays with the roles that had it. The grant under test is
     * read-only, and a change grant appearing here would be a widening
     * nobody asked for.
     */
    public function test_the_grant_is_read_only(): void {
        $uid = $this->coachOn( $this->teamId );

        $this->assertFalse(
            MatrixGate::can( $uid, 'vct_workload', MatrixGate::CHANGE, MatrixGate::SCOPE_TEAM, $this->teamId ),
            'vct_workload is a read grant; nothing here may write load'
        );
    }

    /** `vct_library` is the admin-config entity and stays away from coaches. */
    public function test_a_coach_holds_no_vct_library_grant(): void {
        $uid = $this->coachOn( $this->teamId );

        $this->assertFalse(
            MatrixGate::canAnyScope( $uid, 'vct_library', MatrixGate::READ ),
            'macro-blocks and age profiles stay with the head of development'
        );
    }
}
