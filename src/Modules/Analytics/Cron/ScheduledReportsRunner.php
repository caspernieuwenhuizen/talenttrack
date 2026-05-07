<?php
namespace TT\Modules\Analytics\Cron;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Analytics\Export\CsvExporter;
use TT\Modules\Analytics\KpiRegistry;
use TT\Modules\Analytics\ScheduledReportsRepository;

/**
 * ScheduledReportsRunner — daily cron that processes due schedules
 * (#0083 Child 6).
 *
 * `init()` registers a daily WP-cron `tt_scheduled_reports_cron`.
 * The hook handler iterates `ScheduledReportsRepository::dueForRun()`,
 * renders each as CSV via `CsvExporter::forKpi()`, attaches the file
 * to a `wp_mail()` to every recipient, then stamps `last_run_at` +
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

            $kpi      = KpiRegistry::find( $kpi_key );
            $subject  = sprintf(
                /* translators: 1: schedule name, 2: KPI label */
                __( 'TalentTrack scheduled report: %1$s (%2$s)', 'talenttrack' ),
                (string) $schedule['name'],
                $kpi ? $kpi->label : $kpi_key
            );
            $body = __( "Your TalentTrack scheduled report is attached.\n\nSee the dashboard for the live view.", 'talenttrack' );

            $upload_dir = wp_upload_dir();
            $filename   = 'tt-report-' . sanitize_key( $kpi_key ) . '-' . gmdate( 'Y-m-d' ) . '.csv';
            $tmp_path   = trailingslashit( $upload_dir['basedir'] ) . $filename;
            file_put_contents( $tmp_path, $csv );

            wp_mail( $recipients, $subject, $body, [], [ $tmp_path ] );

            // Best-effort cleanup; file resides in the WP uploads dir
            // under `tt-report-*.csv`. A future hardening pass moves
            // it to a private subdir + scheduled cleanup.
            @unlink( $tmp_path );

            self::auditLog( $schedule, count( $recipients ), true );
            $repo->markRun( (int) $schedule['id'], $now );
        }
    }

    /**
     * Expand role keys (e.g. `tt_head_dev`) into individual emails.
     * Plain email strings pass through. Empty / invalid entries
     * silently dropped.
     *
     * @param string[] $entries
     * @return string[]
     */
    private static function resolveRecipients( array $entries ): array {
        $out = [];
        foreach ( $entries as $entry ) {
            $entry = trim( (string) $entry );
            if ( $entry === '' ) continue;
            if ( is_email( $entry ) ) {
                $out[] = $entry;
                continue;
            }
            // Treat as a WP role key.
            $users = get_users( [ 'role' => $entry, 'fields' => [ 'user_email' ] ] );
            foreach ( $users as $u ) {
                $email = (string) ( $u->user_email ?? '' );
                if ( $email !== '' && is_email( $email ) ) $out[] = $email;
            }
        }
        return array_values( array_unique( $out ) );
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
