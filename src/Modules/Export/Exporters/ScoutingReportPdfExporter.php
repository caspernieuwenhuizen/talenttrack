<?php
namespace TT\Modules\Export\Exporters;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Export\Domain\ExportRequest;
use TT\Modules\Export\ExporterInterface;

/**
 * ScoutingReportPdfExporter (#0063 use case 14) — formal scouting PDF.
 *
 * #3876 — rendered through the player report engine for the scout audience,
 * the same composition `ScoutDelivery::emailLink()` stores and a scout reads on
 * their assigned-players view: letterhead, evaluation scores without the
 * coach's written notes, attendance, playing time and tests at the public
 * level. The engine's audience gate is on the payload, so nothing outside that
 * list reaches the PDF whatever the caller asks for.
 *
 * URL:
 *   `GET /wp-json/talenttrack/v1/exports/scouting_report_pdf?format=pdf&player_id=42`
 *   filters:
 *     `player_id`    (REQUIRED)
 *     `date_from`    (optional ISO date; defaults to scope='all_time')
 *     `date_to`      (optional ISO date)
 *     `eval_type_id` (optional positive int)
 *
 * Cap: `tt_generate_scout_report` — same gate as the scout-access view.
 *
 * Brand-kit letterhead (per spec note "on club letterhead") lands with
 * the brand-kit template-inheritance follow-up; consumers that need
 * letterhead today can hook the `tt_pdf_render_html` filter from
 * v3.110.0 to prepend their letterhead.
 */
final class ScoutingReportPdfExporter implements ExporterInterface {

    public function key(): string { return 'scouting_report_pdf'; }

    public function label(): string { return __( 'Scouting report (PDF)', 'talenttrack' ); }

    public function supportedFormats(): array { return [ 'pdf' ]; }

    public function requiredCap(): string { return 'tt_generate_scout_report'; }

    /** Non-tabular exporter — opts out of the column picker (#986). */
    public function availableColumns(): array { return []; }

    public function validateFilters( array $raw ): ?array {
        $player_id = isset( $raw['player_id'] ) ? (int) $raw['player_id'] : 0;
        if ( $player_id <= 0 ) return null;

        $filters = [ 'player_id' => $player_id ];

        if ( isset( $raw['date_from'] ) && $raw['date_from'] !== '' ) {
            $filters['date_from'] = (string) $raw['date_from'];
        }
        if ( isset( $raw['date_to'] ) && $raw['date_to'] !== '' ) {
            $filters['date_to'] = (string) $raw['date_to'];
        }
        if ( isset( $raw['eval_type_id'] ) ) {
            $eval_type_id = (int) $raw['eval_type_id'];
            if ( $eval_type_id > 0 ) $filters['eval_type_id'] = $eval_type_id;
        }

        return $filters;
    }

    public function collect( ExportRequest $request ): array {
        $filters   = $request->filters;
        $player_id = (int) ( $filters['player_id'] ?? 0 );

        // Tenant scope — `QueryHelpers::get_player()` scopes to the
        // current club; without this an authenticated cap-holder could
        // request a scouting report for a player in another club by
        // guessing the id.
        $player = QueryHelpers::get_player( $player_id );
        if ( ! $player ) {
            return [
                'html'    => '<p>' . esc_html__( 'Player not found.', 'talenttrack' ) . '</p>',
                'options' => [ 'paper' => 'A4', 'orientation' => 'portrait' ],
            ];
        }

        // #3876 — the player report's own access rule, as every other door to
        // the report uses. The capability alone used to open any player in
        // the club.
        if ( ! \TT\Modules\Analytics\Reports\PlayerReportAccess::canRead( (int) $request->requesterUserId, $player_id ) ) {
            throw new \TT\Modules\Export\ExportException( 'forbidden', __( 'You do not have access to a report on this player.', 'talenttrack' ) );
        }

        // The player report engine, composed for the scout audience on the
        // requester's behalf: the scout allowlist on the payload, scores
        // without the coach's notes, tests at the public level. A window the
        // caller passes still bounds it; otherwise the season so far.
        $from = (string) ( $filters['date_from'] ?? '' );
        $to   = (string) ( $filters['date_to'] ?? '' );
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) || $from > $to ) {
            $window = \TT\Modules\Analytics\Reports\ReportFilters::seasonDefaultWindow();
            $from   = $window['from'];
            $to     = $window['to'];
        }

        $report = ( new \TT\Modules\Analytics\Reports\PlayerReport() )->forPlayer(
            $player_id,
            $from,
            $to,
            [],
            (int) $request->requesterUserId,
            \TT\Modules\Analytics\Reports\PlayerReportAudience::SCOUT
        );
        if ( $report === null ) {
            throw new \TT\Modules\Export\ExportException( 'bad_filters', __( 'Player not found.', 'talenttrack' ) );
        }

        return PlayerReportPdfExporter::payload( $report, \TT\Modules\Analytics\Reports\PlayerReportLayout::ONE_PAGER );
    }
}
