<?php
namespace TT\Modules\Export\Exporters;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Analytics\Reports\PlayerReport;
use TT\Modules\Analytics\Reports\PlayerReportAccess;
use TT\Modules\Analytics\Reports\PlayerReportComposition;
use TT\Modules\Analytics\Reports\PlayerReportLayout;
use TT\Modules\Export\Domain\ExportRequest;
use TT\Modules\Export\ExportException;
use TT\Modules\Export\ExporterInterface;
use TT\Modules\Export\ScopeGatedExporter;

/**
 * PlayerReportPdfExporter (#3874, epic #3871) — the player report on paper, in
 * the layout and with the sections the panel chose.
 *
 * URL:
 *   `GET /wp-json/talenttrack/v1/exports/player_report_pdf?format=pdf
 *        &player_id=42&layout=A&blocks=status,talking_points,ratings`
 *   `period`, or `from` + `to` (Y-m-d); neither means the season so far.
 *
 * The page count the panel shows is the page count this prints: both read
 * `PlayerReportLayout::fit()`, and the shortening it chose is applied here
 * before the template sees the data.
 *
 * Access is the report's own rule, `PlayerReportAccess::canRead()`, and the
 * report is composed for the requester, so every block's own gate — thread
 * notes, injuries, journey, tests, the development plan — holds on paper too.
 */
final class PlayerReportPdfExporter implements ExporterInterface, ScopeGatedExporter {

    public function key(): string { return 'player_report_pdf'; }

    public function label(): string { return __( 'Player report (PDF)', 'talenttrack' ); }

    public function supportedFormats(): array { return [ 'pdf' ]; }

    public function requiredCap(): string { return 'tt_view_reports'; }

    /** Non-tabular exporter — opts out of the column picker. */
    public function availableColumns(): array { return []; }

    /**
     * Coarse gate: any `reports` read at all. Which player is checked in
     * `collect()`, where the player id is known.
     */
    public function isAvailableFor( int $user_id ): bool {
        if ( class_exists( '\\TT\\Modules\\Authorization\\MatrixGate' ) ) {
            return \TT\Modules\Authorization\MatrixGate::canAnyScope( $user_id, 'reports', 'read' );
        }
        return user_can( $user_id, 'tt_view_reports' );
    }

    /**
     * Forgiving like the panel it prints: unknown sections drop and a missing
     * window is the season so far. A document that refuses to print because a
     * saved view names a section that was renamed is worse than one without it.
     *
     * @param array<string,mixed> $raw
     * @return array<string,mixed>|null
     */
    public function validateFilters( array $raw ): ?array {
        // #3890 — a snapshot is self-describing: it carries its own player,
        // window, layout and sections, so nothing else on the request is read.
        $snapshot = isset( $raw['snapshot'] ) ? sanitize_text_field( (string) $raw['snapshot'] ) : '';
        if ( $snapshot !== '' ) return [ 'snapshot' => $snapshot ];

        $player_id = isset( $raw['player_id'] ) ? absint( $raw['player_id'] ) : 0;
        if ( $player_id <= 0 ) return null;

        $composition = PlayerReportComposition::normalise( [
            'player_id' => $player_id,
            'period'    => $raw['period'] ?? '',
            'from'      => $raw['from'] ?? '',
            'to'        => $raw['to'] ?? '',
            'blocks'    => $raw['blocks'] ?? [],
        ] );
        $window = PlayerReportComposition::window( $composition, gmdate( 'Y-m-d' ) );

        $layout = strtoupper( isset( $raw['layout'] ) ? (string) $raw['layout'] : '' );
        if ( ! PlayerReportLayout::isValid( $layout ) ) $layout = PlayerReportLayout::DEFAULT;

        return [
            'player_id' => $player_id,
            'from'      => $window['from'],
            'to'        => $window['to'],
            'layout'    => $layout,
            'blocks'    => $composition['blocks'],
        ];
    }

    public function collect( ExportRequest $request ): array {
        // #3890 — a snapshot prints what it stored, notes included, and answers
        // to its own player, never to one named in the request.
        $uuid = (string) ( $request->filters['snapshot'] ?? '' );
        if ( $uuid !== '' ) {
            $snapshot = \TT\Modules\Analytics\Reports\PlayerReportSnapshots::read( $uuid, $request->requesterUserId );
            if ( $snapshot === null ) {
                throw new ExportException( 'forbidden', __( 'You do not have access to this snapshot.', 'talenttrack' ) );
            }
            return self::payload( $snapshot['report'], (string) ( $snapshot['composition']['layout'] ?? PlayerReportLayout::DEFAULT ), $snapshot['notes'] );
        }

        $player_id = (int) ( $request->filters['player_id'] ?? 0 );
        if ( ! PlayerReportAccess::canRead( $request->requesterUserId, $player_id ) ) {
            throw new ExportException( 'forbidden', __( 'You do not have access to a report on this player.', 'talenttrack' ) );
        }

        $report = ( new PlayerReport() )->forPlayer(
            $player_id,
            (string) ( $request->filters['from'] ?? '' ),
            (string) ( $request->filters['to'] ?? '' ),
            array_values( array_map( 'strval', (array) ( $request->filters['blocks'] ?? [] ) ) ),
            $request->requesterUserId
        );
        if ( $report === null ) {
            throw new ExportException( 'bad_filters', __( 'Player not found.', 'talenttrack' ) );
        }

        return self::payload( $report, (string) ( $request->filters['layout'] ?? PlayerReportLayout::DEFAULT ) );
    }

    /**
     * The renderer payload for a composed report. Split from `collect()` so a
     * test can lay a known report onto paper without a database.
     *
     * @param array{data:array<string,array<string,mixed>>, blocks:list<string>, from:string, to:string} $report
     * @param array<string,array{body:string, author:int, updated_at:string}> $notes
     *        a snapshot's section notes, so print and screen agree (#3890).
     * @return array{html:string, options:array{paper:string, orientation:string}}
     */
    public static function payload( array $report, string $layout, array $notes = [] ): array {
        $layout = PlayerReportLayout::isValid( $layout ) ? $layout : PlayerReportLayout::DEFAULT;
        $fit    = PlayerReportLayout::fit( $report, $layout );
        $report['data'] = PlayerReportLayout::degrade( $report, $fit['degraded'] )['data'];

        return [
            'html'    => PlayerReportPdfDocument::html( $report, $notes ),
            'options' => [ 'paper' => 'A4', 'orientation' => 'portrait' ],
        ];
    }
}
