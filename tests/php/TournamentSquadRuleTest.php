<?php
namespace TT\Tests\Php;

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
 * both squad routes ask that one question. A tournament's team is fixed
 * once it exists, except for an academy-wide planner.
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
        global $wpdb;
        ( new RolesService() )->installRoles();
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        TournamentsRestController::init();
        do_action( 'rest_api_init' );

        $p = $wpdb->prefix;
        foreach ( [ 'team_a' => 'Squadrule A', 'team_b' => 'Squadrule B', 'team_c' => 'Squadrule C' ] as $prop => $name ) {
            $wpdb->insert( "{$p}tt_teams", [ 'club_id' => 1, 'name' => $name, 'age_group' => 'U12' ] );
            $this->{$prop} = (int) $wpdb->insert_id;
        }
        $this->player_a = $this->player( $this->team_a, 'Squadrulea' );
        $this->player_b = $this->player( $this->team_b, 'Squadruleb' );
        $this->player_c = $this->player( $this->team_c, 'Squadrulec' );

        // A head coach of teams A and B.
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
                'person_id' => $person, 'role_id' => 1, 'scope_type' => 'team', 'scope_id' => $team,
            ] );
        }
        AuthorizationService::flushCache();
        wp_set_current_user( $this->coach );
    }

    public function tear_down(): void {
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_the_predicate(): void {
        $this->assertTrue( TournamentAccess::canAddToSquad( $this->coach, $this->player_a ) );
        $this->assertTrue( TournamentAccess::canAddToSquad( $this->coach, $this->player_b ), 'the second team the coach plans for' );
        $this->assertFalse( TournamentAccess::canAddToSquad( $this->coach, $this->player_c ) );
        $this->assertTrue( TournamentAccess::canAddToSquad( $this->admin(), $this->player_c ), 'an academy-wide planner adds anyone' );
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
        $refused = TournamentsRestController::create_tournament( $this->request( 'POST', '/tournaments', $this->body( [ $this->player_a, $this->player_c ] ) ) );
        $this->assertSame( 403, $refused->get_status() );
        $this->assertSame( 0, $this->tournamentCount() );

        $served = TournamentsRestController::create_tournament( $this->request( 'POST', '/tournaments', $this->body( [ $this->player_a, $this->player_b ] ) ) );
        $this->assertSame( 200, $served->get_status(), 'a squad from the coach\'s two teams is created' );
        $this->assertSame( 1, $this->tournamentCount() );
    }

    public function test_replacing_the_squad_refuses_a_new_player_from_another_team(): void {
        $id = $this->tournament( [ $this->player_a ] );

        $refused = TournamentsRestController::replace_squad( $this->request( 'PATCH', "/tournaments/{$id}/squad", [
            'id' => $id, 'squad' => [ [ 'player_id' => $this->player_a ], [ 'player_id' => $this->player_c ] ],
        ] ) );
        $this->assertSame( 403, $refused->get_status() );
        $this->assertSame( [ $this->player_a ], $this->squad( $id ), 'the refused replace wiped nothing' );

        $served = TournamentsRestController::replace_squad( $this->request( 'PATCH', "/tournaments/{$id}/squad", [
            'id' => $id, 'squad' => [ [ 'player_id' => $this->player_a ], [ 'player_id' => $this->player_b ] ],
        ] ) );
        $this->assertSame( 200, $served->get_status() );
        $this->assertSame( [ $this->player_a, $this->player_b ], $this->squad( $id ) );
    }

    public function test_adding_one_member_asks_and_editing_one_already_in_does_not(): void {
        $id = $this->tournament( [ $this->player_a ] );
        $refused = TournamentsRestController::update_squad_member( $this->request( 'PATCH', "/tournaments/{$id}/squad/{$this->player_c}", [
            'id' => $id, 'player_id' => $this->player_c,
        ] ) );
        $this->assertSame( 403, $refused->get_status() );
        $this->assertSame( [ $this->player_a ], $this->squad( $id ) );

        // A member an academy-wide planner added stays editable for the coach.
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_tournament_squad", [ 'tournament_id' => $id, 'player_id' => $this->player_c, 'club_id' => 1, 'eligible_positions' => '[]' ] );
        $this->assertContains( $this->player_c, $this->squad( $id ), 'fixture: the member was added' );
        $served = TournamentsRestController::update_squad_member( $this->request( 'PATCH', "/tournaments/{$id}/squad/{$this->player_c}", [
            'id' => $id, 'player_id' => $this->player_c, 'notes' => 'Keeps goal',
        ] ) );
        $this->assertSame( 200, $served->get_status() );
    }

    public function test_the_team_of_a_tournament_is_fixed_for_a_coach(): void {
        $id = $this->tournament( [ $this->player_a ] );

        $refused = TournamentsRestController::update_tournament( $this->request( 'PUT', "/tournaments/{$id}", [
            'id' => $id, 'name' => 'Moved', 'start_date' => '2031-05-01', 'team_id' => $this->team_b,
        ] ) );
        $this->assertSame( 403, $refused->get_status() );
        $this->assertSame( $this->team_a, $this->teamOf( $id ) );

        $kept = TournamentsRestController::update_tournament( $this->request( 'PUT', "/tournaments/{$id}", [
            'id' => $id, 'name' => 'Renamed', 'start_date' => '2031-05-01',
        ] ) );
        $this->assertSame( 200, $kept->get_status(), 'an edit without a team is fine' );
        $this->assertSame( $this->team_a, $this->teamOf( $id ), 'and keeps the team' );

        wp_set_current_user( $this->admin() );
        $moved = TournamentsRestController::update_tournament( $this->request( 'PUT', "/tournaments/{$id}", [
            'id' => $id, 'name' => 'Moved', 'start_date' => '2031-05-01', 'team_id' => $this->team_c,
        ] ) );
        $this->assertSame( 200, $moved->get_status(), 'an academy-wide planner moves it' );
        $this->assertSame( $this->team_c, $this->teamOf( $id ) );
    }

    // ---- helpers -------------------------------------------------------

    /** @param array<string, mixed> $body */
    private function request( string $method, string $path, array $body ): \WP_REST_Request {
        $r = new \WP_REST_Request( $method, '/talenttrack/v1' . $path );
        $r->set_header( 'Content-Type', 'application/json' );
        $r->set_body( (string) wp_json_encode( $body ) );
        foreach ( [ 'id', 'player_id' ] as $url_param ) {
            if ( isset( $body[ $url_param ] ) && ! is_array( $body[ $url_param ] ) ) $r->set_url_params( array_merge( (array) $r->get_url_params(), [ $url_param => $body[ $url_param ] ] ) );
        }
        return $r;
    }

    /**
     * @param list<int> $player_ids
     * @return array<string, mixed>
     */
    private function body( array $player_ids ): array {
        return [
            'name' => 'Squadrule Cup', 'team_id' => $this->team_a, 'start_date' => '2031-05-01',
            'squad' => array_map( static fn( int $id ): array => [ 'player_id' => $id ], $player_ids ),
        ];
    }

    /** @param list<int> $player_ids */
    private function tournament( array $player_ids ): int {
        global $wpdb;
        $p = $wpdb->prefix;
        $wpdb->insert( "{$p}tt_tournaments", [
            'uuid' => wp_generate_uuid4(), 'club_id' => 1, 'name' => 'Squadrule Cup',
            'start_date' => '2031-05-01', 'team_id' => $this->team_a, 'created_by' => $this->coach,
        ] );
        $id = (int) $wpdb->insert_id;
        foreach ( $player_ids as $pid ) {
            $wpdb->insert( "{$p}tt_tournament_squad", [ 'tournament_id' => $id, 'player_id' => $pid, 'club_id' => 1, 'eligible_positions' => '[]' ] );
        }
        return $id;
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

    private function admin(): int {
        $id = self::factory()->user->create( [ 'role' => 'administrator' ] );
        AuthorizationService::flushCache();
        return $id;
    }

    private function render( object $step, array $state ): string {
        ob_start();
        $step->render( $state );
        return (string) ob_get_clean();
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
