<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Config\ConfigService;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Alerts\Definitions\PlayerWithoutTeamAlert;
use TT\Modules\Alerts\Definitions\TeamWithoutHeadCoachAlert;
use TT\Modules\Alerts\Domain\AlertContext;

/**
 * #2636 instalment 5 — the two data-quality definitions.
 *
 * These are the odd ones out: their audience comes from a capability rather
 * than from a relationship, because a player with no team has no head coach
 * and a team with no head coach has, by definition, no head coach. The
 * recipient set is therefore whoever holds the records cap, so these
 * assertions check that the seeded custodian is among the recipients rather
 * than counting them — the test database has its own administrator too.
 *
 * The negatives matter most here for a specific reason: a data-quality alert
 * that fires on placeholder rows (an empty team created for next season, a
 * player added five minutes ago) trains people to skim past the list, and
 * this list is the one that is meant to be skimmed quickly.
 */
final class AlertsDataQualityDefinitionsTest extends WP_UnitTestCase {

    /** @var string */
    private $p;

    /** @var int */
    private $club = 1;

    /** @var int */
    private $admin;

    /** @var ConfigService */
    private $config;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->p = $wpdb->prefix;

        // The custodian query reads capabilities off the roles, so the caps
        // have to be installed before a fresh administrator counts as one.
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();

        $this->admin  = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $this->config = new ConfigService();

        // DELETE, not TRUNCATE: TRUNCATE commits and would break the
        // transaction WP_UnitTestCase rolls back between tests.
        $wpdb->query( "DELETE FROM {$this->p}tt_players" );
        $wpdb->query( "DELETE FROM {$this->p}tt_teams" );
    }

    // -- dataquality.player_without_team ---------------------------------

    public function test_teamless_player_reaches_the_records_custodians(): void {
        $player = $this->insertPlayer( 0, $this->daysAgo( 30 ), 'active' );

        $out = ( new PlayerWithoutTeamAlert() )->evaluate( new AlertContext( $this->club ) );

        $this->assertNotEmpty( $out );
        $this->assertContains( $this->admin, array_map( static fn( $o ) => $o->recipientUserId, $out ) );
        $this->assertSame( 'player', $out[0]->subjectType );
        $this->assertSame( $player, $out[0]->subjectId );
        $this->assertSame( $player, $out[0]->playerId, 'a data-quality alert naming a player is still player data' );
        $this->assertNotSame( '', $out[0]->title() );
    }

    public function test_player_with_a_team_produces_nothing(): void {
        $team = $this->insertTeam( 'U12 alerts' );
        $this->insertPlayer( $team, $this->daysAgo( 30 ), 'active' );

        $this->assertSame( [], ( new PlayerWithoutTeamAlert() )->evaluate( new AlertContext( $this->club ) ) );
    }

    /**
     * Assigning the squad is usually the next step in the same sitting.
     * Alerting on a player added this morning is how the list becomes noise.
     */
    public function test_player_added_within_the_grace_period_produces_nothing(): void {
        $this->insertPlayer( 0, $this->daysAgo( 2 ), 'active' );

        $this->assertSame( [], ( new PlayerWithoutTeamAlert() )->evaluate( new AlertContext( $this->club ) ) );
    }

    public function test_released_teamless_player_produces_nothing(): void {
        $this->insertPlayer( 0, $this->daysAgo( 30 ), 'released' );

        $this->assertSame( [], ( new PlayerWithoutTeamAlert() )->evaluate( new AlertContext( $this->club ) ) );
    }

    public function test_archived_teamless_player_produces_nothing(): void {
        global $wpdb;
        $player = $this->insertPlayer( 0, $this->daysAgo( 30 ), 'active' );
        $wpdb->update( "{$this->p}tt_players", [ 'archived_at' => current_time( 'mysql' ) ], [ 'id' => $player ] );

        $this->assertSame( [], ( new PlayerWithoutTeamAlert() )->evaluate( new AlertContext( $this->club ) ) );
    }

    public function test_teamless_grace_period_comes_from_config_not_from_code(): void {
        $this->insertPlayer( 0, $this->daysAgo( 2 ), 'active' );

        $this->assertSame( [], ( new PlayerWithoutTeamAlert() )->evaluate( new AlertContext( $this->club ) ) );

        $this->config->set( PlayerWithoutTeamAlert::CONFIG_KEY_GRACE_DAYS, '1' );

        $this->assertNotEmpty( ( new PlayerWithoutTeamAlert() )->evaluate( new AlertContext( $this->club ) ) );
    }

    // -- dataquality.team_without_head_coach -----------------------------

    public function test_squad_without_a_head_coach_reaches_the_custodians(): void {
        $team = $this->insertTeam( 'U12 alerts' );
        $this->insertPlayer( $team, $this->daysAgo( 30 ), 'active' );

        $out = ( new TeamWithoutHeadCoachAlert() )->evaluate( new AlertContext( $this->club ) );

        $this->assertNotEmpty( $out );
        $this->assertContains( $this->admin, array_map( static fn( $o ) => $o->recipientUserId, $out ) );
        $this->assertSame( 'team', $out[0]->subjectType );
        $this->assertSame( $team, $out[0]->subjectId );
        $this->assertNull( $out[0]->playerId, 'a team is not a player' );
        $this->assertStringContainsString( 'U12 alerts', $out[0]->title() );
    }

    public function test_team_with_a_head_coach_produces_nothing(): void {
        $team = $this->insertTeam( 'U12 alerts' );
        $this->insertPlayer( $team, $this->daysAgo( 30 ), 'active' );
        $this->assignHeadCoach( $team, self::factory()->user->create(), null );

        $this->assertSame( [], ( new TeamWithoutHeadCoachAlert() )->evaluate( new AlertContext( $this->club ) ) );
    }

    /**
     * A coach who left in June is not this team's head coach in September.
     * `tt_team_people` assignments carry an end date and the live head-coach
     * queries honour it, so this one must too.
     */
    public function test_head_coach_whose_assignment_ended_does_not_count(): void {
        $team = $this->insertTeam( 'U12 alerts' );
        $this->insertPlayer( $team, $this->daysAgo( 30 ), 'active' );
        $this->assignHeadCoach( $team, self::factory()->user->create(), $this->daysAgo( 1 ) );

        $this->assertNotEmpty( ( new TeamWithoutHeadCoachAlert() )->evaluate( new AlertContext( $this->club ) ) );
    }

    /**
     * A team with no head coach and no players is a placeholder — a shell
     * for next season, or one whose squad has moved on.
     */
    public function test_team_with_no_players_produces_nothing(): void {
        $this->insertTeam( 'Empty shell' );

        $this->assertSame( [], ( new TeamWithoutHeadCoachAlert() )->evaluate( new AlertContext( $this->club ) ) );
    }

    public function test_team_whose_only_players_are_archived_produces_nothing(): void {
        global $wpdb;
        $team   = $this->insertTeam( 'U12 alerts' );
        $player = $this->insertPlayer( $team, $this->daysAgo( 30 ), 'active' );
        $wpdb->update( "{$this->p}tt_players", [ 'archived_at' => current_time( 'mysql' ) ], [ 'id' => $player ] );

        $this->assertSame( [], ( new TeamWithoutHeadCoachAlert() )->evaluate( new AlertContext( $this->club ) ) );
    }

    public function test_archived_team_produces_nothing(): void {
        global $wpdb;
        $team = $this->insertTeam( 'U12 alerts' );
        $this->insertPlayer( $team, $this->daysAgo( 30 ), 'active' );
        $wpdb->update( "{$this->p}tt_teams", [ 'archived_at' => current_time( 'mysql' ) ], [ 'id' => $team ] );

        $this->assertSame( [], ( new TeamWithoutHeadCoachAlert() )->evaluate( new AlertContext( $this->club ) ) );
    }

    /**
     * Trial groups are run by whoever is running the trial, not by a
     * permanent head coach.
     */
    public function test_trial_group_produces_nothing(): void {
        global $wpdb;
        $team = $this->insertTeam( 'Trial group' );
        $this->insertPlayer( $team, $this->daysAgo( 30 ), 'active' );
        $wpdb->update( "{$this->p}tt_teams", [ 'team_kind' => 'trial_group' ], [ 'id' => $team ] );

        $this->assertSame( [], ( new TeamWithoutHeadCoachAlert() )->evaluate( new AlertContext( $this->club ) ) );
    }

    // -- fixtures --------------------------------------------------------

    private function daysAgo( int $n ): string {
        return gmdate( 'Y-m-d', current_time( 'timestamp' ) - $n * DAY_IN_SECONDS );
    }

    private function insertTeam( string $name ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_teams", [ 'club_id' => $this->club, 'name' => $name ] );
        return (int) $wpdb->insert_id;
    }

    private function insertPlayer( int $team_id, string $date_joined, string $status ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_players", [
            'club_id'     => $this->club,
            'team_id'     => $team_id,
            'first_name'  => 'Alert',
            'last_name'   => 'Fixture',
            'status'      => $status,
            'date_joined' => $date_joined,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function assignHeadCoach( int $team_id, int $user_id, ?string $end_date ): void {
        global $wpdb;

        $role_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$this->p}tt_functional_roles WHERE role_key = %s LIMIT 1",
            'head_coach'
        ) );
        if ( $role_id <= 0 ) {
            $wpdb->insert( "{$this->p}tt_functional_roles", [
                'club_id'  => $this->club,
                'role_key' => 'head_coach',
                'label'    => 'Head Coach',
            ] );
            $role_id = (int) $wpdb->insert_id;
        }

        $wpdb->insert( "{$this->p}tt_people", [
            'club_id'    => $this->club,
            'first_name' => 'Head',
            'last_name'  => 'Coach',
            'wp_user_id' => $user_id,
        ] );
        $person_id = (int) $wpdb->insert_id;

        $wpdb->insert( "{$this->p}tt_team_people", [
            'club_id'            => $this->club,
            'team_id'            => $team_id,
            'person_id'          => $person_id,
            'functional_role_id' => $role_id,
            'end_date'           => $end_date,
        ] );
    }
}
