<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Config\ConfigService;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Alerts\Definitions\NoMeasurementThisSeasonAlert;
use TT\Modules\Alerts\Domain\AlertContext;
use TT\Modules\Authorization\Matrix\MatrixRepository;

/**
 * #2636 instalment 4 — `measurements.none_this_season`.
 *
 * Two things are under test and they fail in different ways. The query has
 * to mean "not measured in the current season", which is not the same as
 * "not measured recently" — a result from last season must not count, and a
 * result from this season must. And the audience has to respect the
 * `measurements` matrix entity, because the module has no legacy `tt_*`
 * capability for the evaluator's own gate to use; without that filter the
 * alert would name a player to somebody with no access to their measurement
 * history at all.
 */
final class AlertsMeasurementDefinitionTest extends WP_UnitTestCase {

    private const PERSONA = 'scout';
    private const MODULE  = 'TT\\Modules\\Measurements\\MeasurementsModule';

    /** @var string */
    private $p;

    /** @var int */
    private $club = 1;

    /** @var int */
    private $head;

    /** @var ConfigService */
    private $config;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->p = $wpdb->prefix;

        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        MatrixRepository::clearCache();

        $this->head   = self::factory()->user->create( [ 'role' => 'tt_scout' ] );
        $this->config = new ConfigService();

        // A season seeded elsewhere would give the definition a second
        // current season to join against. DELETE, not TRUNCATE: TRUNCATE
        // commits and would break the transaction rollback between tests.
        $wpdb->query( "DELETE FROM {$this->p}tt_seasons" );
    }

    public function tear_down(): void {
        ( new MatrixRepository() )->removeRow( self::PERSONA, 'measurements', 'read', 'global' );
        MatrixRepository::clearCache();
        parent::tear_down();
    }

    public function test_unmeasured_player_alerts_the_head_coach(): void {
        $this->grantMeasurementsRead();
        $season = $this->insertCurrentSeason();
        $team   = $this->insertTeam();
        $this->assignHeadCoach( $team, $this->head );
        $player = $this->insertPlayer( $team );

        $out = ( new NoMeasurementThisSeasonAlert() )->evaluate( new AlertContext( $this->club ) );

        $this->assertCount( 1, $out );
        $this->assertSame( $this->head, $out[0]->recipientUserId );
        $this->assertSame( 'player', $out[0]->subjectType );
        $this->assertSame( $player, $out[0]->playerId );
        $this->assertNotSame( '', $out[0]->title() );
        unset( $season );
    }

    public function test_player_measured_this_season_produces_nothing(): void {
        $this->grantMeasurementsRead();
        $this->insertCurrentSeason();
        $team   = $this->insertTeam();
        $this->assignHeadCoach( $team, $this->head );
        $player = $this->insertPlayer( $team );
        $this->insertResult( $player, $this->daysAgo( 30 ) );

        $this->assertSame( [], ( new NoMeasurementThisSeasonAlert() )->evaluate( new AlertContext( $this->club ) ) );
    }

    /**
     * A measurement from before the season started does not cover it. This
     * is the difference between "not measured this season" and "not
     * measured recently", and getting it wrong makes the alert silently
     * under-report every returning player.
     */
    public function test_measurement_from_before_the_season_does_not_count(): void {
        $this->grantMeasurementsRead();
        $this->insertCurrentSeason();
        $team   = $this->insertTeam();
        $this->assignHeadCoach( $team, $this->head );
        $player = $this->insertPlayer( $team );
        $this->insertResult( $player, $this->daysAgo( 300 ) );

        $this->assertCount( 1, ( new NoMeasurementThisSeasonAlert() )->evaluate( new AlertContext( $this->club ) ) );
    }

    public function test_archived_measurement_does_not_count(): void {
        global $wpdb;
        $this->grantMeasurementsRead();
        $this->insertCurrentSeason();
        $team   = $this->insertTeam();
        $this->assignHeadCoach( $team, $this->head );
        $player = $this->insertPlayer( $team );
        $id     = $this->insertResult( $player, $this->daysAgo( 30 ) );
        $wpdb->update( "{$this->p}tt_measurement_results", [ 'archived_at' => current_time( 'mysql' ) ], [ 'id' => $id ] );

        $this->assertCount( 1, ( new NoMeasurementThisSeasonAlert() )->evaluate( new AlertContext( $this->club ) ) );
    }

    public function test_trashed_measurement_does_not_count(): void {
        global $wpdb;
        $this->grantMeasurementsRead();
        $this->insertCurrentSeason();
        $team   = $this->insertTeam();
        $this->assignHeadCoach( $team, $this->head );
        $player = $this->insertPlayer( $team );
        $id     = $this->insertResult( $player, $this->daysAgo( 30 ) );
        $wpdb->update( "{$this->p}tt_measurement_results", [ 'trashed_at' => current_time( 'mysql' ) ], [ 'id' => $id ] );

        $this->assertCount( 1, ( new NoMeasurementThisSeasonAlert() )->evaluate( new AlertContext( $this->club ) ) );
    }

    /**
     * Week one of a season would otherwise alert every coach about every
     * player at once, which is indistinguishable from telling them nothing.
     */
    public function test_season_still_inside_the_grace_period_produces_nothing(): void {
        $this->grantMeasurementsRead();
        $this->insertSeason( 'New season', $this->daysAgo( 10 ), $this->daysAhead( 300 ), 1 );
        $team = $this->insertTeam();
        $this->assignHeadCoach( $team, $this->head );
        $this->insertPlayer( $team );

        $this->assertSame( [], ( new NoMeasurementThisSeasonAlert() )->evaluate( new AlertContext( $this->club ) ) );
    }

    public function test_no_current_season_produces_nothing(): void {
        $this->grantMeasurementsRead();
        $this->insertSeason( 'Old season', $this->daysAgo( 700 ), $this->daysAgo( 400 ), 0 );
        $team = $this->insertTeam();
        $this->assignHeadCoach( $team, $this->head );
        $this->insertPlayer( $team );

        $this->assertSame( [], ( new NoMeasurementThisSeasonAlert() )->evaluate( new AlertContext( $this->club ) ) );
    }

    /**
     * The reason this definition filters its own recipients: `measurements`
     * has no legacy capability, so the evaluator's `capRequired()` gate
     * cannot express it. Without this the alert would name a player to
     * somebody with no access to their measurement history.
     */
    public function test_recipient_without_measurements_access_receives_nothing(): void {
        $this->insertCurrentSeason();
        $team = $this->insertTeam();
        $this->assignHeadCoach( $team, $this->head );
        $this->insertPlayer( $team );

        // No matrix grant for the scout persona this time.
        $this->assertSame( [], ( new NoMeasurementThisSeasonAlert() )->evaluate( new AlertContext( $this->club ) ) );
    }

    public function test_grace_period_comes_from_config_not_from_code(): void {
        $this->grantMeasurementsRead();
        $this->insertSeason( 'New season', $this->daysAgo( 30 ), $this->daysAhead( 300 ), 1 );
        $team = $this->insertTeam();
        $this->assignHeadCoach( $team, $this->head );
        $this->insertPlayer( $team );

        $this->assertSame( [], ( new NoMeasurementThisSeasonAlert() )->evaluate( new AlertContext( $this->club ) ) );

        $this->config->set( NoMeasurementThisSeasonAlert::CONFIG_KEY_GRACE_DAYS, '14' );

        $this->assertCount( 1, ( new NoMeasurementThisSeasonAlert() )->evaluate( new AlertContext( $this->club ) ) );
    }

    // -- fixtures --------------------------------------------------------

    private function grantMeasurementsRead(): void {
        ( new MatrixRepository() )->setRow( self::PERSONA, 'measurements', 'read', 'global', self::MODULE );
        MatrixRepository::clearCache();
    }

    private function daysAgo( int $n ): string {
        return gmdate( 'Y-m-d', current_time( 'timestamp' ) - $n * DAY_IN_SECONDS );
    }

    private function daysAhead( int $n ): string {
        return gmdate( 'Y-m-d', current_time( 'timestamp' ) + $n * DAY_IN_SECONDS );
    }

    private function insertCurrentSeason(): int {
        return $this->insertSeason( 'This season', $this->daysAgo( 200 ), $this->daysAhead( 160 ), 1 );
    }

    private function insertSeason( string $name, string $start, string $end, int $is_current ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_seasons", [
            'club_id'    => $this->club,
            'name'       => $name,
            'start_date' => $start,
            'end_date'   => $end,
            'is_current' => $is_current,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function insertTeam(): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_teams", [ 'club_id' => $this->club, 'name' => 'U13 alerts' ] );
        return (int) $wpdb->insert_id;
    }

    private function insertPlayer( int $team_id ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_players", [
            'club_id'     => $this->club,
            'team_id'     => $team_id,
            'first_name'  => 'Alert',
            'last_name'   => 'Fixture',
            'status'      => 'active',
            'date_joined' => $this->daysAgo( 400 ),
        ] );
        return (int) $wpdb->insert_id;
    }

    /**
     * `definition_id` is a plain column with no foreign key, and the
     * definition's identity does not affect this query — the condition is
     * "any measurement at all this season".
     */
    private function insertResult( int $player_id, string $recorded_date ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_measurement_results", [
            'club_id'       => $this->club,
            'player_id'     => $player_id,
            'definition_id' => 1,
            'recorded_date' => $recorded_date,
            'value_numeric' => 12.5,
        ] );
        return (int) $wpdb->insert_id;
    }

    /**
     * Head-coach assignment through `tt_team_people`, the single source of
     * truth since #1315 retired `tt_teams.head_coach_id`.
     */
    private function assignHeadCoach( int $team_id, int $user_id ): void {
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
        ] );
    }
}
