<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Activities\Services\ActivityCompletionResolver;
use TT\Modules\Authorization\AllTeamsScope;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\MatchExecution\Repositories\MatchExecutionRepository;
use TT\Modules\MatchPrep\Repositories\MatchPrepRepository;

/**
 * "Complete activity" on a match that ran on the live sheet leads to the
 * finalize view, which writes to that match's team. The affordance asks the
 * same: a coach who also reads every team is offered it on their own
 * team's match only, while the team's coach and the head of development
 * keep it.
 */
final class CompletionWriteScopeTest extends WP_UnitTestCase {

    private int $own_team    = 0;
    private int $own_match   = 0;
    private int $other_match = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        ( new RolesService() )->installRoles();
        $this->flush();

        $p    = $wpdb->prefix;
        $club = (int) CurrentClub::id();
        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $club, 'name' => 'Completescope Own U15', 'age_group' => 'U15' ] );
        $this->own_team = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $club, 'name' => 'Completescope Other U15', 'age_group' => 'U15' ] );
        $other_team = (int) $wpdb->insert_id;

        $this->own_match   = $this->liveMatch( $this->own_team );
        $this->other_match = $this->liveMatch( $other_team );
    }

    public function tear_down(): void {
        $this->flush();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_a_coach_who_reads_every_team_completes_only_their_own_match(): void {
        $coach = $this->coach( true );
        $this->assertTrue( AllTeamsScope::canSeeAllTeamsActivities( $coach ), 'precondition: the seat reads every team' );

        $this->assertTrue( ActivityCompletionResolver::canComplete( $this->own_match, 'game', $coach ) );
        $this->assertFalse( ActivityCompletionResolver::canComplete( $this->other_match, 'game', $coach ) );
    }

    public function test_the_teams_coach_and_the_head_of_development_are_unchanged(): void {
        $coach = $this->coach( false );
        $this->assertTrue( ActivityCompletionResolver::canComplete( $this->own_match, 'game', $coach ) );

        $hod = self::factory()->user->create( [ 'role' => 'tt_head_dev' ] );
        $this->flush();
        $this->assertTrue( ActivityCompletionResolver::canComplete( $this->own_match, 'game', $hod ) );
        $this->assertTrue( ActivityCompletionResolver::canComplete( $this->other_match, 'game', $hod ) );
    }

    /** A head coach of the own team, optionally with the read-only observer seat too. */
    private function coach( bool $observer ): int {
        global $wpdb;
        $p     = $wpdb->prefix;
        $coach = self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $wpdb->insert( "{$p}tt_people", [
            'club_id' => (int) CurrentClub::id(), 'first_name' => 'Completescope', 'last_name' => 'Coachx',
            'role_type' => 'head_coach', 'wp_user_id' => $coach, 'status' => 'active',
        ] );
        $person = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_team_people", [
            'club_id' => (int) CurrentClub::id(), 'team_id' => $this->own_team, 'person_id' => $person,
            'role_in_team' => 'head_coach', 'is_head_coach' => 1,
        ] );
        $wpdb->insert( "{$p}tt_user_role_scopes", [
            'person_id' => $person, 'role_id' => 1, 'scope_type' => 'team', 'scope_id' => $this->own_team,
        ] );
        if ( $observer ) ( new \WP_User( $coach ) )->add_role( 'tt_readonly_observer' );
        $this->flush();
        return $coach;
    }

    /** A match with a match execution, so completion routes to the finalize view. */
    private function liveMatch( int $team_id ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_activities", [
            'club_id' => (int) CurrentClub::id(), 'team_id' => $team_id, 'title' => 'Completescope match',
            'session_date' => gmdate( 'Y-m-d', (int) strtotime( '-1 day' ) ),
            'activity_type_key' => 'game', 'activity_status_key' => 'planned', 'plan_state' => 'scheduled',
        ] );
        $activity = (int) $wpdb->insert_id;
        $prep     = ( new MatchPrepRepository() )->ensureForActivity( $activity );
        ( new MatchExecutionRepository() )->ensureForActivity( $activity, $prep );
        $this->assertTrue( ActivityCompletionResolver::routesToMatchExecution( $activity, 'game' ), 'fixture: completion routes to the finalize view' );
        return $activity;
    }

    private function flush(): void {
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
    }
}
