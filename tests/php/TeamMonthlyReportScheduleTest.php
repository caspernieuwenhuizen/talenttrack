<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Database\MigrationHelpers;
use TT\Infrastructure\Filters\SavedViewsRepository;
use TT\Modules\Analytics\Cron\ScheduledReportsRunner;
use TT\Modules\Analytics\Reports\TeamMonthlyReportComposition;
use TT\Modules\Analytics\Reports\TeamMonthlyReportDelivery;
use TT\Modules\Analytics\ScheduledReportsRepository;
use TT\Modules\Comms\Channel\Adapters\EmailChannelAdapter;
use TT\Modules\Comms\Channel\ChannelAdapterRegistry;
use TT\Modules\Comms\Template\TemplateRegistry;
use TT\Modules\Comms\Template\TemplateSwitch;
use TT\Modules\Comms\Templates\ScheduledReportTemplate;
use TT\Infrastructure\Query\QueryHelpers;

/**
 * #3462 (epic #3457) — the team monthly report, mailed on the 1st.
 *
 * Pinned: the migration is idempotent and KPI schedules keep their shape; a
 * schedule firing on 1 October reports on September, and across a year
 * boundary; the schedule renders from its own copy, so deleting the coach's
 * saved view changes nothing; a schedule whose team is archived, or whose
 * owner lost access, stops rather than sends, and says why; and a good run
 * mails a PDF named after the team and the month, with an audit row.
 */
final class TeamMonthlyReportScheduleTest extends WP_UnitTestCase {

    private int $team_id = 0;
    private ScheduledReportsRepository $repo;

    /** @var list<string> attachment basenames the transport saw */
    private array $sent = [];

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => 1, 'name' => 'JO13-1', 'age_group' => 'U13' ] );
        $this->team_id = (int) $wpdb->insert_id;
        $this->repo    = new ScheduledReportsRepository();

        // Idempotent; makes the columns this test writes present whatever
        // the suite's bootstrap ran.
        ( require dirname( __DIR__, 2 ) . '/database/migrations/0266_scheduled_reports_composition.php' )->up();

        TemplateRegistry::clear();
        ChannelAdapterRegistry::clear();
        TemplateRegistry::register( new ScheduledReportTemplate() );
        ChannelAdapterRegistry::register( new EmailChannelAdapter() );
        QueryHelpers::set_config( TemplateSwitch::CONFIG_KEY, '' );
        QueryHelpers::set_config( 'comms_quiet_hours_start', '03:00' );
        QueryHelpers::set_config( 'comms_quiet_hours_end', '03:01' );

        $this->sent = [];
        add_filter( 'tt_comms_email_send', [ $this, 'captureSend' ], 10, 2 );
    }

    public function tear_down(): void {
        remove_filter( 'tt_comms_email_send', [ $this, 'captureSend' ], 10 );
        TemplateRegistry::clear();
        ChannelAdapterRegistry::clear();
        parent::tear_down();
    }

    /**
     * @param mixed               $accepted
     * @param array<string,mixed> $payload
     */
    public function captureSend( $accepted, array $payload ): bool {
        foreach ( (array) ( $payload['attachments'] ?? [] ) as $path ) {
            $this->sent[] = basename( (string) $path );
        }
        return true;
    }

    public function test_the_migration_is_idempotent_and_kpi_schedules_keep_their_shape(): void {
        global $wpdb;
        $migration = require dirname( __DIR__, 2 ) . '/database/migrations/0266_scheduled_reports_composition.php';
        $migration->up();
        $migration->up();

        $table = $wpdb->prefix . 'tt_scheduled_reports';
        foreach ( [ 'report_key', 'composition_json', 'last_error' ] as $column ) {
            $this->assertTrue( MigrationHelpers::columnExists( $table, $column ), "{$column} missing" );
        }

        $id  = $this->repo->create( [ 'name' => 'KPI digest', 'kpi_key' => 'attendance_rate', 'frequency' => ScheduledReportsRepository::FREQUENCY_WEEKLY_MONDAY, 'recipients' => [ 'a@example.test' ], 'format' => 'csv' ], 1 );
        $row = $this->repo->findById( $id );
        $this->assertNotNull( $row );
        $this->assertSame( ScheduledReportsRepository::REPORT_KPI, $row['report_key'] );
        $this->assertNull( $row['composition'] );
        $this->assertNull( $row['last_error'] );
    }

    public function test_a_run_on_the_first_reports_on_the_month_before(): void {
        $owner    = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $schedule = $this->schedule( $owner );

        $october = TeamMonthlyReportDelivery::plan( $schedule, '2026-10-01' );
        $this->assertTrue( $october['ok'], $october['error'] );
        $this->assertSame( '2026-09-01', $october['window']['from'] );
        $this->assertSame( '2026-09-30', $october['window']['to'] );
        $this->assertSame( 'JO13-1-2026-09.pdf', $october['filename'] );

        $january = TeamMonthlyReportDelivery::plan( $schedule, '2027-01-01' );
        $this->assertSame( [ 'from' => '2026-12-01', 'to' => '2026-12-31', 'period' => 'last_month' ], $january['window'] );
        $this->assertSame( 'JO13-1-2026-12.pdf', $january['filename'] );

        $late = TeamMonthlyReportDelivery::plan( $schedule, '2026-10-02' );
        $this->assertSame( '2026-09-01', $late['window']['from'], 'A cron that fires a day late still reports on the month before.' );
    }

    /** The test that matters most: the schedule does not depend on a preset. */
    public function test_deleting_the_coachs_saved_view_does_not_change_the_schedule(): void {
        $owner  = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $views  = new SavedViewsRepository();
        $stored = [ 'team_id' => (string) $this->team_id, 'layout' => 'A', 'blocks' => 'kpi,attention,roster' ];
        $view   = $views->create( $owner, TeamMonthlyReportComposition::VIEW_KEY, 'Staff meeting', $stored );
        $this->assertNotNull( $view );

        $id = $this->repo->create( [
            'name'        => 'Monthly report JO13-1',
            'report_key'  => ScheduledReportsRepository::REPORT_TEAM_MONTHLY,
            'composition' => array_merge( TeamMonthlyReportComposition::normalise( $stored ), [ 'period' => 'last_month' ] ),
            'frequency'   => ScheduledReportsRepository::FREQUENCY_MONTHLY_FIRST,
            'recipients'  => [ 'staff@example.test' ],
            'format'      => 'pdf',
        ], $owner );

        $before = TeamMonthlyReportDelivery::plan( (array) $this->repo->findById( $id ), '2026-10-01' );
        $this->assertTrue( $views->delete( (int) $view->id, $owner ) );
        $after = TeamMonthlyReportDelivery::plan( (array) $this->repo->findById( $id ), '2026-10-01' );

        $this->assertTrue( $after['ok'] );
        $this->assertSame( $before, $after );
        $this->assertSame( 'A', $after['composition']['layout'] );
        $this->assertSame( [ 'kpi', 'attention', 'roster' ], $after['composition']['blocks'] );
    }

    public function test_an_archived_team_stops_the_schedule_instead_of_mailing(): void {
        global $wpdb;
        $owner = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $id    = (int) $this->schedule( $owner )['id'];
        $wpdb->update( "{$wpdb->prefix}tt_teams", [ 'archived_at' => current_time( 'mysql' ) ], [ 'id' => $this->team_id ] );

        ScheduledReportsRunner::runTeamMonthly( $this->repo, (array) $this->repo->findById( $id ), '2026-10-01 06:00:00' );

        $row = (array) $this->repo->findById( $id );
        $this->assertSame( [], $this->sent, 'Nothing is mailed about an archived team.' );
        $this->assertSame( ScheduledReportsRepository::STATUS_PAUSED, $row['status'] );
        $this->assertNotEmpty( $row['last_error'], 'The schedules screen says why it stopped.' );
    }

    public function test_an_owner_who_lost_access_stops_the_schedule(): void {
        $owner = self::factory()->user->create( [ 'role' => 'subscriber' ] );
        $id    = (int) $this->schedule( $owner )['id'];

        ScheduledReportsRunner::runTeamMonthly( $this->repo, (array) $this->repo->findById( $id ), '2026-10-01 06:00:00' );

        $row = (array) $this->repo->findById( $id );
        $this->assertSame( [], $this->sent );
        $this->assertSame( ScheduledReportsRepository::STATUS_PAUSED, $row['status'] );
        $this->assertNotEmpty( $row['last_error'] );
    }

    public function test_a_good_run_mails_the_pdf_named_for_team_and_month_and_audits_it(): void {
        if ( ! class_exists( \Dompdf\Dompdf::class ) ) {
            $this->markTestSkipped( 'DomPDF not installed.' );
        }
        global $wpdb;
        $owner = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $id    = (int) $this->schedule( $owner )['id'];
        $this->repo->recordError( $id, 'Left over from a previous run.' );

        $audit_before = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}tt_audit_log WHERE action = %s", 'scheduled_report.run' ) );

        ScheduledReportsRunner::runTeamMonthly( $this->repo, (array) $this->repo->findById( $id ), gmdate( 'Y-m-01' ) . ' 06:00:00' );

        $expected = 'JO13-1-' . gmdate( 'Y-m', (int) strtotime( gmdate( 'Y-m-01' ) . ' -1 month' ) ) . '.pdf';
        $this->assertSame( [ $expected ], $this->sent );

        $row = (array) $this->repo->findById( $id );
        $this->assertNull( $row['last_error'], 'A run that sent clears the old reason.' );
        $this->assertSame( ScheduledReportsRepository::STATUS_ACTIVE, $row['status'] );

        $audit_after = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}tt_audit_log WHERE action = %s", 'scheduled_report.run' ) );
        $this->assertSame( $audit_before + 1, $audit_after );
    }

    /** @return array<string,mixed> a hydrated team-monthly schedule for `$owner`. */
    private function schedule( int $owner ): array {
        $id = $this->repo->create( [
            'name'        => 'Monthly report JO13-1',
            'report_key'  => ScheduledReportsRepository::REPORT_TEAM_MONTHLY,
            'composition' => TeamMonthlyReportComposition::normalise( [ 'team_id' => $this->team_id, 'period' => 'last_month', 'layout' => 'B' ] ),
            'frequency'   => ScheduledReportsRepository::FREQUENCY_MONTHLY_FIRST,
            'recipients'  => [ 'staff@example.test' ],
            'format'      => 'pdf',
        ], $owner );
        $this->assertGreaterThan( 0, $id );
        return (array) $this->repo->findById( $id );
    }
}
