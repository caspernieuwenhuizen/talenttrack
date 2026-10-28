<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_UnitTestCase;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Trials\Rest\TrialsRestController;
use TT\Modules\Trials\Security\TrialCaseAccessPolicy;
use TT\Modules\Trials\TrialsModule;

/**
 * `GET trial-cases` lists the cases the caller has a reason to see, and only
 * a trial manager lists them all. `tt_view_trial_synthesis` is held by every
 * coach; it says what they may read on a case they sit on, not which cases
 * exist, so a coach on one panel no longer receives every trialist's name
 * and decision in the club.
 *
 * The reasons are a panel seat, a scout's linked player, and the team-scoped
 * read a head coach holds on their own team (#2005). The last one lists
 * cases the head coach may not open, as the `?tt_view=trials` screen always
 * did, so "listed" is deliberately not a subset of "openable" here. What it
 * never does is reach another team.
 */
final class TrialCaseListVisibilityTest extends WP_UnitTestCase {

    private int $playerA = 0;
    private int $playerB = 0;
    private int $caseA = 0;
    private int $caseB = 0;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        TrialsModule::ensureCapabilities();
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();

        $this->playerA = $this->player( 'Panel' );
        $this->playerB = $this->player( 'Elsewhere' );
        $this->caseA   = $this->trialCase( $this->playerA );
        $this->caseB   = $this->trialCase( $this->playerB );
    }

    public function tear_down(): void {
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_a_coach_lists_only_the_case_they_sit_on(): void {
        // A head coach holds `trial_synthesis` (team scope in the seed), so
        // the coarse capability is really there. Their team has no cases, so
        // the team-scoped read adds nothing and the seat is the only reason.
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => (int) CurrentClub::id(), 'name' => 'Trials O11-1' ] );
        $coach = $this->teamCoach( (int) $wpdb->insert_id, true );
        $this->seat( $this->caseA, $coach );
        $this->assertTrue( user_can( $coach, 'tt_view_trial_synthesis' ), 'precondition: the coach holds the coarse capability' );

        $this->assertSame( [ $this->caseA ], $this->listIds( $coach ) );
        $this->assertTrue( TrialCaseAccessPolicy::canOpenCase( $coach, $this->caseA ) );
        $this->assertFalse( TrialCaseAccessPolicy::canOpenCase( $coach, $this->caseB ) );
    }

    public function test_a_player_filter_never_widens_the_list(): void {
        $coach = (int) self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $this->seat( $this->caseA, $coach );

        $this->assertSame( [], $this->listIds( $coach, [ 'player_id' => $this->playerB ] ) );
        $this->assertSame( [ $this->caseA ], $this->listIds( $coach, [ 'player_id' => $this->playerA ] ) );
    }

    public function test_a_coach_on_no_panel_lists_nothing(): void {
        $coach = (int) self::factory()->user->create( [ 'role' => 'tt_coach' ] );

        $this->assertSame( [], $this->listIds( $coach ) );
    }

    public function test_an_unassigned_seat_no_longer_lists_the_case(): void {
        $coach = (int) self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $this->seat( $this->caseA, $coach, '2026-09-15 10:00:00' );

        $this->assertSame( [], $this->listIds( $coach ) );
    }

    public function test_a_scout_lists_the_cases_of_linked_players(): void {
        $scout = (int) self::factory()->user->create( [ 'role' => 'tt_scout' ] );
        update_user_meta( $scout, 'tt_scout_player_ids', wp_json_encode( [ $this->playerB ] ) );
        AuthorizationService::flushCache();

        $this->assertSame( [ $this->caseB ], $this->listIds( $scout ) );
    }

    public function test_a_trial_manager_lists_every_case(): void {
        $hod = (int) self::factory()->user->create( [ 'role' => 'tt_head_dev' ] );

        $this->assertNull( TrialCaseAccessPolicy::visibleCaseFilter( $hod ) );
        $ids = $this->listIds( $hod );
        $this->assertContains( $this->caseA, $ids );
        $this->assertContains( $this->caseB, $ids );
    }

    public function test_a_head_coach_lists_their_own_teams_cases_and_no_other(): void {
        [ $own_team, $own_case, $other_case ] = $this->twoTeamsWithACaseEach();
        $coach = $this->teamCoach( $own_team, true );

        $this->assertSame( [ $own_case ], $this->listIds( $coach ) );
        $this->assertNotContains( $other_case, $this->listIds( $coach ) );
        $this->assertNotContains( $this->caseA, $this->listIds( $coach ) );
    }

    public function test_a_head_coachs_panel_seat_adds_to_their_team(): void {
        [ $own_team, $own_case ] = $this->twoTeamsWithACaseEach();
        $coach = $this->teamCoach( $own_team, true );
        $this->seat( $this->caseB, $coach );

        $expected = [ $own_case, $this->caseB ];
        sort( $expected );
        $this->assertSame( $expected, $this->listIds( $coach ) );
    }

    public function test_an_assistant_coach_holds_no_team_read_on_trial_cases(): void {
        [ $own_team ] = $this->twoTeamsWithACaseEach();
        $assistant = $this->teamCoach( $own_team, false );

        $this->assertSame( [], $this->listIds( $assistant ) );
    }

    public function test_the_screen_lists_what_the_api_lists(): void {
        [ $own_team ] = $this->twoTeamsWithACaseEach();
        $coach = $this->teamCoach( $own_team, true );
        wp_set_current_user( $coach );

        $method = new \ReflectionMethod( \TT\Shared\Frontend\FrontendTrialsManageView::class, 'renderList' );
        $method->setAccessible( true );
        ob_start();
        $method->invoke( null, $coach, false, false );
        $html = (string) ob_get_clean();

        $this->assertStringContainsString( 'OwnTeam', $html );
        $this->assertStringNotContainsString( 'OtherTeam', $html );
        $this->assertStringNotContainsString( 'Elsewhere', $html );
    }

    /**
     * Two teams with one trialist and one open case each.
     *
     * @return array{0:int,1:int,2:int} [own team, own case, other team's case]
     */
    private function twoTeamsWithACaseEach(): array {
        global $wpdb;
        $club = (int) CurrentClub::id();
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => $club, 'name' => 'Trials O13-1' ] );
        $own = (int) $wpdb->insert_id;
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => $club, 'name' => 'Trials O15-1' ] );
        $other = (int) $wpdb->insert_id;

        $own_case   = $this->trialCase( $this->player( 'OwnTeam', $own ) );
        $other_case = $this->trialCase( $this->player( 'OtherTeam', $other ) );
        return [ $own, $own_case, $other_case ];
    }

    private function teamCoach( int $team_id, bool $head ): int {
        global $wpdb;
        $p   = $wpdb->prefix;
        $uid = (int) self::factory()->user->create( [ 'role' => 'tt_coach' ] );

        $wpdb->insert( "{$p}tt_people", [
            'club_id'    => (int) CurrentClub::id(),
            'first_name' => $head ? 'Hoofd' : 'Assistent',
            'last_name'  => 'Trainer',
            'role_type'  => $head ? 'head_coach' : 'assistant_coach',
            'wp_user_id' => $uid,
            'status'     => 'active',
        ] );
        $person_id = (int) $wpdb->insert_id;

        $wpdb->insert( "{$p}tt_team_people", [
            'team_id'       => $team_id,
            'person_id'     => $person_id,
            'role_in_team'  => $head ? 'head_coach' : 'assistant_coach',
            'is_head_coach' => $head ? 1 : 0,
        ] );
        $wpdb->insert( "{$p}tt_user_role_scopes", [
            'club_id'    => (int) CurrentClub::id(),
            'person_id'  => $person_id,
            'role_id'    => 1,
            'scope_type' => 'team',
            'scope_id'   => $team_id,
        ] );
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        return $uid;
    }

    private function player( string $last, int $team_id = 0 ): int {
        global $wpdb;
        $row = [
            'club_id'    => (int) CurrentClub::id(),
            'first_name' => 'Trialist',
            'last_name'  => $last,
            'status'     => 'trial',
        ];
        // A player on a team roster: the roster helper (#2005) reads active
        // players only, so a team's trial case here is an active player's.
        if ( $team_id > 0 ) {
            $row['team_id'] = $team_id;
            $row['status']  = 'active';
        }
        $wpdb->insert( "{$wpdb->prefix}tt_players", $row );
        return (int) $wpdb->insert_id;
    }

    private function trialCase( int $player_id ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_trial_cases", [
            'club_id'    => (int) CurrentClub::id(),
            'player_id'  => $player_id,
            'status'     => 'open',
            'start_date' => '2026-09-01',
            'end_date'   => '2026-09-30',
            'created_by' => 1,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function seat( int $case_id, int $user_id, ?string $unassigned_at = null ): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_trial_case_staff", [
            'club_id'       => (int) CurrentClub::id(),
            'case_id'       => $case_id,
            'user_id'       => $user_id,
            'unassigned_at' => $unassigned_at,
        ] );
        AuthorizationService::flushCache();
    }

    /**
     * @param array<string, mixed> $params
     * @return list<int>
     */
    private function listIds( int $user, array $params = [] ): array {
        wp_set_current_user( $user );
        $request = new WP_REST_Request( 'GET', '/talenttrack/v1/trial-cases' );
        foreach ( $params as $key => $value ) {
            $request->set_param( $key, $value );
        }
        $data  = (array) TrialsRestController::list_cases( $request )->get_data();
        $cases = (array) ( ( (array) ( $data['data'] ?? [] ) )['cases'] ?? [] );
        $ids   = array_map( static fn( $c ): int => (int) ( ( (array) $c )['id'] ?? 0 ), $cases );
        sort( $ids );
        return $ids;
    }
}
