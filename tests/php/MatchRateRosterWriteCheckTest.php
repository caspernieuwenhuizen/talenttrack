<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Activities\Repositories\AttendanceWriter;
use TT\Modules\Authorization\FunctionalRoleGrants;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\MatchPrep\Repositories\MatchPrepRepository;
use TT\Modules\Wizards\Evaluation\EvaluationInserter;
use TT\Modules\Wizards\Evaluation\RateActorsStep;
use TT\Modules\Wizards\Evaluation\ReviewStep;

/**
 * On a match whose register was never recorded, the rate roster (line-up
 * and substitutions) and the evaluation write check give the same answer:
 * a linked guest in the line-up is rated, a player who was not there is not.
 */
final class MatchRateRosterWriteCheckTest extends WP_UnitTestCase {

    private int $own_team   = 0;
    private int $other_team = 0;
    private int $match      = 0;
    private int $guest      = 0;
    private int $coach      = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        ( new RolesService() )->installRoles();
        MatrixRepository::clearCache();
        FunctionalRoleGrants::clearCache();
        AuthorizationService::flushCache();

        $p = $wpdb->prefix;
        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => 1, 'name' => 'Matchroster Own U12', 'age_group' => 'U12' ] );
        $this->own_team = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => 1, 'name' => 'Matchroster Other U12', 'age_group' => 'U12' ] );
        $this->other_team = (int) $wpdb->insert_id;

        $this->guest = $this->player( $this->other_team, 'Matchrosterguest' );
        $this->coach = $this->headCoachOf( $this->own_team );
        wp_set_current_user( $this->coach );

        // A match that was planned and lined up but never run through match
        // execution: no register rows exist for it.
        $wpdb->insert( "{$p}tt_activities", [
            'club_id' => 1, 'team_id' => $this->own_team, 'title' => 'Matchroster match',
            'session_date' => gmdate( 'Y-m-d', (int) strtotime( '-2 days' ) ),
            'activity_type_key' => 'match', 'activity_status_key' => 'planned', 'plan_state' => 'scheduled',
        ] );
        $this->match = (int) $wpdb->insert_id;

        $prep_repo = new MatchPrepRepository();
        $prep_id   = $prep_repo->ensureForActivity( $this->match );
        $this->assertGreaterThan( 0, $prep_id, 'fixture: the match prep was written' );
        $prep_repo->replaceLineupForHalf( $prep_id, 1, [ 1 => $this->guest ] );
    }

    public function tear_down(): void {
        MatrixRepository::clearCache();
        FunctionalRoleGrants::clearCache();
        AuthorizationService::flushCache();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_a_linked_guest_in_the_line_up_of_an_unfinished_match_is_rated(): void {
        $this->assertSame( 0, $this->registerRows( $this->match ), 'precondition: no register was recorded' );
        $this->assertContains( $this->guest, $this->rosterIds(), 'precondition: the rate roster offers the guest' );
        $this->assertFalse( EvaluationInserter::mayWriteFor( $this->coach, $this->guest ), 'precondition: not the coach\'s own player' );

        $this->assertContains( $this->guest, AttendanceWriter::attendedPlayerIds( $this->match ) );
        $this->assertTrue( EvaluationInserter::mayWriteForActivity( $this->coach, $this->guest, $this->match ) );

        $result = ( new ReviewStep() )->submit( [
            '_path' => 'activity-first', 'activity_id' => $this->match,
            'ratings' => [ $this->guest => [ 1 => 7 ] ],
        ] );
        $this->assertNotInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 1, $this->evaluationsFor( $this->guest ), 'the guest who played is rated' );
    }

    public function test_a_player_not_in_the_line_up_and_with_no_attendance_is_refused(): void {
        $stranger = $this->player( $this->other_team, 'Matchrosterstranger' );
        $this->assertNotContains( $stranger, $this->rosterIds(), 'precondition: the roster does not offer them' );

        $this->assertNotContains( $stranger, AttendanceWriter::attendedPlayerIds( $this->match ) );
        $this->assertFalse( EvaluationInserter::mayWriteForActivity( $this->coach, $stranger, $this->match ) );

        ( new ReviewStep() )->submit( [
            '_path' => 'activity-first', 'activity_id' => $this->match,
            'ratings' => [ $stranger => [ 1 => 7 ] ],
        ] );
        $this->assertSame( 0, $this->evaluationsFor( $stranger ) );
    }

    /** @return list<int> */
    private function rosterIds(): array {
        return array_map( static fn( $row ): int => (int) $row->id, RateActorsStep::ratablePlayersForActivity( $this->match ) );
    }

    private function headCoachOf( int $team_id ): int {
        global $wpdb;
        $p     = $wpdb->prefix;
        $coach = self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $wpdb->insert( "{$p}tt_people", [
            'club_id' => 1, 'first_name' => 'Matchroster', 'last_name' => 'Coachx',
            'role_type' => 'head_coach', 'wp_user_id' => $coach, 'status' => 'active',
        ] );
        $person = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_team_people", [
            'club_id' => 1, 'team_id' => $team_id, 'person_id' => $person,
            'role_in_team' => 'head_coach', 'is_head_coach' => 1,
        ] );
        $wpdb->insert( "{$p}tt_user_role_scopes", [
            'person_id' => $person, 'role_id' => 1, 'scope_type' => 'team', 'scope_id' => $team_id,
        ] );
        AuthorizationService::flushCache();
        return $coach;
    }

    private function registerRows( int $activity_id ): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_attendance WHERE activity_id = %d AND record_type = 'actual'",
            $activity_id
        ) );
    }

    private function evaluationsFor( int $player_id ): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_evaluations WHERE player_id = %d", $player_id
        ) );
    }

    private function player( int $team_id, string $first ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id' => 1, 'team_id' => $team_id, 'first_name' => $first, 'last_name' => 'Kidx',
            'status' => 'active', 'wp_user_id' => self::factory()->user->create( [ 'role' => 'tt_player' ] ),
        ] );
        $id = (int) $wpdb->insert_id;
        $this->assertGreaterThan( 0, $id, 'fixture: the player row was written' );
        return $id;
    }
}
