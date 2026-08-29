<?php
namespace TT\Modules\Analytics\Cron;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Identity\ContactResolver;
use TT\Infrastructure\Logging\Logger;
use TT\Modules\Analytics\Export\CsvExporter;
use TT\Modules\Analytics\KpiRegistry;
use TT\Modules\Analytics\ScheduledReportsRepository;
use TT\Modules\Comms\Dispatch\CommsDispatcher;
use TT\Modules\Comms\Domain\CommsOutcomeSummary;
use TT\Modules\Comms\Domain\MessageType;
use TT\Modules\Comms\Domain\Recipient;
use TT\Modules\Comms\Templates\ScheduledReportTemplate;

/**
 * ScheduledReportsRunner — daily cron that processes due schedules
 * (#0083 Child 6).
 *
 * `init()` registers a daily WP-cron `tt_scheduled_reports_cron`.
 * The hook handler iterates `ScheduledReportsRepository::dueForRun()`,
 * renders each as CSV via `CsvExporter::forKpi()`, sends it through
 * Comms with the file attached, then stamps `last_run_at` +
 * `next_run_at` on the schedule.
 *
 * Each run writes one row to the audit log (`scheduled_report.run`)
 * with the schedule id, recipient count, and success / failure.
 *
 * License-gated: schedules registered on installs whose effective
 * tier doesn't have `scheduled_reports` are skipped at run time
 * (operators can keep their definitions in case the plan upgrades).
 */
final class ScheduledReportsRunner {

    public const HOOK = 'tt_scheduled_reports_cron';

    public static function init(): void {
        add_action( self::HOOK, [ self::class, 'run' ] );
        // Schedule on plugin boot if missing.
        if ( ! wp_next_scheduled( self::HOOK ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
        }
    }

    public static function run(): void {
        // License gate — silently skip when the tier doesn't have it.
        // Operators see their definitions but no emails go out.
        if ( class_exists( '\\TT\\Modules\\License\\LicenseGate' )
             && ! \TT\Modules\License\LicenseGate::allows( 'scheduled_reports' )
        ) {
            return;
        }

        $repo = new ScheduledReportsRepository();
        $due  = $repo->dueForRun();
        $now  = current_time( 'mysql', true );

        foreach ( $due as $schedule ) {
            $kpi_key = (string) ( $schedule['kpi_key'] ?? '' );
            if ( $kpi_key === '' || KpiRegistry::find( $kpi_key ) === null ) {
                $repo->markRun( (int) $schedule['id'], $now );
                continue;
            }
            $csv = CsvExporter::forKpi( $kpi_key );
            $recipients = self::resolveRecipients( (array) $schedule['recipients'] );
            if ( empty( $recipients ) ) {
                $repo->markRun( (int) $schedule['id'], $now );
                continue;
            }

            $kpi = KpiRegistry::find( $kpi_key );

            // #3080 — the CSV is staged outside the web root, in a
            // directory whose name nobody can guess. See `stagingDir()`.
            $dir = self::stagingDir();
            if ( $dir === '' ) {
                self::auditLog( $schedule, count( $recipients ), false );
                $repo->markRun( (int) $schedule['id'], $now );
                continue;
            }

            $filename = 'tt-report-' . sanitize_key( $kpi_key ) . '-' . gmdate( 'Y-m-d' ) . '.csv';
            $tmp_path = $dir . '/' . $filename;

            try {
                // A failed write used to pass unnoticed: the adapter drops
                // an unreadable attachment silently, so the operator got a
                // "here is your report" email with no report in it. Treat
                // it as a failed run instead.
                if ( file_put_contents( $tmp_path, $csv ) === false ) {
                    Logger::error(
                        'Scheduled report: could not write the CSV, send skipped.',
                        [ 'schedule_id' => (int) $schedule['id'], 'kpi_key' => $kpi_key ]
                    );
                    self::auditLog( $schedule, count( $recipients ), false );
                    $repo->markRun( (int) $schedule['id'], $now );
                    continue;
                }

                // #2604 — through Comms rather than a direct `wp_mail()`, so
                // the send is audited and an operator who muted scheduled
                // reports stops receiving them. The rendered CSV rides along
                // on the request's attachment paths.
                $results = CommsDispatcher::dispatchSync(
                    ScheduledReportTemplate::KEY,
                    [
                        'schedule_name' => (string) $schedule['name'],
                        'kpi_label'     => $kpi ? $kpi->label : $kpi_key,
                    ],
                    $recipients,
                    [
                        'message_type'   => MessageType::SCHEDULED_REPORT,
                        'sender_user_id' => 0,
                        'attachments'    => [ $tmp_path ],
                    ]
                );

                self::auditLog( $schedule, count( $recipients ), CommsOutcomeSummary::sentCount( $results ) > 0 );
                $repo->markRun( (int) $schedule['id'], $now );
            } finally {
                // `finally`, not the happy path: a throw anywhere above used
                // to leave the file behind for good. A leftover nobody is
                // told about is what turns a short window into a permanent
                // one, so the failure is logged rather than suppressed.
                self::discardStagingDir( $dir );
            }
        }
    }

    /**
     * A private, single-use directory to render one report into.
     *
     * The CSV used to be written straight into `wp_upload_dir()['basedir']`
     * — the served root of `wp-content/uploads/` — under the fully
     * predictable name `tt-report-<kpi>-<date>.csv`, and removed with a
     * suppressed `@unlink()`. Anyone who knew the site could guess today's
     * URL for any KPI, and a failed unlink left it there indefinitely.
     * These are reports about minors.
     *
     * Three properties, in the order they matter:
     *
     * - **Not served.** `get_temp_dir()` resolves `WP_TEMP_DIR`, then
     *   `sys_get_temp_dir()`, then PHP's upload tmp dir before its
     *   last-resort `WP_CONTENT_DIR` fallback. On any normal install the
     *   file is outside the web root entirely.
     * - **Not guessable.** The 20-character random directory component
     *   closes the hole even on an install that lands on that fallback,
     *   and two runs on the same day for the same KPI cannot collide.
     * - **Still a sensible attachment.** The human-readable filename lives
     *   *inside* the random directory. This is why it is not
     *   `wp_tempnam()`: that names the file `<stem>-<random>.tmp`, and the
     *   adapter passes the path straight to `wp_mail()`, so the recipient
     *   would receive a `.tmp` they cannot open.
     *
     * @return string Absolute path with no trailing slash, or '' on failure.
     */
    private static function stagingDir(): string {
        $dir = trailingslashit( get_temp_dir() ) . 'tt-report-' . wp_generate_password( 20, false );

        if ( ! wp_mkdir_p( $dir ) ) {
            Logger::error(
                'Scheduled report: could not create the staging directory, send skipped.',
                [ 'dir' => $dir ]
            );
            return '';
        }

        return untrailingslashit( $dir );
    }

    /**
     * Remove a staging directory and everything in it. Called from a
     * `finally`, so it must not throw; a failure is logged instead, because
     * a leftover file that nobody is told about is the actual problem.
     */
    private static function discardStagingDir( string $dir ): void {
        if ( $dir === '' || ! is_dir( $dir ) ) return;

        $leftovers = glob( $dir . '/*' );
        foreach ( is_array( $leftovers ) ? $leftovers : [] as $path ) {
            if ( ! is_file( $path ) ) continue;
            if ( ! unlink( $path ) ) {
                Logger::error(
                    'Scheduled report: could not delete the staged CSV. It is outside the web root, but remove it by hand.',
                    [ 'path' => $path ]
                );
            }
        }

        if ( ! rmdir( $dir ) ) {
            Logger::warning(
                'Scheduled report: could not remove the staging directory.',
                [ 'dir' => $dir ]
            );
        }
    }

    /**
     * Expand role keys (e.g. `tt_head_dev`) into individual recipients.
     * Plain email strings pass through as account-less recipients —
     * an operator may legitimately schedule a report to an address that
     * has no login here. Empty / invalid entries silently dropped;
     * duplicates collapse on the address.
     *
     * @param string[] $entries
     * @return Recipient[]
     */
    private static function resolveRecipients( array $entries ): array {
        $byEmail = [];
        foreach ( $entries as $entry ) {
            $entry = trim( (string) $entry );
            if ( $entry === '' ) continue;

            if ( is_email( $entry ) ) {
                if ( ! isset( $byEmail[ $entry ] ) ) {
                    $byEmail[ $entry ] = new Recipient( 0, Recipient::KIND_SYSTEM, null, $entry );
                }
                continue;
            }

            // Treat as a WP role key.
            $users = get_users( [ 'role' => $entry, 'fields' => [ 'ID' ] ] );
            foreach ( $users as $u ) {
                $user_id = (int) ( $u->ID ?? 0 );
                $email   = (string) ( ContactResolver::emailForUser( $user_id ) ?? '' );
                if ( $email === '' || ! is_email( $email ) ) continue;
                // A role expansion wins over a bare address for the same
                // person: it carries the user id, which is what opt-out
                // and locale resolution need.
                $byEmail[ $email ] = new Recipient(
                    $user_id,
                    Recipient::KIND_COACH,
                    null,
                    $email,
                    '',
                    (string) get_user_meta( $user_id, 'locale', true )
                );
            }
        }
        return array_values( $byEmail );
    }

    /**
     * @param array<string,mixed> $schedule
     */
    private static function auditLog( array $schedule, int $recipient_count, bool $ok ): void {
        if ( ! class_exists( '\\TT\\Infrastructure\\Audit\\AuditService' ) ) return;
        ( new \TT\Infrastructure\Audit\AuditService() )->record(
            'scheduled_report.run',
            'scheduled_report',
            (int) ( $schedule['id'] ?? 0 ),
            [
                'name'             => (string) ( $schedule['name'] ?? '' ),
                'kpi_key'          => (string) ( $schedule['kpi_key'] ?? '' ),
                'recipients_count' => $recipient_count,
                'ok'               => $ok,
            ]
        );
    }
}
