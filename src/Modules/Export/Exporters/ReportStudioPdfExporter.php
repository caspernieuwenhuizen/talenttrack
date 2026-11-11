<?php
namespace TT\Modules\Export\Exporters;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Analytics\Reports\PlayerReportAudience;
use TT\Modules\Analytics\Reports\ReportFilters;
use TT\Modules\Export\Domain\ExportRequest;
use TT\Modules\Export\ExportException;
use TT\Modules\Export\ExporterInterface;
use TT\Modules\Export\ScopeGatedExporter;
use TT\Modules\License\LicenseGate;
use TT\Modules\ReportStudio\Document\StudioDocument;
use TT\Modules\ReportStudio\Domain\ReportAccess;
use TT\Modules\ReportStudio\Domain\ReportData;
use TT\Modules\ReportStudio\Domain\ReportReader;
use TT\Modules\ReportStudio\Domain\ReportSnapshots;
use TT\Modules\ReportStudio\Repositories\ReportsRepository;

/**
 * ReportStudioPdfExporter (#5021, epic #4564) — a Report Studio report on
 * paper: the document the reader's view and the canvas's A4 view show.
 *
 * URL:
 *   `GET /wp-json/talenttrack/v1/exports/report_studio_pdf?format=pdf
 *        &report_id=12&source=published&player_id=42&period=last_month`
 *   `from` + `to` (Y-m-d) instead of `period`; `snapshot=<uuid>` for a frozen
 *   copy, which carries its own report, subject and period.
 *
 * The report is read for the requester through `ReportData::compose()`, the
 * route the screen reads, so the subject, the period, each block's access and
 * the audience cut hold on paper as they do on screen. A snapshot answers to
 * its own subject (`ReportSnapshots::read()`), never to the report.
 */
final class ReportStudioPdfExporter implements ExporterInterface, ScopeGatedExporter {

    public function key(): string { return 'report_studio_pdf'; }

    public function label(): string { return __( 'Report Studio report (PDF)', 'talenttrack' ); }

    public function supportedFormats(): array { return [ 'pdf' ]; }

    public function requiredCap(): string { return 'tt_view_reports'; }

    /** Non-tabular exporter — opts out of the column picker. */
    public function availableColumns(): array { return []; }

    /**
     * Coarse gate: any `reports` read at all, as the player report's PDF
     * asks, so staff who open a Studio snapshot on a player's file can print
     * it. The report, or the snapshot's subject, is checked in `collect()`.
     */
    public function isAvailableFor( int $user_id ): bool {
        if ( class_exists( '\\TT\\Modules\\Authorization\\MatrixGate' ) ) {
            return \TT\Modules\Authorization\MatrixGate::canAnyScope( $user_id, 'reports', 'read' );
        }
        return user_can( $user_id, 'tt_view_reports' );
    }

    /**
     * @param array<string,mixed> $raw
     * @return array<string,mixed>|null
     */
    public function validateFilters( array $raw ): ?array {
        $snapshot = isset( $raw['snapshot'] ) ? sanitize_text_field( (string) $raw['snapshot'] ) : '';
        if ( $snapshot !== '' ) return [ 'snapshot' => $snapshot ];

        $report_id = isset( $raw['report_id'] ) ? absint( $raw['report_id'] ) : 0;
        if ( $report_id <= 0 ) return null;

        $source = isset( $raw['source'] ) ? sanitize_key( (string) $raw['source'] ) : '';
        if ( ! in_array( $source, [ '', 'draft', 'published' ], true ) ) return null;

        $audience = isset( $raw['audience'] ) ? sanitize_key( (string) $raw['audience'] ) : '';
        if ( $audience !== '' && ! PlayerReportAudience::isValid( $audience ) ) return null;

        $from = isset( $raw['from'] ) ? (string) $raw['from'] : '';
        $to   = isset( $raw['to'] ) ? (string) $raw['to'] : '';
        $period = isset( $raw['period'] ) ? ReportFilters::sanitizePeriod( (string) $raw['period'] ) : '';
        if ( $period !== '' ) {
            $window = ReportFilters::periodWindow( $period, (string) current_time( 'Y-m-d' ) );
            if ( $window === null ) return null;
            $from = $window['from'];
            $to   = $window['to'];
        }
        $ymd = '/^\d{4}-\d{2}-\d{2}$/';
        if ( ( $from !== '' && ! preg_match( $ymd, $from ) ) || ( $to !== '' && ! preg_match( $ymd, $to ) ) ) return null;

        return [
            'report_id' => $report_id,
            'source'    => $source,
            'player_id' => isset( $raw['player_id'] ) && absint( $raw['player_id'] ) > 0 ? absint( $raw['player_id'] ) : null,
            'team_id'   => isset( $raw['team_id'] ) && absint( $raw['team_id'] ) > 0 ? absint( $raw['team_id'] ) : null,
            'from'      => $from,
            'to'        => $to,
            'audience'  => $audience,
        ];
    }

    public function collect( ExportRequest $request ): array {
        if ( ! LicenseGate::allows( 'report_studio' ) ) {
            throw new ExportException( 'forbidden', __( 'Report Studio is not part of your plan.', 'talenttrack' ) );
        }
        $user_id = $request->requesterUserId;

        $uuid = (string) ( $request->filters['snapshot'] ?? '' );
        if ( $uuid !== '' ) {
            $snapshot = ReportSnapshots::read( $uuid, $user_id );
            if ( $snapshot === null ) {
                throw new ExportException( 'forbidden', __( 'You do not have access to this snapshot.', 'talenttrack' ) );
            }
            return self::documentPayload( $snapshot['document'], $user_id, $snapshot['title'], $snapshot['notes'] );
        }

        $report = ( new ReportsRepository() )->find( (int) ( $request->filters['report_id'] ?? 0 ) );
        $reader = ReportReader::for( $user_id );
        // A live report is read in the Studio, which this reader must read.
        if ( $report === null || ! $reader->readsStudio() || ! ReportAccess::canRead( $reader, $report ) ) {
            throw new ExportException( 'forbidden', __( 'This report is not shared with you.', 'talenttrack' ) );
        }

        $filters = $request->filters;
        $id      = static fn( mixed $v ): ?int => is_numeric( $v ) && (int) $v > 0 ? (int) $v : null;
        $text    = static fn( mixed $v ): string => is_string( $v ) ? $v : '';
        $read    = ReportData::compose( $report, [
            'source'    => $text( $filters['source'] ?? '' ),
            'player_id' => $id( $filters['player_id'] ?? null ),
            'team_id'   => $id( $filters['team_id'] ?? null ),
            'from'      => $text( $filters['from'] ?? '' ),
            'to'        => $text( $filters['to'] ?? '' ),
            'audience'  => $text( $filters['audience'] ?? '' ),
        ], $reader );
        if ( $read instanceof \TT\Modules\ReportStudio\Domain\StudioError ) {
            throw new ExportException( $read->status === 403 ? 'forbidden' : 'bad_filters', $read->message );
        }

        $name = $report['name'] !== '' ? $report['name'] : __( 'Untitled report', 'talenttrack' );
        return self::documentPayload( $read, $user_id, $name );
    }

    /**
     * The renderer payload for a read report or a frozen one. Split from
     * `collect()` so a test can lay a known document onto paper.
     *
     * @param array<string,mixed> $document
     * @param array<string,array{body:string, author:int, updated_at:string}> $notes
     * @return array{html:string, options:array{paper:string, orientation:string}, filename:string}
     */
    public static function documentPayload( array $document, int $user_id, string $title, array $notes = [] ): array {
        $context = is_array( $document['context'] ?? null ) ? $document['context'] : [];
        $from    = is_string( $context['from'] ?? null ) ? $context['from'] : '';
        $to      = is_string( $context['to'] ?? null ) ? $context['to'] : '';
        return [
            'html'     => StudioDocument::html( $document, $user_id, $title, $notes ),
            'options'  => [ 'paper' => 'A4', 'orientation' => 'portrait' ],
            'filename' => sanitize_title( $title !== '' ? $title : 'report' ) . ( $from !== '' ? '-' . $from . '-' . $to : '' ) . '.pdf',
        ];
    }
}
