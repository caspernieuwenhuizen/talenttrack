<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\REST\TournamentsRestController;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Tournaments\TournamentAccess;
use TT\Modules\Tournaments\Wizard\SquadStep;

/**
 * A player joins a tournament squad only when the planner may create
 * tournaments for that player's team: an academy-wide planner adds anyone,
 * a coach of two teams mixes those two. The wizard, the create route and
 * both squad routes ask that one question, through the REST server so the
 * permission callbacks run too. A tournament's team is fixed once it exists,
 * except for an academy-wide planner, and only onto a team the club has.
 */
final class TournamentSquadRuleTest extends WP_UnitTestCase {

    private int $team_a = 0;
    private int $team_b = 0;
    private int $team_c = 0;
    private int $player_a = 0;
    private int $player_b = 0;
    private int $player_c = 0;
    private int $coach = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb, $wp_rest_server;
        ( new RolesService() )->installRoles();
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        $wp_rest_server = new WP_REST_Server();
        TournamentsRestController::init();
        do_action( 'rest_api_init' );

        $p = $wpdb->prefix;
        $this->team_a = $this->team( 'Squadrule A' );
        $this->team_b = $this->team( 'Squadrule B' );
        $this->team_c = $this->team( 'Squadrule C' );
        $this->player_a = $this->player( $this->team_a, 'Squadrulea' );
        $this->player_b = $this->player( $this->team_b, 'Squadruleb' );
        $this->player_c = $this->player( $this->team_c, 'Squadrulec' );

        // A head coach of teams A and B, built the way production builds one.
        $role_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}tt_roles WHERE role_key = %s AND club_id = %d LIMIT 1", 'head_coach', 1
        ) );
        $this->assertGreaterThan( 0, $role_id, 'fixture: the head coach role is seeded' );
        $this->coach = self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $wpdb->insert( "{$p}tt_people", [
            'club_id' => 1, 'first_name' => 'Squadrule', 'last_name' => 'Coachx',
            'role_type' => 'head_coach', 'wp_user_id' => $this->coach, 'status' => 'active',
        ] );
        $person = (int) $wpdb->insert_id;
        foreach ( [ $this->team_a, $this->team_b ] as $team ) {
            $wpdb->insert( "{$p}tt_team_people", [
                'club_id' => 1, 'team_id' => $team, 'person_id' => $person,
                'role_in_team' => 'head_coach', 'is_head_coach' => 1,
            ] );
            $wpdb->insert( "{$p}tt_user_role_scopes", [
                'club_id' => 1, 'person_id' => $person, 'role_id' => $role_id, 'scope_type' => 'team', 'scope_id' => $team,
            ] );
        }
        AuthorizationService::flushCache();
        wp_set_current_user( $this->coach );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_the_predicate(): void {
        $this->assertTrue( TournamentAccess::canAddToSquad( $this->coach, $this->player_a ) );
        $this->assertTrue( TournamentAccess::canAddToSquad( $this->coach, $this->player_b ), 'the second team the coach plans for' );
        $this->assertFalse( TournamentAccess::canAddToSquad( $this->coach, $this->player_c ) );
        $hod = $this->headOfDevelopment();
        $this->assertTrue( TournamentAccess::canAddToSquad( $hod, $this->player_c ), 'an academy-wide planner adds anyone' );
        $this->assertFalse( TournamentAccess::canAddToSquad( $hod, $this->archivedPlayer() ), 'but not an archived player' );
        $this->assertFalse( TournamentAccess::canAddToSquad( $hod, $this->otherClubPlayer() ), 'nor another club\'s' );
    }

    public function test_the_wizard_mixes_the_coachs_teams_and_drops_another(): void {
        $html = $this->render( new SquadStep(), [ 'team_id' => $this->team_a ] );
        $this->assertStringContainsString( 'Squadruleb', $html, 'the second team\'s players are offered' );
        $this->assertStringNotContainsString( 'Squadrulec', $html );

        $result = ( new SquadStep() )->validate(
            [ 'squad_in' => [ (string) $this->player_a, (string) $this->player_b, (string) $this->player_c ] ],
            [ 'team_id' => $this->team_a ]
        );
        $this->assertIsArray( $result );
        $this->assertEqualsCanonicalizing( [ $this->player_a, $this->player_b ], array_column( $result['squad'], 'player_id' ) );
    }

    public function test_create_refuses_a_squad_player_the_coach_may_not_add(): void {
        $refused = $this->dispatch( 'POST', '/tournaments', $this->body( $this->team_a, [ $this->player_a, $this->player_c ] ) );
        $this->assertSame( 403, $refused->get_status() );
        $this->assertSame( 0, $this->tournamentCount() );

        $served = $this->dispatch( 'POST', '/tournaments', $this->body( $this->team_a, [ $this->player_a, $this->player_b ] ) );
        $this->assertSame( 200, $served->get_status(), 'a squad from the coach\'s two teams is created' );
        $this->assertSame( 1, $this->tournamentCount() );
    }

    public function test_create_refuses_an_archived_or_other_club_player_even_for_an_academy_wide_planner(): void {
        wp_set_current_user( $this->headOfDevelopment() );
        foreach ( [ $this->archivedPlayer(), $this->otherClubPlayer() ] as $player ) {
            $this->assertSame( 403, $this->dispatch( 'POST', '/tournaments', $this->body( $this->team_a, [ $player ] ) )->get_status() );
        }
        $this->assertSame( 0, $this->tournamentCount() );
        $this->assertSame( 200, $this->dispatch( 'POST', '/tournaments', $this->body( $this->team_c, [ $this->player_c ] ) )->get_status(), 'precondition: any current player of any team is fine' );
    }

    public function test_create_refuses_an_archived_or_other_club_team_even_for_an_academy_wide_planner(): void {
        wp_set_current_user( $this->headOfDevelopment() );
        foreach ( [ $this->archivedTeam(), $this->otherClubTeam(), 999999 ] as $team ) {
            $this->assertSame( 403, $this->dispatch( 'POST', '/tournaments', $this->body( $team, [] ) )->get_status(), 'team ' . $team );
        }
        $this->assertSame( 0, $this->tournamentCount() );
    }

    public function test_replacing_the_squad_refuses_a_new_player_from_another_team(): void {
        $id = $this->tournament( [ $this->player_a ] );

        $refused = $this->dispatch( 'PATCH', "/tournaments/{$id}/squad", [
            'squad' => [ [ 'player_id' => $this->player_a ], [ 'player_id' => $this->player_c ] ],
        ] );
        $this->assertSame( 403, $refused->get_status() );
        $this->assertSame( [ $this->player_a ], $this->squad( $id ), 'the refused replace wiped nothing' );

        $served = $this->dispatch( 'PATCH', "/tournaments/{$id}/squad", [
            'squad' => [ [ 'player_id' => $this->player_a ], [ 'player_id' => $this->player_b ] ],
        ] );
        $this->assertSame( 200, $served->get_status() );
        $this->assertSame( [ $this->player_a, $this->player_b ], $this->squad( $id ) );
    }

    public function test_adding_one_member_asks_and_editing_one_already_in_does_not(): void {
        $id = $this->tournament( [ $this->player_a ] );
        $refused = $this->dispatch( 'PATCH', "/tournaments/{$id}/squad/{$this->player_c}", [] );
        $this->assertSame( 403, $refused->get_status() );
        $this->assertSame( [ $this->player_a ], $this->squad( $id ) );

        // A member an academy-wide planner added stays editable for the coach.
        $this->addMember( $id, $this->player_c );
        $served = $this->dispatch( 'PATCH', "/tournaments/{$id}/squad/{$this->player_c}", [ 'notes' => 'Keeps goal' ] );
        $this->assertSame( 200, $served->get_status() );
    }

    public function test_the_team_of_a_tournament_is_fixed_for_a_coach(): void {
        $id = $this->tournament( [ $this->player_a ] );

        $refused = $this->dispatch( 'PUT', "/tournaments/{$id}", [ 'name' => 'Moved', 'start_date' => '2031-05-01', 'team_id' => $this->team_b ] );
        $this->assertSame( 403, $refused->get_status() );
        $this->assertSame( "A tournament's team can't be changed after it is created.", (string) ( $refused->get_data()['errors'][0]['message'] ?? '' ) );
        $this->assertSame( $this->team_a, $this->teamOf( $id ) );

        $kept = $this->dispatch( 'PUT', "/tournaments/{$id}", [ 'name' => 'Renamed', 'start_date' => '2031-05-01' ] );
        $this->assertSame( 200, $kept->get_status(), 'an edit without a team is fine' );
        $this->assertSame( $this->team_a, $this->teamOf( $id ), 'and keeps the team' );
    }

    public function test_an_academy_wide_planner_moves_it_only_onto_a_team_the_club_has(): void {
        $id = $this->tournament( [ $this->player_a ] );
        wp_set_current_user( $this->headOfDevelopment() );

        foreach ( [ $this->archivedTeam(), $this->otherClubTeam() ] as $team ) {
            $this->assertSame( 422, $this->dispatch( 'PUT', "/tournaments/{$id}", [ 'name' => 'Moved', 'start_date' => '2031-05-01', 'team_id' => $team ] )->get_status() );
        }
        $this->assertSame( $this->team_a, $this->teamOf( $id ) );

        $this->assertSame( 200, $this->dispatch( 'PUT', "/tournaments/{$id}", [ 'name' => 'Moved', 'start_date' => '2031-05-01', 'team_id' => $this->team_c ] )->get_status() );
        $this->assertSame( $this->team_c, $this->teamOf( $id ) );
    }

    // ---- helpers -------------------------------------------------------

    /** @param array<string, mixed> $body */
    private function dispatch( string $method, string $path, array $body ): \WP_REST_Response {
        $request = new WP_REST_Request( $method, '/talenttrack/v1' . $path );
        $request->set_header( 'Content-Type', 'application/json' );
        $request->set_body( (string) wp_json_encode( $body ) );
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        return rest_get_server()->dispatch( $request );
    }

    /**
     * @param list<int> $player_ids
     * @return array<string, mixed>
     */
    private function body( int $team_id, array $player_ids ): array {
        return [
            'name' => 'Squadrule Cup', 'team_id' => $team_id, 'start_date' => '2031-05-01',
            'squad' => array_map( static fn( int $id ): array => [ 'player_id' => $id ], $player_ids ),
        ];
    }

    /** @param list<int> $player_ids */
    private function tournament( array $player_ids ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_tournaments", [
            'uuid' => wp_generate_uuid4(), 'club_id' => 1, 'name' => 'Squadrule Fixture',
            'start_date' => '2031-05-01', 'team_id' => $this->team_a, 'created_by' => $this->coach,
        ] );
        $id = (int) $wpdb->insert_id;
        foreach ( $player_ids as $pid ) $this->addMember( $id, $pid );
        return $id;
    }

    private function addMember( int $tournament_id, int $player_id ): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_tournament_squad", [
            'tournament_id' => $tournament_id, 'player_id' => $player_id, 'club_id' => 1, 'eligible_positions' => '[]',
        ] );
        $this->assertContains( $player_id, $this->squad( $tournament_id ), 'fixture: the member was added' );
    }

    /** @return list<int> */
    private function squad( int $tournament_id ): array {
        global $wpdb;
        return array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
            "SELECT player_id FROM {$wpdb->prefix}tt_tournament_squad WHERE tournament_id = %d ORDER BY player_id", $tournament_id
        ) ) );
    }

    private function teamOf( int $tournament_id ): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare( "SELECT team_id FROM {$wpdb->prefix}tt_tournaments WHERE id = %d", $tournament_id ) );
    }

    private function tournamentCount(): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}tt_tournaments WHERE name = %s", 'Squadrule Cup' ) );
    }

    /** A head of development: `tournaments` create at global scope, from the seed. */
    private function headOfDevelopment(): int {
        $id = self::factory()->user->create( [ 'role' => 'tt_head_dev' ] );
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        $this->assertTrue( TournamentAccess::hasGlobal( $id, \TT\Modules\Authorization\MatrixGate::CREATE_DELETE ), 'precondition: the global tournaments grant' );
        return $id;
    }

    private function team( string $name, int $club = 1, bool $archived = false ): int {
        global $wpdb;
        $row = [ 'club_id' => $club, 'name' => $name, 'age_group' => 'U12' ];
        if ( $archived ) $row['archived_at'] = current_time( 'mysql' );
        $wpdb->insert( "{$wpdb->prefix}tt_teams", $row );
        return (int) $wpdb->insert_id;
    }

    private function archivedTeam(): int { return $this->team( 'Squadrule Archived', 1, true ); }

    private function otherClubTeam(): int { return $this->team( 'Squadrule Elsewhere', 2 ); }

    private function player( int $team_id, string $first, int $club = 1, bool $archived = false ): int {
        global $wpdb;
        $row = [
            'club_id' => $club, 'team_id' => $team_id, 'first_name' => $first, 'last_name' => 'Kidx',
            'status' => 'active', 'wp_user_id' => self::factory()->user->create( [ 'role' => 'tt_player' ] ),
        ];
        if ( $archived ) $row['archived_at'] = current_time( 'mysql' );
        $wpdb->insert( "{$wpdb->prefix}tt_players", $row );
        $id = (int) $wpdb->insert_id;
        $this->assertGreaterThan( 0, $id, 'fixture: the player row was written' );
        return $id;
    }

    private function archivedPlayer(): int { return $this->player( $this->team_c, 'Squadrulearchived', 1, true ); }

    private function otherClubPlayer(): int { return $this->player( $this->otherClubTeam(), 'Squadruleelsewhere', 2 ); }

    private function render( object $step, array $state ): string {
        ob_start();
        $step->render( $state );
        return (string) ob_get_clean();
    }
}
