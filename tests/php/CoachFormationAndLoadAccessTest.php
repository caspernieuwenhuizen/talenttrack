<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Authorization\PersonaResolver;
use TT\Modules\TeamDevelopment\TeamChemistryAccess;
use TT\Modules\Vct\Rest\VctWorkloadRestController;

/**
 * #3706 — an assistant coach could see the line-up on the pitch but not the
 * formation it came from, and neither coach persona could read the training
 * load they plan against.
 *
 * Two seed rows: `team_chemistry [read, team]` for the assistant coach, and
 * `vct_workload [read, team]` for both coach personas. Both are read-only
 * and both stop at the teams the holder is assigned to, which is what the
 * negative assertions below are for — the failure mode of an authorization
 * change is reaching a squad that is not yours.
 */
final class CoachFormationAndLoadAccessTest extends WP_UnitTestCase {

    private int $myTeam    = 0;
    private int $otherTeam = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;

        ( new RolesService() )->installRoles();
        MatrixRepository::clearCache();

        $wpdb->insert( $wpdb->prefix . 'tt_teams', [ 'club_id' => 1, 'name' => 'Chemistry Mine', 'age_group' => 'U15' ] );
        $this->myTeam = (int) $wpdb->insert_id;
        $wpdb->insert( $wpdb->prefix . 'tt_teams', [ 'club_id' => 1, 'name' => 'Chemistry Theirs', 'age_group' => 'U17' ] );
        $this->otherTeam = (int) $wpdb->insert_id;
    }

    public function tear_down(): void {
        MatrixRepository::clearCache();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    // ── fixtures ───────────────────────────────────────────────────────

    /**
     * A `tt_coach` WP user assigned to `$this->myTeam`. The persona split
     * is `tt_team_people.is_head_coach`, so the same fixture builds both.
     */
    private function makeCoach( bool $is_head_coach ): int {
        global $wpdb;
        $p = $wpdb->prefix;

        $uid = self::factory()->user->create( [ 'role' => 'tt_coach' ] );

        $wpdb->insert( "{$p}tt_people", [
            'club_id'    => 1,
            'first_name' => $is_head_coach ? 'Hoofd' : 'Assistent',
            'last_name'  => 'Trainer',
            'role_type'  => $is_head_coach ? 'head_coach' : 'assistant_coach',
            'wp_user_id' => $uid,
            'status'     => 'active',
        ] );
        $person_id = (int) $wpdb->insert_id;

        $wpdb->insert( "{$p}tt_team_people", [
            'team_id'       => $this->myTeam,
            'person_id'     => $person_id,
            'role_in_team'  => $is_head_coach ? 'head_coach' : 'assistant_coach',
            'is_head_coach' => $is_head_coach ? 1 : 0,
        ] );

        $wpdb->insert( "{$p}tt_user_role_scopes", [
            'person_id'  => $person_id,
            'role_id'    => 1,
            'scope_type' => 'team',
            'scope_id'   => $this->myTeam,
        ] );

        $expected = $is_head_coach ? 'head_coach' : 'assistant_coach';
        $this->assertContains(
            $expected,
            PersonaResolver::personasFor( $uid ),
            'the fixture must resolve to the persona under test or nothing below means anything'
        );

        return $uid;
    }

    private function canReadLoad( int $user_id, int $team_id ): bool {
        wp_set_current_user( $user_id );
        $request = new WP_REST_Request( 'GET', '/talenttrack/v1/vct/teams/' . $team_id . '/workload' );
        $request->set_param( 'id', $team_id );
        return VctWorkloadRestController::can_team( $request );
    }

    // ── team_chemistry: formation, blueprint, chemistry board ──────────

    public function test_an_assistant_coach_reads_the_formation_of_their_own_team(): void {
        $uid = $this->makeCoach( false );
        $this->assertTrue( TeamChemistryAccess::canReadForTeam( $uid, $this->myTeam ) );
    }

    public function test_an_assistant_coach_cannot_read_another_teams_formation(): void {
        $uid = $this->makeCoach( false );
        $this->assertFalse( TeamChemistryAccess::canReadForTeam( $uid, $this->otherTeam ) );
    }

    public function test_the_assistant_coachs_grant_is_read_only(): void {
        $uid = $this->makeCoach( false );
        $this->assertFalse(
            TeamChemistryAccess::canManageForTeam( $uid, $this->myTeam ),
            'authoring formations, blueprints and pairings stays with the head coach'
        );
    }

    public function test_the_head_coach_keeps_read_and_manage(): void {
        $uid = $this->makeCoach( true );
        $this->assertTrue( TeamChemistryAccess::canReadForTeam( $uid, $this->myTeam ) );
        $this->assertTrue( TeamChemistryAccess::canManageForTeam( $uid, $this->myTeam ) );
    }

    // ── vct_workload: team training load ───────────────────────────────

    public function test_both_coach_personas_read_their_own_teams_load(): void {
        $this->assertTrue( $this->canReadLoad( $this->makeCoach( true ), $this->myTeam ), 'head coach' );
        $this->assertTrue( $this->canReadLoad( $this->makeCoach( false ), $this->myTeam ), 'assistant coach' );
    }

    public function test_neither_coach_persona_reads_another_teams_load(): void {
        $this->assertFalse( $this->canReadLoad( $this->makeCoach( true ), $this->otherTeam ), 'head coach' );
        $this->assertFalse( $this->canReadLoad( $this->makeCoach( false ), $this->otherTeam ), 'assistant coach' );
    }

    // ── the top-up, for installs that already ran the seed ─────────────

    public function test_the_migration_gives_an_existing_install_the_same_rows(): void {
        global $wpdb;
        $table = "{$wpdb->prefix}tt_authorization_matrix";

        $wpdb->delete( $table, [ 'persona' => 'assistant_coach', 'entity' => 'team_chemistry' ] );
        $wpdb->delete( $table, [ 'persona' => 'assistant_coach', 'entity' => 'vct_workload' ] );
        $wpdb->delete( $table, [ 'persona' => 'head_coach',      'entity' => 'vct_workload' ] );

        $migration = require dirname( __DIR__, 2 ) . '/database/migrations/0276_authorization_seed_topup_coach_formation_load.php';
        $migration->up();
        // Twice, because a re-run must add nothing.
        $migration->up();

        $rows = $wpdb->get_results(
            "SELECT persona, entity, activity, scope_kind FROM {$table}
              WHERE ( persona = 'assistant_coach' AND entity IN ( 'team_chemistry', 'vct_workload' ) )
                 OR ( persona = 'head_coach' AND entity = 'vct_workload' )
              ORDER BY persona, entity",
            ARRAY_A
        );

        $this->assertSame( [
            [ 'persona' => 'assistant_coach', 'entity' => 'team_chemistry', 'activity' => 'read', 'scope_kind' => 'team' ],
            [ 'persona' => 'assistant_coach', 'entity' => 'vct_workload',   'activity' => 'read', 'scope_kind' => 'team' ],
            [ 'persona' => 'head_coach',      'entity' => 'vct_workload',   'activity' => 'read', 'scope_kind' => 'team' ],
        ], $rows );
    }
}
