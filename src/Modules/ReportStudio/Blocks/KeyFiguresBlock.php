<?php
namespace TT\Modules\ReportStudio\Blocks;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Analytics\Reports\PlayerReportAudience;
use TT\Modules\Analytics\Reports\TeamMonthlyComposers\KpiComposer;
use TT\Modules\Analytics\Reports\TeamMonthlyComposers\TeamReportContext;
use TT\Modules\ReportStudio\Domain\ReportBlock;
use TT\Modules\ReportStudio\Domain\ReportContext;

/**
 * Key figures with the change since the previous period (#4572) — the team
 * monthly report's headline tiles, read through its KPI composer over
 * `TeamMonthlyReport::previousWindow()`: activities, squad attendance, the
 * median minutes share, evaluation coverage, the squad rating and how many
 * players need attention.
 *
 * Team scope only: there is no player version of these figures. Staff only,
 * because "need attention" is a judgement about players.
 */
final class KeyFiguresBlock implements ReportBlock {

    public function key(): string {
        return 'key_figures';
    }

    public function label(): string {
        return _x( 'Key figures', 'report studio block', 'talenttrack' );
    }

    public function scopes(): array {
        return [ ReportContext::SCOPE_TEAM ];
    }

    public function audiences(): array {
        return [ PlayerReportAudience::INTERNAL ];
    }

    public function capability(): string {
        return 'tt_view_reports';
    }

    public function options( ReportContext $context ): array {
        return [];
    }

    public function available( ReportContext $context ): bool {
        return $context->scope === ReportContext::SCOPE_TEAM;
    }

    public function externalKeys(): array {
        return [];
    }

    public function render( ReportContext $context, array $options ): array {
        $ctx  = new TeamReportContext( $context->teamId, $context->from, $context->to, $context->userId );
        $kpi  = KpiComposer::compose( $ctx );
        $pts  = _x( 'pts', 'percentage points', 'talenttrack' );

        // The monthly report's tiles, in its order and with its labels.
        $tiles = [
            'activities'               => [ __( 'Activities', 'talenttrack' ), '', '' ],
            'attendance_pct'           => [ __( 'Attendance', 'talenttrack' ), 'percent', $pts ],
            'minutes_share_median_pct' => [ __( 'Median minutes share', 'talenttrack' ), 'percent', $pts ],
            'evaluated'                => [ __( 'Evaluated', 'talenttrack' ), 'count_of', $pts ],
            'squad_rating'             => [ __( 'Squad rating', 'talenttrack' ), '', '' ],
            'needs_attention'          => [ __( 'Need attention', 'talenttrack' ), '', '' ],
        ];

        $figures = [];
        foreach ( $tiles as $key => [ $label, $unit, $delta_unit ] ) {
            $m = is_array( $kpi[ $key ] ?? null ) ? $kpi[ $key ] : [];
            $figures[] = [
                'key'        => $key,
                'label'      => $label,
                'value'      => $m['value'] ?? null,
                'of'         => $key === 'evaluated' ? (int) ( $m['of'] ?? 0 ) : null,
                'unit'       => $unit,
                'delta'      => $m['delta'] ?? null,
                'delta_unit' => $delta_unit,
            ];
        }

        return [
            'figures'  => $figures,
            'previous' => $ctx->previous,
        ];
    }
}
