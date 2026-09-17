<?php
namespace TT\Modules\Analytics\Reports;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Export\Domain\ExportRequest;
use TT\Modules\Export\Exporters\TeamMonthlyReportPdfExporter;
use TT\Modules\Export\Format\Renderers\PdfRenderer;

/**
 * TeamMonthlyReportDelivery (#3462, epic #3457) — a scheduled team monthly
 * report, from the schedule row to the PDF that is mailed.
 *
 * ## What it renders
 *
 * The schedule's **own copy** of the composition, never a saved view: the
 * cron has no user, and a preset belongs to one. The window is resolved when
 * the schedule runs, so a `last_month` schedule firing on 1 October reports on
 * September.
 *
 * ## When it refuses
 *
 * This document names minors and goes out by email, unattended. So a schedule
 * stops — it is paused, with the reason on the schedules screen — rather than
 * sending, when:
 *
 * - its team no longer exists or has been archived;
 * - the person who set it up can no longer read that team's reports. The
 *   report is rendered as them, and a schedule must not outlive the access
 *   that created it. Resuming it is a deliberate act by someone who still has
 *   that access, after fixing whatever changed.
 *
 * A failure that may pass on its own (the PDF could not be rendered) is
 * recorded but does not pause: the schedule tries again next month.
 */
final class TeamMonthlyReportDelivery {

    /**
     * Everything about a run except the PDF bytes.
     *
     * @param array<string,mixed> $schedule a hydrated `ScheduledReportsRepository` row.
     * @return array{ok:bool, stop:bool, error:string, composition:array{team_id:int, period:string, from:string, to:string, layout:string, blocks:list<string>, options:array<string,array<string,mixed>>}, window:array{from:string, to:string, period:string}, team_name:string, owner:int, filename:string, label:string}
     */
    public static function plan( array $schedule, string $today ): array {
        $raw         = is_array( $schedule['composition'] ?? null ) ? $schedule['composition'] : [];
        $composition = TeamMonthlyReportComposition::normalise( $raw );
        $window      = TeamMonthlyReportComposition::window( $composition, $today );
        $owner       = (int) ( $schedule['created_by'] ?? 0 );

        $plan = [
            'ok'          => false,
            'stop'        => true,
            'error'       => '',
            'composition' => $composition,
            'window'      => $window,
            'team_name'   => '',
            'owner'       => $owner,
            'filename'    => '',
            'label'       => '',
        ];

        $team = $composition['team_id'] > 0 ? QueryHelpers::get_team( $composition['team_id'] ) : null;
        if ( $team === null ) {
            $plan['error'] = __( 'Stopped: the team in this schedule no longer exists.', 'talenttrack' );
            return $plan;
        }
        if ( ! empty( $team->archived_at ) ) {
            $plan['error'] = __( 'Stopped: the team in this schedule has been archived.', 'talenttrack' );
            return $plan;
        }
        if ( ! TeamReportAccess::canRead( $owner, $composition['team_id'] ) ) {
            $plan['error'] = __( 'Stopped: the person who set up this schedule can no longer read this team’s reports.', 'talenttrack' );
            return $plan;
        }

        $team_name = (string) ( $team->name ?? '' );
        $month_ts  = strtotime( $window['from'] . ' 12:00:00' );
        $month     = $month_ts !== false ? (string) wp_date( 'F Y', $month_ts ) : $window['from'];

        $plan['ok']        = true;
        $plan['stop']      = false;
        $plan['team_name'] = $team_name;
        $plan['filename']  = self::filename( $team_name, $composition['team_id'], $window['from'] );
        $plan['label']     = sprintf(
            /* translators: 1: team name, 2: month and year the report covers */
            __( 'Monthly report %1$s, %2$s', 'talenttrack' ),
            $team_name,
            $month
        );
        return $plan;
    }

    /**
     * The plan plus the PDF.
     *
     * @param array<string,mixed> $schedule
     * @return array{ok:bool, stop:bool, error:string, bytes:string, filename:string, label:string}
     */
    public static function render( array $schedule, string $today ): array {
        $plan = self::plan( $schedule, $today );
        $out  = [ 'ok' => false, 'stop' => $plan['stop'], 'error' => $plan['error'], 'bytes' => '', 'filename' => $plan['filename'], 'label' => $plan['label'] ];
        if ( ! $plan['ok'] ) return $out;

        $c = $plan['composition'];
        try {
            $report = ( new TeamMonthlyReport() )->forTeam( $c['team_id'], $plan['window']['from'], $plan['window']['to'], $c['blocks'], $plan['owner'] );
            $payload = TeamMonthlyReportPdfExporter::payload( $report, $c['layout'], $plan['team_name'] );

            $request = new ExportRequest( 'team_monthly_report_pdf', 'pdf', CurrentClub::id(), $plan['owner'], null, [
                'team_id' => $c['team_id'],
                'from'    => $plan['window']['from'],
                'to'      => $plan['window']['to'],
                'layout'  => $c['layout'],
                'blocks'  => $c['blocks'],
            ] );
            $bytes = ( new PdfRenderer() )->render( $request, $payload )->bytes;
        } catch ( \Throwable $e ) {
            $out['stop']  = false;
            $out['error'] = __( 'The PDF could not be created this time. The schedule will try again next month.', 'talenttrack' );
            \TT\Infrastructure\Logging\Logger::error( 'Team monthly schedule: render failed.', [
                'schedule_id' => (int) ( $schedule['id'] ?? 0 ),
                'error'       => $e->getMessage(),
            ] );
            return $out;
        }

        if ( $bytes === '' ) {
            $out['stop']  = false;
            $out['error'] = __( 'The PDF could not be created this time. The schedule will try again next month.', 'talenttrack' );
            return $out;
        }

        $out['ok']    = true;
        $out['bytes'] = $bytes;
        return $out;
    }

    /**
     * `JO13-1-2026-09.pdf`: the team and the month, so a folder of them sorts
     * and reads without opening any.
     */
    public static function filename( string $team_name, int $team_id, string $from ): string {
        $stem = sanitize_file_name( str_replace( ' ', '-', trim( $team_name ) ) );
        if ( $stem === '' ) $stem = 'team-' . $team_id;
        return $stem . '-' . substr( $from, 0, 7 ) . '.pdf';
    }
}
