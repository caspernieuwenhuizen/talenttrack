<?php
namespace TT\Modules\Export\Exporters;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Analytics\Reports\TeamMonthlyReport;
use TT\Modules\Analytics\Reports\TeamMonthlyReportBlock;
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

        return [
            'team_id' => $team_id,
            'from'    => $from,
            'to'      => $to,
            'layout'  => $layout,
            'blocks'  => array_values( array_unique( $blocks ) ),
        ];
    }

    public function collect( ExportRequest $request ): array {
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

        $report = ( new TeamMonthlyReport() )->forTeam(
            $team_id,
            (string) $request->filters['from'],
            (string) $request->filters['to'],
            array_values( array_map( 'strval', $blocks ) ),
            $request->requesterUserId
        );

        return self::payload( $report, $layout, (string) ( $team->name ?? '' ) );
    }

    /**
     * The renderer payload for a composed report. Split from `collect()` so a
     * test can lay a known report onto paper without a database.
     *
     * @param array{data:array<string,array<string,mixed>>, blocks:list<string>, from:string, to:string} $report
     * @return array{html:string, options:array{paper:string, orientation:string}}
     */
    public static function payload( array $report, string $layout, string $team_name ): array {
        $layout = TeamMonthlyReportLayout::isValid( $layout ) ? $layout : TeamMonthlyReportLayout::DEFAULT;
        $fit    = TeamMonthlyReportLayout::fit( $report, $layout );
        $report['data'] = TeamMonthlyReportLayout::degrade( $report, $fit['degraded'] )['data'];

        return [
            'html'    => TeamMonthlyReportPdfDocument::html( $report, $layout, $team_name ),
            'options' => [
                'paper'       => 'A4',
                'orientation' => $layout === TeamMonthlyReportLayout::MATRIX ? 'landscape' : 'portrait',
            ],
        ];
    }
}
