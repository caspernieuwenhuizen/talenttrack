<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Tournaments\Wizard\BasicsStep;
use TT\Modules\Tournaments\Wizard\ReviewStep;
use TT\Modules\Tournaments\Wizard\SquadStep;

/**
 * The tournament wizard plans a squad for one team. It offers, accepts and
 * saves only a team the planner may write, and a squad of that team's own
 * players: a coach of one team can neither name another team's players nor
 * create a tournament for it.
 */
final class TournamentWizardScopeTest extends WP_UnitTestCase {

    private int $own_team     = 0;
    private int $other_team   = 0;
    private int $own_player   = 0;
    private int $other_player = 0;
    private int $coach        = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        ( new RolesService() )->installRoles();
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();

        $p = $wpdb->prefix;
        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => 1, 'name' => 'Tourscope Own U13', 'age_group' => 'U13' ] );
        $this->own_team = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => 1, 'name' => 'Tourscope Other U13', 'age_group' => 'U13' ] );
        $this->other_team = (int) $wpdb->insert_id;

        $this->own_player   = $this->player( $this->own_team, 'Tourscopeown' );
        $this->other_player = $this->player( $this->other_team, 'Tourscopeother' );

        $this->coach = self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $wpdb->insert( "{$p}tt_people", [
            'club_id' => 1, 'first_name' => 'Tourscope', 'last_name' => 'Coachx',
            'role_type' => 'head_coach', 'wp_user_id' => $this->coach, 'status' => 'active',
        ] );
        $person = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_team_people", [
            'club_id' => 1, 'team_id' => $this->own_team, 'person_id' => $person,
            'role_in_team' => 'head_coach', 'is_head_coach' => 1,
        ] );
        $wpdb->insert( "{$p}tt_user_role_scopes", [
            'person_id' => $person, 'role_id' => 1, 'scope_type' => 'team', 'scope_id' => $this->own_team,
        ] );
        AuthorizationService::flushCache();
        wp_set_current_user( $this->coach );
    }

    public function tear_down(): void {
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_the_team_picker_offers_only_the_coachs_teams(): void {
        $html = $this->render( new BasicsStep(), [] );
        $this->assertStringContainsString( 'Tourscope Own U13', $html, 'precondition: the own team is offered' );
        $this->assertStringNotContainsString( 'Tourscope Other U13', $html );
    }

    public function test_the_basics_refuse_another_teams_id(): void {
        $post = [ 'tournament_name' => 'Cup', 'start_date' => '2031-05-01' ];
        $this->assertIsArray( ( new BasicsStep() )->validate( $post + [ 'team_id' => $this->own_team ], [] ), 'precondition: the own team is accepted' );
        $this->assertInstanceOf( \WP_Error::class, ( new BasicsStep() )->validate( $post + [ 'team_id' => $this->other_team ], [] ) );
    }

    public function test_the_squad_step_does_not_list_another_teams_players(): void {
        $this->assertStringContainsString( 'Tourscopeown', $this->render( new SquadStep(), [ 'team_id' => $this->own_team ] ), 'precondition: the own squad is listed' );
        $this->assertStringNotContainsString( 'Tourscopeother', $this->render( new SquadStep(), [ 'team_id' => $this->other_team ] ) );
    }

    public function test_the_squad_keeps_only_the_teams_own_players(): void {
        $result = ( new SquadStep() )->validate(
            [ 'squad_in' => [ (string) $this->own_player, (string) $this->other_player ] ],
            [ 'team_id' => $this->own_team ]
        );
        $this->assertIsArray( $result );
        $this->assertSame( [ $this->own_player ], array_column( $result['squad'], 'player_id' ) );
    }

    public function test_no_tournament_is_created_for_another_team(): void {
        $result = ( new ReviewStep() )->submit( $this->state( $this->other_team, [ $this->other_player ] ) );
        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 0, $this->tournamentsFor( $this->other_team ) );
    }

    public function test_the_saved_squad_holds_only_the_teams_own_players(): void {
        $result = ( new ReviewStep() )->submit( $this->state( $this->own_team, [ $this->own_player, $this->other_player ] ) );
        $this->assertIsArray( $result, 'precondition: the own team\'s tournament is created' );
        $this->assertSame( 1, $this->tournamentsFor( $this->own_team ) );
        $this->assertSame( [ $this->own_player ], $this->squadOf( $this->own_team ) );
    }

    /** The question is the tournaments grant, not the activities one, in both directions. */
    public function test_the_wizard_asks_the_tournaments_grant(): void {
        $post = [ 'tournament_name' => 'Cup', 'start_date' => '2031-05-01', 'team_id' => $this->own_team ];

        $this->dropGrant( 'activities', [ 'change', 'create_delete' ] );
        $this->assertFalse( \TT\Modules\Activities\ActivityAccess::canWriteTeam( $this->coach, $this->own_team ), 'precondition: no activity write' );
        $this->assertIsArray( ( new BasicsStep() )->validate( $post, [] ), 'tournament create without activity write is accepted' );

        $this->dropGrant( 'tournaments', [ 'create_delete' ] );
        $this->assertInstanceOf( \WP_Error::class, ( new BasicsStep() )->validate( $post, [] ) );
    }

    public function test_activity_write_without_the_tournaments_grant_is_refused(): void {
        $this->dropGrant( 'tournaments', [ 'create_delete' ] );
        $this->assertTrue( \TT\Modules\Activities\ActivityAccess::canWriteTeam( $this->coach, $this->own_team ), 'precondition: activity write held' );
        $this->assertInstanceOf( \WP_Error::class, ( new BasicsStep() )->validate(
            [ 'tournament_name' => 'Cup', 'start_date' => '2031-05-01', 'team_id' => $this->own_team ], []
        ) );
        $this->assertInstanceOf( \WP_Error::class, ( new ReviewStep() )->submit( $this->state( $this->own_team, [ $this->own_player ] ) ) );
    }

    public function test_the_squad_step_refuses_an_out_of_scope_team_in_its_state(): void {
        $this->assertInstanceOf( \WP_Error::class, ( new SquadStep() )->validate(
            [ 'squad_in' => [ (string) $this->other_player ] ],
            [ 'team_id' => $this->other_team ]
        ) );
    }

    public function test_a_squad_of_only_other_teams_players_is_refused(): void {
        $result = ( new SquadStep() )->validate( [ 'squad_in' => [ (string) $this->other_player ] ], [ 'team_id' => $this->own_team ] );
        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'squad_empty', $result->get_error_code() );
    }

    /** A planner who neither writes the team's activities nor reads injuries gets no markers. */
    public function test_a_planner_without_plan_or_injury_rights_gets_no_markers(): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_player_injuries", [
            'club_id' => 1, 'player_id' => $this->own_player,
            'started_on' => gmdate( 'Y-m-d', (int) strtotime( '-7 days' ) ), 'notes' => 'ankle',
        ] );
        $state = [ 'team_id' => $this->own_team, 'start_date' => gmdate( 'Y-m-d' ) ];
        $this->assertStringContainsString( 'ttw-unavailable', $this->render( new SquadStep(), $state ), 'precondition: the planner sees the marker' );

        $this->dropGrant( 'activities', [ 'change', 'create_delete' ] );
        $this->dropGrant( 'player_injuries', [ 'read', 'change', 'create_delete' ] );
        $html = $this->render( new SquadStep(), $state );
        $this->assertStringContainsString( 'Tourscopeown', $html, 'precondition: the squad is still listed' );
        $this->assertStringNotContainsString( 'ttw-unavailable', $html );
    }

    public function test_the_basics_refuse_an_archived_missing_or_other_club_team(): void {
        global $wpdb;
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        AuthorizationService::flushCache();
        $p = $wpdb->prefix;
        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => 1, 'name' => 'Tourscope Archived', 'archived_at' => current_time( 'mysql' ) ] );
        $archived = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => 2, 'name' => 'Tourscope Elsewhere' ] );
        $elsewhere = (int) $wpdb->insert_id;

        $post = [ 'tournament_name' => 'Cup', 'start_date' => '2031-05-01' ];
        $this->assertIsArray( ( new BasicsStep() )->validate( $post + [ 'team_id' => $this->own_team ], [] ), 'precondition: an active team is accepted' );
        foreach ( [ $archived, $elsewhere, 999999 ] as $team_id ) {
            $this->assertInstanceOf( \WP_Error::class, ( new BasicsStep() )->validate( $post + [ 'team_id' => $team_id ], [] ), 'team ' . $team_id );
        }
    }

    public function test_the_review_names_no_other_teams_squad(): void {
        $html = $this->render( new ReviewStep(), $this->state( $this->other_team, [ $this->other_player ] ) );
        $this->assertStringNotContainsString( 'Tourscopeother', $html );
        $this->assertStringNotContainsString( 'Tourscope Other U13', $html );

        $own = $this->render( new ReviewStep(), $this->state( $this->own_team, [ $this->own_player, $this->other_player ] ) );
        $this->assertStringContainsString( 'Tourscopeown', $own, 'precondition: the own squad is named' );
        $this->assertStringNotContainsString( 'Tourscopeother', $own );
    }

    /** @param list<string> $activities */
    private function dropGrant( string $entity, array $activities ): void {
        global $wpdb;
        foreach ( $activities as $activity ) {
            $wpdb->delete( "{$wpdb->prefix}tt_authorization_matrix", [ 'persona' => 'head_coach', 'entity' => $entity, 'activity' => $activity ] );
        }
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
    }

    public function test_the_academy_admin_still_plans_for_any_team(): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        AuthorizationService::flushCache();
        $this->assertStringContainsString( 'Tourscope Other U13', $this->render( new BasicsStep(), [] ) );
        $this->assertIsArray( ( new ReviewStep() )->submit( $this->state( $this->other_team, [ $this->other_player ] ) ) );
    }

    /**
     * @param list<int> $player_ids
     * @return array<string, mixed>
     */
    private function state( int $team_id, array $player_ids ): array {
        return [
            'name' => 'Tourscope Cup', 'team_id' => $team_id, 'start_date' => '2031-05-01', 'end_date' => '',
            'default_formation' => '',
            'squad' => array_map( static fn( int $id ): array => [ 'player_id' => $id, 'eligible_positions' => [] ], $player_ids ),
            'matches' => [ [
                'label' => '', 'opponent_name' => 'Opponent', 'opponent_level' => '', 'formation' => '',
                'duration_min' => 20, 'substitution_windows' => [],
            ] ],
        ];
    }

    private function render( object $step, array $state ): string {
        ob_start();
        $step->render( $state );
        return (string) ob_get_clean();
    }

    private function tournamentsFor( int $team_id ): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}tt_tournaments WHERE team_id = %d", $team_id ) );
    }

    /** @return list<int> */
    private function squadOf( int $team_id ): array {
        global $wpdb;
        $p = $wpdb->prefix;
        return array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
            "SELECT s.player_id FROM {$p}tt_tournament_squad s INNER JOIN {$p}tt_tournaments t ON t.id = s.tournament_id
              WHERE t.team_id = %d ORDER BY s.player_id",
            $team_id
        ) ) );
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
