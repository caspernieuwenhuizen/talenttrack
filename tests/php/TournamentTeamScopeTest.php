<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\REST\TournamentsRestController;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Authorization\PersonaResolver;
use TT\Modules\Tournaments\TournamentAccess;

/**
 * #3703 — the tournament planner shipped admin-only, so on the day it is
 * played the people who pick the squad and share out the minutes were
 * locked out of it. They logged the day as a plain activity instead, and
 * its matches and minutes never reached the module.
 *
 * Both coach personas and the team manager now hold `tournaments rcd` at
 * team scope, head of development at global. The interesting half is
 * what "team scope" means when a tournament's squad is drawn from more
 * than one age group, which is the case most of these tests build:
 *
 *   - read and change need ANY participating team the actor holds;
 *   - delete needs ALL of them, because deleting is not partial.
 */
final class TournamentTeamScopeTest extends WP_UnitTestCase {

    private int $myTeam    = 0;
    private int $otherTeam = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;

        ( new RolesService() )->installRoles();
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();

        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => 1, 'name' => 'Toernooi O13-1', 'age_group' => 'U13' ] );
        $this->myTeam = (int) $wpdb->insert_id;
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => 1, 'name' => 'Toernooi O15-2', 'age_group' => 'U15' ] );
        $this->otherTeam = (int) $wpdb->insert_id;

        TournamentsRestController::init();
        do_action( 'rest_api_init' );
    }

    public function tear_down(): void {
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    // ── fixtures ───────────────────────────────────────────────────────

    private function makeCoach( int $team_id, bool $is_head_coach = true ): int {
        global $wpdb;
        $p = $wpdb->prefix;

        $uid = self::factory()->user->create( [ 'role' => 'tt_coach' ] );

        $wpdb->insert( "{$p}tt_people", [
            'club_id'    => 1,
            'first_name' => 'Toernooi',
            'last_name'  => 'Trainer',
            'role_type'  => $is_head_coach ? 'head_coach' : 'assistant_coach',
            'wp_user_id' => $uid,
            'status'     => 'active',
        ] );
        $person_id = (int) $wpdb->insert_id;

        $wpdb->insert( "{$p}tt_team_people", [
            'club_id'       => 1,
            'team_id'       => $team_id,
            'person_id'     => $person_id,
            'role_in_team'  => $is_head_coach ? 'head_coach' : 'assistant_coach',
            'is_head_coach' => $is_head_coach ? 1 : 0,
        ] );
        $wpdb->insert( "{$p}tt_user_role_scopes", [
            'person_id'  => $person_id,
            'role_id'    => 1,
            'scope_type' => 'team',
            'scope_id'   => $team_id,
        ] );

        $this->assertContains(
            $is_head_coach ? 'head_coach' : 'assistant_coach',
            PersonaResolver::personasFor( $uid )
        );
        AuthorizationService::flushCache();
        return $uid;
    }

    private function makeHeadOfDevelopment(): int {
        $uid = self::factory()->user->create( [ 'role' => 'tt_head_dev' ] );
        AuthorizationService::flushCache();
        return $uid;
    }

    private function makePlayer( int $team_id ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id'    => 1,
            'first_name' => 'Speler',
            'last_name'  => 'Van Team' . $team_id,
            'team_id'    => $team_id,
            'status'     => 'active',
        ] );
        return (int) $wpdb->insert_id;
    }

    /**
     * A tournament anchored on `$anchor_team_id`, with one squad player
     * from each team in `$squad_team_ids`.
     *
     * @param list<int> $squad_team_ids
     */
    private function makeTournament( int $anchor_team_id, array $squad_team_ids = [] ): int {
        global $wpdb;
        $p = $wpdb->prefix;

        $wpdb->insert( "{$p}tt_tournaments", [
            'uuid'       => wp_generate_uuid4(),
            'club_id'    => 1,
            'name'       => 'Najaarstoernooi',
            'start_date' => '2026-10-24',
            'team_id'    => $anchor_team_id,
            'created_by' => 1,
        ] );
        $tournament_id = (int) $wpdb->insert_id;

        foreach ( $squad_team_ids as $team_id ) {
            $wpdb->insert( "{$p}tt_tournament_squad", [
                'tournament_id'      => $tournament_id,
                'player_id'          => $this->makePlayer( $team_id ),
                'club_id'            => 1,
                'eligible_positions' => (string) wp_json_encode( [ 'MID' ] ),
            ] );
        }

        return $tournament_id;
    }

    // ── the participating set ──────────────────────────────────────────

    public function test_participating_teams_are_the_anchor_plus_every_squad_members_team(): void {
        $tournament = $this->makeTournament( $this->myTeam, [ $this->myTeam, $this->otherTeam ] );

        $expected = [ $this->myTeam, $this->otherTeam ];
        sort( $expected );

        $this->assertSame( $expected, TournamentAccess::participatingTeamIds( $tournament ) );
    }

    // ── read and change: any participating team is enough ──────────────

    public function test_a_coach_reads_and_plans_their_own_teams_tournament(): void {
        $coach      = $this->makeCoach( $this->myTeam );
        $tournament = $this->makeTournament( $this->myTeam );

        $this->assertTrue( AuthorizationService::canViewTournament( $coach, $tournament ) );
        $this->assertTrue( AuthorizationService::canEditTournament( $coach, $tournament ) );
    }

    public function test_a_coach_reaches_neither_a_tournament_with_none_of_their_teams(): void {
        $coach      = $this->makeCoach( $this->myTeam );
        $tournament = $this->makeTournament( $this->otherTeam, [ $this->otherTeam ] );

        $this->assertFalse( AuthorizationService::canViewTournament( $coach, $tournament ) );
        $this->assertFalse( AuthorizationService::canEditTournament( $coach, $tournament ) );
    }

    public function test_one_squad_player_of_theirs_is_enough_to_reach_another_teams_tournament(): void {
        $coach      = $this->makeCoach( $this->myTeam );
        $tournament = $this->makeTournament( $this->otherTeam, [ $this->myTeam ] );

        $this->assertTrue(
            AuthorizationService::canViewTournament( $coach, $tournament ),
            'their player is in the squad, so part of the day is theirs'
        );
    }

    public function test_the_assistant_coach_and_the_team_manager_get_the_same_team_control(): void {
        $assistant  = $this->makeCoach( $this->myTeam, false );
        $tournament = $this->makeTournament( $this->myTeam );

        $this->assertTrue( AuthorizationService::canViewTournament( $assistant, $tournament ) );
        $this->assertTrue( AuthorizationService::canEditTournament( $assistant, $tournament ) );
    }

    // ── delete: every participating team, or nothing ───────────────────

    public function test_a_coach_deletes_a_tournament_entirely_within_their_scope(): void {
        $coach      = $this->makeCoach( $this->myTeam );
        $tournament = $this->makeTournament( $this->myTeam, [ $this->myTeam ] );

        $this->assertTrue( AuthorizationService::canDeleteTournament( $coach, $tournament ) );
    }

    public function test_a_coach_cannot_delete_a_tournament_that_reaches_past_their_teams(): void {
        $coach      = $this->makeCoach( $this->myTeam );
        $tournament = $this->makeTournament( $this->myTeam, [ $this->myTeam, $this->otherTeam ] );

        $this->assertTrue(
            AuthorizationService::canEditTournament( $coach, $tournament ),
            'they may still plan their half of the day'
        );
        $this->assertFalse( AuthorizationService::canDeleteTournament( $coach, $tournament ) );
        $this->assertTrue( TournamentAccess::spansTeamsOutsideScope( $coach, $tournament ) );
    }

    public function test_the_refused_delete_says_why_rather_than_refusing_bare(): void {
        $coach      = $this->makeCoach( $this->myTeam );
        $tournament = $this->makeTournament( $this->myTeam, [ $this->myTeam, $this->otherTeam ] );

        wp_set_current_user( $coach );
        $gate = TournamentsRestController::deleteGate( $tournament );

        $this->assertInstanceOf( \WP_Error::class, $gate );
        $this->assertSame( 'tournament_spans_other_teams', $gate->get_error_code() );
        $this->assertSame( 403, (int) ( $gate->get_error_data()['status'] ?? 0 ) );
    }

    public function test_a_stranger_gets_the_plain_refusal_not_the_spanning_one(): void {
        $stranger   = self::factory()->user->create( [ 'role' => 'subscriber' ] );
        $tournament = $this->makeTournament( $this->myTeam, [ $this->myTeam, $this->otherTeam ] );

        wp_set_current_user( $stranger );
        $gate = TournamentsRestController::deleteGate( $tournament );

        $this->assertInstanceOf( \WP_Error::class, $gate );
        $this->assertSame( 'rest_forbidden', $gate->get_error_code() );
    }

    // ── the global personas are unaffected ─────────────────────────────

    public function test_head_of_development_reaches_every_tournament_including_deleting_it(): void {
        $hod        = $this->makeHeadOfDevelopment();
        $tournament = $this->makeTournament( $this->myTeam, [ $this->myTeam, $this->otherTeam ] );

        $this->assertTrue( AuthorizationService::canViewTournament( $hod, $tournament ) );
        $this->assertTrue( AuthorizationService::canEditTournament( $hod, $tournament ) );
        $this->assertTrue(
            AuthorizationService::canDeleteTournament( $hod, $tournament ),
            'the all-teams rule narrows a team-scoped actor, not a global one'
        );
        $this->assertFalse( TournamentAccess::spansTeamsOutsideScope( $hod, $tournament ) );
    }

    public function test_an_administrator_is_unchanged(): void {
        $admin      = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $tournament = $this->makeTournament( $this->otherTeam, [ $this->otherTeam ] );

        $this->assertTrue( AuthorizationService::canViewTournament( $admin, $tournament ) );
        $this->assertTrue( AuthorizationService::canDeleteTournament( $admin, $tournament ) );
    }

    // ── creating is decided on the anchor team ─────────────────────────

    public function test_a_coach_creates_only_on_a_team_they_hold(): void {
        $coach = $this->makeCoach( $this->myTeam );

        $this->assertTrue( TournamentAccess::canCreateForTeam( $coach, $this->myTeam ) );
        $this->assertFalse( TournamentAccess::canCreateForTeam( $coach, $this->otherTeam ) );
    }

    // ── the list narrows in SQL ────────────────────────────────────────

    public function test_the_list_returns_only_the_callers_own_teams_tournaments(): void {
        $coach = $this->makeCoach( $this->myTeam );
        $mine  = $this->makeTournament( $this->myTeam, [ $this->myTeam ] );
        $this->makeTournament( $this->otherTeam, [ $this->otherTeam ] );

        wp_set_current_user( $coach );
        $request = new \WP_REST_Request( 'GET', '/talenttrack/v1/tournaments' );
        $data    = TournamentsRestController::list_tournaments( $request )->get_data()['data'];

        $ids = array_map( static fn ( $row ): int => (int) $row['id'], (array) $data['rows'] );
        $this->assertSame( [ $mine ], $ids );
        $this->assertSame( 1, (int) $data['total'], 'the count has to agree with the rows' );
    }

    public function test_head_of_development_lists_every_tournament(): void {
        $hod = $this->makeHeadOfDevelopment();
        $this->makeTournament( $this->myTeam );
        $this->makeTournament( $this->otherTeam );

        wp_set_current_user( $hod );
        $request = new \WP_REST_Request( 'GET', '/talenttrack/v1/tournaments' );
        $data    = TournamentsRestController::list_tournaments( $request )->get_data()['data'];

        $this->assertSame( 2, (int) $data['total'] );
    }

    // ── the top-up, for installs that already ran the seed ─────────────

    public function test_the_migration_gives_an_existing_install_the_new_rows(): void {
        global $wpdb;
        $table = "{$wpdb->prefix}tt_authorization_matrix";
        foreach ( [ 'assistant_coach', 'head_coach', 'team_manager', 'head_of_development' ] as $persona ) {
            $wpdb->delete( $table, [ 'persona' => $persona, 'entity' => 'tournaments' ] );
        }

        $migration = require dirname( __DIR__, 2 ) . '/database/migrations/0278_authorization_seed_topup_tournaments_personas.php';
        $migration->up();
        // Twice, because a re-run must add nothing.
        $migration->up();

        $rows = $wpdb->get_results(
            "SELECT persona, activity, scope_kind FROM {$table}
              WHERE entity = 'tournaments'
           ORDER BY persona, activity",
            ARRAY_A
        );

        $seen = [];
        foreach ( $rows as $row ) {
            $seen[ $row['persona'] . '|' . $row['scope_kind'] ][] = $row['activity'];
        }
        foreach ( $seen as $key => $activities ) {
            sort( $activities );
            $seen[ $key ] = $activities;
        }
        ksort( $seen );

        $rcd = [ 'change', 'create_delete', 'read' ];
        $this->assertSame( [
            'academy_admin|global'       => $rcd,
            'assistant_coach|team'       => $rcd,
            'head_coach|team'            => $rcd,
            'head_of_development|global' => $rcd,
            'team_manager|team'          => $rcd,
        ], $seen );
    }
}
