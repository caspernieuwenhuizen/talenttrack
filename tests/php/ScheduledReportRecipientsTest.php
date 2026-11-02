<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Analytics\Cron\ScheduledReportsRunner;
use TT\Modules\Analytics\Domain\DimensionValueResolver;
use TT\Modules\Analytics\Domain\FactScope;
use TT\Modules\Analytics\ScheduledReportsRepository;
use TT\Modules\Authorization\FunctionalRoleGrants;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Comms\Channel\Adapters\EmailChannelAdapter;
use TT\Modules\Comms\Channel\ChannelAdapterRegistry;
use TT\Modules\Comms\Template\TemplateRegistry;
use TT\Modules\Comms\Template\TemplateSwitch;
use TT\Modules\Comms\Templates\ScheduledReportTemplate;

/**
 * #4619 — a scheduled KPI report reaches only recipients who may read its
 * data, and each reads it with their own scope. A role expands to people
 * who are checked one by one; an address with no account receives only a
 * report that holds no player data. Whoever is left out is named on the
 * schedule.
 */
final class ScheduledReportRecipientsTest extends WP_UnitTestCase {

    private const PLAYER_KPI = 'attendance_vs_squad';
    private const TEAM_KPI   = 'activity_volume';

    private ScheduledReportsRepository $repo;
    private int $club = 1;
    private int $team_a = 0;
    private int $team_b = 0;
    private int $manager = 0;
    private int $hod = 0;

    /** @var array<string,string> recipient address => CSV they received */
    private array $sent = [];

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        $this->flush();

        global $wpdb;
        $wpdb->hide_errors();
        $this->club = (int) CurrentClub::id();

        $this->team_a  = $this->insertTeam( 'Recipients U12' );
        $this->team_b  = $this->insertTeam( 'Recipients U15' );
        $this->manager = $this->makePersona( 'tt_team_manager', 'team_manager', $this->team_a );
        $this->hod     = $this->makePersona( 'tt_head_dev', 'head_of_development', $this->team_a );

        $pa = $this->insertPlayer( $this->team_a, 'Anna' );
        $pb = $this->insertPlayer( $this->team_b, 'Cas' );
        $this->insertAttendance( $this->insertActivity( $this->team_a, '2020-03-02' ), $pa );
        $this->insertAttendance( $this->insertActivity( $this->team_b, '2020-03-03' ), $pb );

        TemplateRegistry::clear();
        ChannelAdapterRegistry::clear();
        TemplateRegistry::register( new ScheduledReportTemplate() );
        ChannelAdapterRegistry::register( new EmailChannelAdapter() );
        QueryHelpers::set_config( TemplateSwitch::CONFIG_KEY, '' );
        QueryHelpers::set_config( 'comms_quiet_hours_start', '03:00' );
        QueryHelpers::set_config( 'comms_quiet_hours_end', '03:01' );

        $this->repo = new ScheduledReportsRepository();
        $this->sent = [];
        add_filter( 'tt_comms_email_send', [ $this, 'captureSend' ], 10, 2 );
    }

    public function tear_down(): void {
        remove_filter( 'tt_comms_email_send', [ $this, 'captureSend' ], 10 );
        TemplateRegistry::clear();
        ChannelAdapterRegistry::clear();
        $this->flush();
        parent::tear_down();
    }

    /**
     * @param mixed               $accepted
     * @param array<string,mixed> $payload
     */
    public function captureSend( $accepted, array $payload ): bool {
        $csv = '';
        foreach ( (array) ( $payload['attachments'] ?? [] ) as $path ) {
            $csv .= (string) file_get_contents( (string) $path );
        }
        $this->sent[ strtolower( (string) $payload['to'] ) ] = $csv;
        return true;
    }

    public function test_a_parent_role_receives_nothing_for_a_player_level_fact(): void {
        if ( get_role( 'tt_parent' ) === null ) add_role( 'tt_parent', 'parent', [ 'read' => true ] );
        $parent = (int) self::factory()->user->create( [ 'role' => 'tt_parent', 'user_email' => 'parent-4619@example.test' ] );
        $this->flush();
        $this->assertFalse( \TT\Modules\Analytics\FactQuery::canRead( 'attendance', FactScope::forUser( $parent ) ) );

        $id = $this->runSchedule( self::PLAYER_KPI, [ 'tt_parent' ] );

        $this->assertArrayNotHasKey( 'parent-4619@example.test', $this->sent, 'a parent received a club attendance report' );
        $this->assertStringContainsString( 'parent-4619@example.test', (string) $this->lastError( $id ), 'the parent left out is not named on the schedule' );
    }

    public function test_a_team_scoped_recipient_gets_only_their_teams_rows(): void {
        $manager_email = (string) get_userdata( $this->manager )->user_email;
        $hod_email     = (string) get_userdata( $this->hod )->user_email;

        $this->runSchedule( self::PLAYER_KPI, [ $manager_email, $hod_email ] );

        $this->assertArrayHasKey( strtolower( $manager_email ), $this->sent );
        $this->assertStringContainsString( 'Recipients U12', $this->sent[ strtolower( $manager_email ) ] );
        $this->assertStringNotContainsString( 'Recipients U15', $this->sent[ strtolower( $manager_email ) ], 'a team manager received another team\'s rows' );

        $this->assertArrayHasKey( strtolower( $hod_email ), $this->sent );
        $this->assertStringContainsString( 'Recipients U12', $this->sent[ strtolower( $hod_email ) ] );
        $this->assertStringContainsString( 'Recipients U15', $this->sent[ strtolower( $hod_email ) ], 'a club-wide reader lost a team' );
    }

    public function test_a_plain_address_gets_a_report_without_player_data(): void {
        $this->assertFalse( ScheduledReportsRunner::factCarriesPlayerData( 'activities' ) );

        $id = $this->runSchedule( self::TEAM_KPI, [ 'outside-4619@example.test' ], $this->hod );

        $this->assertArrayHasKey( 'outside-4619@example.test', $this->sent );
        $this->assertNull( $this->lastError( $id ) );
    }

    public function test_a_plain_address_does_not_get_a_report_with_player_data(): void {
        foreach ( [ 'attendance', 'evaluations', 'goals', 'journey_events', 'prospects', 'trial_decisions' ] as $fact ) {
            $this->assertTrue( ScheduledReportsRunner::factCarriesPlayerData( $fact ), $fact );
        }

        $id = $this->runSchedule( self::PLAYER_KPI, [ 'outside-4619@example.test' ], $this->hod );

        $this->assertArrayNotHasKey( 'outside-4619@example.test', $this->sent, 'an address with no account received player data' );
        $this->assertStringContainsString( 'outside-4619@example.test', (string) $this->lastError( $id ) );
    }

    public function test_the_skipped_recipients_are_listed_on_the_run_record_and_the_rest_still_receive(): void {
        $hod_email = (string) get_userdata( $this->hod )->user_email;
        if ( get_role( 'tt_parent' ) === null ) add_role( 'tt_parent', 'parent', [ 'read' => true ] );
        self::factory()->user->create( [ 'role' => 'tt_parent', 'user_email' => 'parent-b-4619@example.test' ] );
        $this->flush();

        $id = $this->runSchedule( self::PLAYER_KPI, [ $hod_email, 'tt_parent', 'outside-4619@example.test' ], $this->hod );

        $this->assertArrayHasKey( strtolower( $hod_email ), $this->sent, 'a reader lost the report because someone else was skipped' );
        $note = (string) $this->lastError( $id );
        $this->assertStringContainsString( 'parent-b-4619@example.test', $note );
        $this->assertStringContainsString( 'outside-4619@example.test', $note );
        $this->assertStringNotContainsString( $hod_email, $note );

        $plan = ScheduledReportsRunner::planKpiDelivery( [ $hod_email, 'tt_parent', 'outside-4619@example.test' ], 'attendance', $this->hod );
        $reasons = array_column( $plan['skipped'], 'reason', 'email' );
        $this->assertSame( 'no_read_access', $reasons['parent-b-4619@example.test'] ?? null );
        $this->assertSame( 'player_data_to_address', $reasons['outside-4619@example.test'] ?? null );
    }

    // ===== helpers =====

    /**
     * @param string[] $recipients
     */
    private function runSchedule( string $kpi_key, array $recipients, int $created_by = 0 ): int {
        global $wpdb;
        $id = $this->repo->create( [
            'name'       => 'Recipients test',
            'kpi_key'    => $kpi_key,
            'frequency'  => ScheduledReportsRepository::FREQUENCY_WEEKLY_MONDAY,
            'recipients' => $recipients,
        ], $created_by );
        $this->assertGreaterThan( 0, $id );
        $wpdb->update(
            $wpdb->prefix . 'tt_scheduled_reports',
            [ 'next_run_at' => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) ],
            [ 'id' => $id ]
        );
        $this->flush();
        ScheduledReportsRunner::run();
        return $id;
    }

    private function lastError( int $id ): ?string {
        $row = $this->repo->findById( $id );
        $this->assertNotNull( $row );
        return $row['last_error'];
    }

    private function flush(): void {
        MatrixRepository::clearCache();
        FunctionalRoleGrants::clearCache();
        AuthorizationService::flushCache();
        FactScope::flush();
        DimensionValueResolver::flush();
        wp_cache_flush();
    }

    private function insertTeam( string $name ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => $this->club, 'name' => $name ] );
        return (int) $wpdb->insert_id;
    }

    private function insertPlayer( int $team_id, string $first ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id' => $this->club, 'team_id' => $team_id, 'first_name' => $first, 'last_name' => 'Recipients', 'status' => 'active',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function insertActivity( int $team_id, string $date ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_activities", [
            'club_id' => $this->club, 'team_id' => $team_id, 'title' => 'Training ' . $date, 'session_date' => $date,
            'activity_type_key' => 'training', 'activity_status_key' => 'completed', 'plan_state' => 'completed',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function insertAttendance( int $activity_id, int $player_id ): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_attendance", [
            'club_id' => $this->club, 'activity_id' => $activity_id, 'player_id' => $player_id,
            'status' => 'Present', 'is_guest' => 0, 'record_type' => 'actual',
        ] );
    }

    /** A persona from its WordPress role, with a team-scope grant on one team. */
    private function makePersona( string $role, string $role_type, int $team_id ): int {
        global $wpdb;
        if ( get_role( $role ) === null ) {
            add_role( $role, $role_type, [ 'read' => true ] );
        }
        $user_id = (int) self::factory()->user->create( [ 'role' => $role ] );
        $wpdb->insert( "{$wpdb->prefix}tt_people", [
            'club_id' => $this->club, 'first_name' => ucfirst( $role_type ), 'last_name' => 'Recipients',
            'role_type' => $role_type, 'wp_user_id' => $user_id, 'status' => 'active',
        ] );
        $wpdb->insert( "{$wpdb->prefix}tt_user_role_scopes", [
            'club_id' => $this->club, 'person_id' => (int) $wpdb->insert_id, 'role_id' => 1,
            'scope_type' => 'team', 'scope_id' => $team_id,
        ] );
        $this->flush();
        return $user_id;
    }
}
