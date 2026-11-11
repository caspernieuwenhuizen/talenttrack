<?php
namespace TT\Modules\ReportStudio\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Logging\Logger;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Analytics\Reports\ReportFilters;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Export\Domain\ExportRequest;
use TT\Modules\Export\Exporters\ReportStudioPdfExporter;
use TT\Modules\Export\Format\Renderers\PdfRenderer;
use TT\Modules\ReportStudio\Repositories\ReportsRepository;

/**
 * StudioScheduleDelivery (#4963, epic #4564) — a scheduled Report Studio
 * report, from the schedule row to the PDF that is mailed. The render
 * callback `ScheduledReportsRunner::runPdf()` calls, like the team monthly
 * report's and the player report's.
 *
 * ## What it sends
 *
 * The report's **published version**, read at send time: a draft edit made
 * after publishing is not in the PDF until it is published. The subject is
 * the report's own; the period is the report's, or the schedule's rolling one
 * when the report leaves it open (`ReportSchedules::window()`).
 *
 * It is composed once for the owner and every recipient together
 * (`ReportData::composeForReaders()`), so every block holds only what every
 * one of them may read. One PDF goes to everyone.
 *
 * ## When it stops
 *
 * This document names minors and goes out by email, unattended. So a
 * schedule pauses — with the reason on the schedules screen and one message
 * to its owner — rather than sending, when the report is gone, archived,
 * unpublished or no longer schedulable, when its subject is gone, or when the
 * owner may no longer change it. A failure that may pass on its own (too
 * many recipients this run, the PDF could not be rendered) is recorded but
 * does not pause.
 *
 * @phpstan-import-type Report from ReportsRepository
 */
final class StudioScheduleDelivery {

    /**
     * The report a Studio schedule points at, when it still exists here.
     *
     * @param array<string,mixed> $schedule
     * @return Report|null
     */
    public static function reportOf( array $schedule ): ?array {
        $composition = is_array( $schedule['composition'] ?? null ) ? $schedule['composition'] : [];
        $id          = isset( $composition['report_id'] ) && is_numeric( $composition['report_id'] ) ? (int) $composition['report_id'] : 0;
        return $id > 0 ? ( new ReportsRepository() )->find( $id ) : null;
    }

    /**
     * The reads the sent PDF is of, per player: what `runPdf()` records once
     * per recipient after the send, because the composition itself is quiet.
     * Each block that can carry injury or sensitive journey data says which
     * (`CarriesSensitiveReads`): a player report's injuries and journey
     * sections, a team report's "what changed". `$reader` is the owner, the
     * reader the document was composed as.
     *
     * @param array<string,mixed> $doc
     * @return array<string, array<int,int>> action => player id => entries
     */
    public static function sensitiveReads( array $doc, int $reader ): array {
        $context = is_array( $doc['context'] ?? null ) ? $doc['context'] : [];
        $out     = [];
        foreach ( is_array( $doc['blocks'] ?? null ) ? $doc['blocks'] : [] as $block ) {
            if ( ! is_array( $block ) || ! is_array( $block['data'] ?? null ) ) continue;
            $renderer = BlockRegistry::find( (string) ( $block['key'] ?? '' ) );
            if ( ! $renderer instanceof CarriesSensitiveReads ) continue;
            $data = [];
            foreach ( $block['data'] as $k => $v ) $data[ (string) $k ] = $v;
            foreach ( $renderer->sensitiveReads( $data, $context, $reader ) as $action => $counts ) {
                foreach ( $counts as $player => $n ) {
                    $out[ $action ][ $player ] = ( $out[ $action ][ $player ] ?? 0 ) + $n;
                }
            }
        }
        return $out;
    }

    /**
     * Does the schedule go to anyone but its owner?
     *
     * @param array<string,mixed> $schedule
     */
    public static function goesToOthers( array $schedule ): bool {
        $own = \TT\Modules\Analytics\Cron\ScheduledReportsRunner::ACCOUNT_PREFIX . (int) ( $schedule['created_by'] ?? 0 );
        return array_values( array_map( 'strval', (array) ( $schedule['recipients'] ?? [] ) ) ) !== [ $own ];
    }

    /**
     * Is the stored player or team still there, and not archived? A group
     * and the academy have no single subject to lose.
     *
     * @param Report $report
     */
    private static function subjectExists( array $report ): bool {
        if ( $report['scope'] === ReportContext::SCOPE_PLAYER ) {
            $player = $report['player_id'] !== null ? QueryHelpers::get_player( $report['player_id'] ) : null;
            return $player !== null && empty( $player->archived_at );
        }
        if ( $report['scope'] === ReportContext::SCOPE_TEAM ) {
            $team = $report['team_id'] !== null ? QueryHelpers::get_team( $report['team_id'] ) : null;
            return $team !== null && empty( $team->archived_at );
        }
        return true;
    }

    /**
     * The PDF for this run, or why there is none.
     *
     * @param array<string,mixed> $schedule a hydrated `ScheduledReportsRepository` row
     * @param array{recipients: list<\TT\Modules\Comms\Domain\Recipient>, via: array<int, string>, skipped: list<array{email: string, user_id: int, reason: string, via: string}>, unresolved: list<string>} $resolved
     *        `ScheduledReportsRunner::resolveRecipients()` for this run
     * @return array{ok:bool, stop:bool, error:string, bytes:string, filename:string, label:string, note?:string, reads?:array<string, array<int,int>>}
     */
    public static function render( array $schedule, string $today, array $resolved ): array {
        $out = [ 'ok' => false, 'stop' => true, 'error' => '', 'bytes' => '', 'filename' => '', 'label' => '' ];

        $report = self::reportOf( $schedule );
        $owner  = (int) ( $schedule['created_by'] ?? 0 );
        if ( $report === null ) {
            $out['error'] = __( 'Stopped: the report in this schedule no longer exists.', 'talenttrack' );
            return $out;
        }
        $refusal = ReportSchedules::refusal( $report );
        if ( $refusal !== null ) {
            $out['error'] = sprintf(
                /* translators: %s: why the report cannot be sent on a schedule */
                __( 'Stopped: %s', 'talenttrack' ),
                $refusal
            );
            return $out;
        }
        if ( ! self::subjectExists( $report ) ) {
            $out['error'] = __( 'Stopped: the player or team this report is about no longer exists.', 'talenttrack' );
            return $out;
        }
        $owner_reader = ReportReader::for( $owner );
        if ( ! ReportSchedules::canSchedule( $owner_reader, $report ) ) {
            $out['error'] = __( 'Stopped: the person who set up this schedule may no longer change this report.', 'talenttrack' );
            return $out;
        }
        // Who decides distribution is asked every run too: a schedule to
        // others needs the right to publish reports (epic decision 2).
        if ( self::goesToOthers( $schedule ) && ! $owner_reader->publishesReports() ) {
            $out['error'] = __( 'Stopped: the person who set up this schedule may no longer send reports to others.', 'talenttrack' );
            return $out;
        }
        if ( $report['sharing'] === ReportStartRules::SHARING_ME && self::goesToOthers( $schedule ) ) {
            $out['error'] = sprintf(
                /* translators: %s: why the report cannot be sent to others */
                __( 'Stopped: %s', 'talenttrack' ),
                ReportSchedules::keptForOwner()
            );
            return $out;
        }

        $out['stop'] = false;
        $composition = is_array( $schedule['composition'] ?? null ) ? $schedule['composition'] : [];
        $window      = ReportSchedules::window( $report, $composition, $today );
        if ( $window === null ) {
            $out['error'] = ReportFilters::noPeriodMessage();
            return $out;
        }

        // One window for the readers and the composition: every scope read
        // once for the set, the subject check once per reader.
        $composed = \TT\Infrastructure\Security\ScopeMemo::run( static function () use ( $resolved, $owner, $report, $window ): array {
            $readers = ReportSchedules::resolve( $resolved, $owner, $report )['readers'];
            if ( count( $readers ) > ReportSchedules::MAX_READERS ) return [ 'readers' => $readers, 'doc' => null ];
            return [ 'readers' => $readers, 'doc' => ReportSchedules::compose( $report, $window, $readers ) ];
        } );
        $doc = $composed['doc'];
        if ( $doc === null ) {
            $out['error'] = ReportSchedules::tooManyReaders();
            return $out;
        }
        if ( $doc instanceof StudioError ) {
            // A report that lost its subject or its publication stops; a
            // group nobody may see all of may change with the recipients.
            $out['stop']  = in_array( $doc->code, [ 'not_published', 'not_found' ], true ) || ( $doc->status === 400 && $doc->code === 'invalid_field' );
            $out['error'] = sprintf(
                /* translators: %s: why the report could not be composed */
                __( 'Not sent: %s', 'talenttrack' ),
                $doc->message
            );
            return $out;
        }

        // Nothing every recipient may read: say so, and mail nothing.
        if ( ( is_array( $doc['blocks'] ?? null ) ? $doc['blocks'] : [] ) === [] ) {
            $out['error'] = __( 'Not sent: no block of this report is one every recipient may read.', 'talenttrack' );
            return $out;
        }

        $name  = $report['name'] !== '' ? $report['name'] : __( 'Untitled report', 'talenttrack' );
        $label = sprintf(
            /* translators: 1: report name, 2: first day of the period, 3: last day of the period */
            __( '%1$s, %2$s to %3$s', 'talenttrack' ),
            $name,
            $window['from'],
            $window['to']
        );

        try {
            $payload = ReportStudioPdfExporter::documentPayload( $doc, $owner, $name );
            $request = new ExportRequest( 'report_studio_pdf', 'pdf', CurrentClub::id(), $owner, null, [
                'report_id' => $report['id'],
                'from'      => $window['from'],
                'to'        => $window['to'],
            ] );
            $bytes = ( new PdfRenderer() )->render( $request, $payload )->bytes;
        } catch ( \Throwable $e ) {
            $out['error'] = __( 'The PDF could not be created this time. The schedule will try again on its next run.', 'talenttrack' );
            Logger::error( 'Studio schedule: render failed.', [ 'schedule_id' => (int) ( $schedule['id'] ?? 0 ), 'error' => $e->getMessage() ] );
            return $out;
        }
        if ( $bytes === '' ) {
            $out['error'] = __( 'The PDF could not be created this time. The schedule will try again on its next run.', 'talenttrack' );
            return $out;
        }

        $out['ok']       = true;
        $out['bytes']    = $bytes;
        $out['filename'] = $payload['filename'];
        $out['label']    = $label;
        $out['reads']    = self::sensitiveReads( $doc, $owner );
        $left            = is_array( $doc['left_out'] ?? null ) ? $doc['left_out'] : [];
        if ( $left !== [] ) {
            $out['note'] = sprintf(
                /* translators: %s: comma-separated names of the blocks left out of the PDF */
                __( 'Left out so every recipient may read the PDF: %s.', 'talenttrack' ),
                implode( ', ', array_map( static fn( $l ): string => is_array( $l ) ? (string) ( $l['label'] ?? '' ) : '', $left ) )
            );
        }
        return $out;
    }
}
