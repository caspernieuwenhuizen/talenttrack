<?php
namespace TT\Modules\Export\Exporters;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Analytics\Reports\TeamMonthlyReport;
use TT\Modules\Analytics\Reports\TeamMonthlyReportBlock;
use TT\Modules\Analytics\Reports\TeamMonthlyReportComposition;
use TT\Modules\Analytics\Reports\TeamMonthlyReportLayout;
use TT\Modules\Analytics\Reports\TeamReportAccess;
use TT\Modules\Export\Domain\ExportRequest;
use TT\Modules\Export\ExportException;
use TT\Modules\Export\ExporterInterface;
use TT\Modules\Export\ScopeGatedExporter;

/**
 * TeamMonthlyReportPdfExporter (#3460, epic #3457) — the team monthly report on
 * paper, in the layout and with the sections the composition panel chose.
 *
 * URL:
 *   `GET /wp-json/talenttrack/v1/exports/team_monthly_report_pdf?format=pdf
 *        &team_id=12&period=last_month&layout=B&blocks=kpi,status,roster`
 *   `from` + `to` (Y-m-d) instead of `period` for a custom window.
 *
 * The page count the panel shows is the page count this prints: both read
 * `TeamMonthlyReportLayout::fit()`, and the degradation rungs it chose are
 * applied to the data here before the template sees it.
 *
 * Access is the same rule the on-screen report and its REST route answer to,
 * `TeamReportAccess::canRead()`. A coach of another team gets a refusal, not an
 * empty PDF — an empty PDF reads as "this team had a quiet month".
 */
final class TeamMonthlyReportPdfExporter implements ExporterInterface, ScopeGatedExporter {

    public function key(): string { return 'team_monthly_report_pdf'; }

    public function label(): string { return __( 'Team monthly report (PDF)', 'talenttrack' ); }

    public function supportedFormats(): array { return [ 'pdf' ]; }

    public function requiredCap(): string { return 'tt_view_reports'; }

    /** Non-tabular exporter — opts out of the column picker. */
    public function availableColumns(): array { return []; }

    /**
     * Coarse gate: any `reports` read at all. Which team is checked in
     * `collect()`, where the team id is known.
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
        // #3517 — a snapshot is self-describing: it carries its own team,
        // window, layout and sections, so nothing else on the request is read.
        // Whether the requester may see it is decided in collect().
        $snapshot = isset( $raw['snapshot'] ) ? sanitize_text_field( (string) $raw['snapshot'] ) : '';
        if ( $snapshot !== '' ) return [ 'snapshot' => $snapshot ];

        $team_id = isset( $raw['team_id'] ) ? absint( $raw['team_id'] ) : 0;
        if ( $team_id <= 0 ) return null;

        $from = isset( $raw['from'] ) ? (string) $raw['from'] : '';
        $to   = isset( $raw['to'] ) ? (string) $raw['to'] : '';
        $ymd  = '/^\d{4}-\d{2}-\d{2}$/';
        if ( ! preg_match( $ymd, $from ) || ! preg_match( $ymd, $to ) ) {
            $period = sanitize_key( isset( $raw['period'] ) ? (string) $raw['period'] : '' );
            $window = TeamMonthlyReport::periodWindow( $period !== '' ? $period : TeamMonthlyReport::DEFAULT_PERIOD, gmdate( 'Y-m-d' ) );
            if ( $window === null ) return null;
            $from = $window['from'];
            $to   = $window['to'];
        }
        if ( $from > $to ) return null;

        $layout = strtoupper( isset( $raw['layout'] ) ? (string) $raw['layout'] : '' );
        if ( ! TeamMonthlyReportLayout::isValid( $layout ) ) $layout = TeamMonthlyReportLayout::DEFAULT;

        // The panel is forgiving about a hand-edited block list, and so is its
        // PDF: unknown keys drop, the letterhead is added by the composer.
        $blocks = [];
        $list   = isset( $raw['blocks'] ) ? $raw['blocks'] : [];
        foreach ( is_array( $list ) ? $list : explode( ',', (string) $list ) as $key ) {
            $key = sanitize_key( trim( (string) $key ) );
            if ( TeamMonthlyReportBlock::isValid( $key ) ) $blocks[] = $key;
        }

        $blocks = array_values( array_unique( $blocks ) );

        // #3514 — per-block options, forgiving for the same reason the block
        // list above is: this filter set arrives from a hand-editable URL, a
        // saved view and a schedule's stored copy, and a report that refuses
        // to print because a test was deleted in March is worse than one that
        // prints without it.
        $options = TeamMonthlyReportComposition::normalise( [
            'blocks'  => $blocks,
            'options' => $raw['options'] ?? null,
        ] )['options'];

        return [
            'team_id' => $team_id,
            'from'    => $from,
            'to'      => $to,
            'layout'  => $layout,
            'blocks'  => $blocks,
            'options' => $options,
        ];
    }

    public function collect( ExportRequest $request ): array {
        // #3517 — a snapshot prints what it stored, notes included, so the PDF
        // handed round the table is the document on screen. The team comes
        // from the snapshot, never from the request: checking the requested
        // team_id would let a reader who may see team A print a snapshot of
        // team B by naming A in the filters.
        $snapshot = (string) ( $request->filters['snapshot'] ?? '' );
        if ( $snapshot !== '' ) {
            return $this->collectSnapshot( $snapshot, $request );
        }

        $team_id = (int) ( $request->filters['team_id'] ?? 0 );
        if ( ! TeamReportAccess::canRead( $request->requesterUserId, $team_id ) ) {
            throw new ExportException( 'forbidden', __( 'You do not have access to this team.', 'talenttrack' ) );
        }

        $team = QueryHelpers::get_team( $team_id );
        if ( $team === null ) {
            throw new ExportException( 'bad_filters', __( 'Team not found.', 'talenttrack' ) );
        }

        $layout = (string) ( $request->filters['layout'] ?? TeamMonthlyReportLayout::DEFAULT );
        $blocks = is_array( $request->filters['blocks'] ?? null ) ? $request->filters['blocks'] : [];

        $options = is_array( $request->filters['options'] ?? null ) ? $request->filters['options'] : [];

        $report = ( new TeamMonthlyReport() )->forTeam(
            $team_id,
            (string) $request->filters['from'],
            (string) $request->filters['to'],
            array_values( array_map( 'strval', $blocks ) ),
            $request->requesterUserId,
            $options
        );

        return self::payload( $report, $layout, (string) ( $team->name ?? '' ) );
    }

    /**
     * A frozen report on paper (#3517). No recompute: the stored payload is
     * laid out with the layout the snapshot was composed with.
     *
     * @return array{html:string, options:array{paper:string, orientation:string}}
     */
    private function collectSnapshot( string $uuid, ExportRequest $request ): array {
        $repo = new \TT\Modules\Analytics\Reports\TeamReportSnapshotRepository();
        $row  = $repo->find( $uuid );

        if ( $row === null || ! TeamReportAccess::canRead( $request->requesterUserId, (int) $row->team_id ) ) {
            throw new ExportException( 'forbidden', __( 'You do not have access to this snapshot.', 'talenttrack' ) );
        }

        $team        = QueryHelpers::get_team( (int) $row->team_id );
        $composition = \TT\Modules\Analytics\Reports\TeamReportSnapshotRepository::compositionOf( $row );

        return self::payload(
            \TT\Modules\Analytics\Reports\TeamReportSnapshotRepository::reportOf( $row ),
            (string) $composition['layout'],
            (string) ( $team->name ?? '' ),
            \TT\Modules\Analytics\Reports\TeamReportSnapshotRepository::notesOf( $row )
        );
    }

    /**
     * The renderer payload for a composed report. Split from `collect()` so a
     * test can lay a known report onto paper without a database.
     *
     * @param array{data:array<string,array<string,mixed>>, blocks:list<string>, from:string, to:string} $report
     * @param array<string,array{body:string, author:int, updated_at:string}> $notes
     *        a snapshot's section notes, so print and screen agree (#3517).
     * @return array{html:string, options:array{paper:string, orientation:string}}
     */
    public static function payload( array $report, string $layout, string $team_name, array $notes = [] ): array {
        $layout = TeamMonthlyReportLayout::isValid( $layout ) ? $layout : TeamMonthlyReportLayout::DEFAULT;
        $fit    = TeamMonthlyReportLayout::fit( $report, $layout );
        $report['data'] = TeamMonthlyReportLayout::degrade( $report, $fit['degraded'] )['data'];

        return [
            'html'    => TeamMonthlyReportPdfDocument::html( $report, $layout, $team_name, $notes ),
            'options' => [
                'paper'       => 'A4',
                'orientation' => $layout === TeamMonthlyReportLayout::MATRIX ? 'landscape' : 'portrait',
            ],
        ];
    }
}
