<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\Analytics\Cron\ScheduledReportsRunner;
use TT\Modules\Analytics\Domain\Kpi;
use TT\Modules\Analytics\KpiRegistry;
use TT\Modules\Analytics\ScheduledReportsRepository;
use TT\Modules\Comms\Channel\Adapters\EmailChannelAdapter;
use TT\Modules\Comms\Channel\ChannelAdapterRegistry;
use TT\Modules\Comms\Template\TemplateRegistry;
use TT\Modules\Comms\Template\TemplateSwitch;
use TT\Modules\Comms\Templates\ScheduledReportTemplate;
use TT\Infrastructure\Query\QueryHelpers;

/**
 * #3080 — where a scheduled report's CSV lives while it is being sent.
 *
 * It used to be written to `wp_upload_dir()['basedir']` — the served root
 * of `wp-content/uploads/` — under `tt-report-<kpi_key>-<date>.csv`, and
 * removed with a suppressed `@unlink()`. Web-reachable, fully guessable,
 * and permanent if the unlink ever failed. These are reports about minors.
 *
 * The three properties that fix is made of are pinned here, because all
 * three are invisible from the outside: the send looks identical whether
 * the file was staged safely or not.
 */
final class ScheduledReportTempFileTest extends WP_UnitTestCase {

    private const KPI_KEY = 'tt_test_report_kpi';

    private ScheduledReportsRepository $repo;

    /** @var array<string,mixed>|null last payload the transport filter saw */
    private $captured = null;

    /** Attachment paths as they were at send time, plus whether each existed. */
    private array $attachments_at_send = [];

    /** @var array<string,Kpi> */
    private array $kpis_before = [];

    public function set_up(): void {
        parent::set_up();

        // The registry is static and shared across the whole run, so the
        // fixture KPI is snapshotted out again rather than left behind.
        $this->kpis_before = KpiRegistry::all();

        KpiRegistry::register( new Kpi(
            self::KPI_KEY,
            'Test report',
            'tt_test_fact',
            'tt_test_measure'
        ) );

        // Register only what this path needs; the registries are static and
        // sibling Comms tests clear them.
        TemplateRegistry::clear();
        ChannelAdapterRegistry::clear();
        TemplateRegistry::register( new ScheduledReportTemplate() );
        ChannelAdapterRegistry::register( new EmailChannelAdapter() );

        QueryHelpers::set_config( TemplateSwitch::CONFIG_KEY, '' );
        // Pin the quiet-hours window shut so the wall clock cannot decide
        // whether this test sends.
        QueryHelpers::set_config( 'comms_quiet_hours_start', '03:00' );
        QueryHelpers::set_config( 'comms_quiet_hours_end', '03:01' );

        $this->repo = new ScheduledReportsRepository();

        $this->captured            = null;
        $this->attachments_at_send = [];
        add_filter( 'tt_comms_email_send', [ $this, 'captureSend' ], 10, 2 );
    }

    public function tear_down(): void {
        remove_filter( 'tt_comms_email_send', [ $this, 'captureSend' ], 10 );
        TemplateRegistry::clear();
        ChannelAdapterRegistry::clear();

        KpiRegistry::clear();
        foreach ( $this->kpis_before as $kpi ) {
            KpiRegistry::register( $kpi );
        }

        parent::tear_down();
    }

    /**
     * @param mixed               $accepted
     * @param array<string,mixed> $payload
     */
    public function captureSend( $accepted, array $payload ): bool {
        $this->captured = $payload;
        foreach ( (array) ( $payload['attachments'] ?? [] ) as $path ) {
            $this->attachments_at_send[] = [
                'path'   => (string) $path,
                'exists' => file_exists( (string) $path ),
            ];
        }
        return true;
    }

    // ── the three properties ───────────────────────────────────────────

    public function test_the_csv_is_never_written_under_the_uploads_root(): void {
        $this->runDueSchedule();

        $basedir = untrailingslashit( (string) wp_upload_dir()['basedir'] );

        $this->assertNotSame( [], $this->attachments_at_send, 'The send carried no attachment.' );

        foreach ( $this->attachments_at_send as $attachment ) {
            $this->assertStringStartsNotWith(
                $basedir,
                $attachment['path'],
                'A scheduled report must not be staged anywhere under wp-content/uploads/ — '
                . 'that tree is served, and the report names children.'
            );
            $this->assertTrue(
                $attachment['exists'],
                'The staged CSV must still exist when the transport reads it, or the '
                . 'adapter silently drops the attachment and the operator gets an empty email.'
            );
        }
    }

    public function test_the_path_carries_a_per_run_random_component(): void {
        $this->runDueSchedule();
        $first = $this->attachments_at_send[0]['path'] ?? '';

        $this->attachments_at_send = [];
        $this->runDueSchedule();
        $second = $this->attachments_at_send[0]['path'] ?? '';

        $this->assertNotSame( '', $first );
        $this->assertNotSame( '', $second );
        $this->assertNotSame(
            $first,
            $second,
            'Two runs on the same day for the same KPI must not share a path — a '
            . 'predictable name is what made the old location guessable.'
        );
    }

    /**
     * The recipient still gets a file they can open. This is why the fix
     * randomises the *directory* and not the filename: `wp_tempnam()` would
     * hand `wp_mail()` a `.tmp`.
     */
    public function test_the_attachment_keeps_its_readable_csv_name(): void {
        $this->runDueSchedule();
        $name = basename( $this->attachments_at_send[0]['path'] ?? '' );

        $this->assertSame(
            'tt-report-' . self::KPI_KEY . '-' . gmdate( 'Y-m-d' ) . '.csv',
            $name
        );
    }

    public function test_nothing_is_left_behind_after_the_run(): void {
        $this->runDueSchedule();

        foreach ( $this->attachments_at_send as $attachment ) {
            $this->assertFileDoesNotExist(
                $attachment['path'],
                'The staged CSV must be gone once the run returns.'
            );
            $this->assertDirectoryDoesNotExist(
                dirname( (string) $attachment['path'] ),
                'The staging directory goes with the file — an empty directory per run '
                . 'accumulates just as quietly as the files did.'
            );
        }
    }

    public function test_no_report_csv_lands_in_the_uploads_root(): void {
        $this->runDueSchedule();

        $stragglers = glob( untrailingslashit( (string) wp_upload_dir()['basedir'] ) . '/tt-report-*.csv' );

        $this->assertSame(
            [],
            is_array( $stragglers ) ? $stragglers : [],
            'wp-content/uploads/ must hold no scheduled-report CSV, during the send or after it.'
        );
    }

    // ── helpers ────────────────────────────────────────────────────────

    /**
     * Create one schedule, make it due, and run the cron handler.
     */
    private function runDueSchedule(): void {
        global $wpdb;

        $id = $this->repo->create( [
            'name'       => 'Nightly test report',
            'kpi_key'    => self::KPI_KEY,
            'frequency'  => ScheduledReportsRepository::FREQUENCY_WEEKLY_MONDAY,
            'recipients' => [ 'operator@example.test' ],
        ], 0 );

        $this->assertGreaterThan( 0, $id, 'Could not create the schedule fixture.' );

        // `create()` puts next_run_at in the future; `dueForRun()` wants it
        // in the past.
        $wpdb->update(
            $wpdb->prefix . 'tt_scheduled_reports',
            [ 'next_run_at' => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) ],
            [ 'id' => $id ]
        );

        ScheduledReportsRunner::run();

        // Leave no due schedule behind for the next call in this test.
        $wpdb->delete( $wpdb->prefix . 'tt_scheduled_reports', [ 'id' => $id ] );
    }
}
