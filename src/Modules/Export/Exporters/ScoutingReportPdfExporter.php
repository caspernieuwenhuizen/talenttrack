<?php
namespace TT\Modules\Export\Exporters;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Export\Domain\ExportRequest;
use TT\Modules\Export\ExporterInterface;
use TT\Modules\Reports\AudienceDefaults;
use TT\Modules\Reports\AudienceType;
use TT\Modules\Reports\PlayerReportRenderer;
use TT\Modules\Reports\PrivacySettings;
use TT\Modules\Reports\ReportConfig;

/**
 * ScoutingReportPdfExporter (#0063 use case 14) — formal scouting PDF.
 *
 * The third PDF use case in the family started by use case 1 (player
 * evaluation) and use case 2 (PDP). All three reuse `PlayerReportRenderer`
 * + the standard PDF wrap pipeline; the difference is the
 * `ReportConfig::audience` and the resulting `AudienceDefaults` —
 * scout audience is `[ profile, ratings ]` only with a `formal` tone
 * and the SCOUT privacy floor (no contact details, no full DOB, no
 * coach notes; photo opt-in stays as the renderer config decides).
 *
 * Fits the same shape as the existing `ScoutDelivery::emailLink()`
 * flow, which already builds a SCOUT-audience `ReportConfig` and
 * renders via `PlayerReportRenderer::render()`. This exporter exposes
 * that same artefact through the central Export module so callers
 * outside the email-the-link path can consume it (a "Save as PDF"
 * button in the scout-history view, a future "Generate cohort
 * scouting pack" batch action, an external-system integration that
 * polls for new reports).
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

        // Build a SCOUT-audience ReportConfig. AudienceDefaults gives
        // us the scope keyword + sections + privacy floor + tone; we
        // resolve the scope into concrete date_from/date_to and merge
        // any caller-supplied overrides so a scout export can still be
        // bounded to a tighter window (e.g. "this season only") when
        // the operator passes them.
        $defaults = AudienceDefaults::defaultsFor( AudienceType::SCOUT );
        $resolved = AudienceDefaults::resolveScope( (string) $defaults['scope'] );

        $renderer_filters = [
            'date_from'    => isset( $filters['date_from'] ) ? (string) $filters['date_from'] : $resolved['date_from'],
            'date_to'      => isset( $filters['date_to'] )   ? (string) $filters['date_to']   : $resolved['date_to'],
            'eval_type_id' => isset( $filters['eval_type_id'] ) ? (int) $filters['eval_type_id'] : 0,
        ];

        $config = new ReportConfig(
            AudienceType::SCOUT,
            $renderer_filters,
            (array) $defaults['sections'],
            $defaults['privacy'] instanceof PrivacySettings
                ? $defaults['privacy']
                : new PrivacySettings(),
            $player_id,
            (int) $request->requesterUserId,
            null,
            (string) $defaults['tone_variant']
        );

        $html = ( new PlayerReportRenderer() )->render( $config );

        // Same chart-script strip as the v3.110.4 player-eval exporter:
        // DomPDF can't run JS so the Chart.js boot block becomes dead
        // bytes that should not ship in the PDF.
        $html = self::stripScriptTags( $html );

        return [
            'html'    => $html,
            'options' => [ 'paper' => 'A4', 'orientation' => 'portrait' ],
        ];
    }

    private static function stripScriptTags( string $html ): string {
        $clean = preg_replace( '#<script\b[^>]*>.*?</script>#is', '', $html );
        return $clean === null ? $html : $clean;
    }
}
