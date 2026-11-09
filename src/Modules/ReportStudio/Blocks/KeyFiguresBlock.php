<?php
namespace TT\Modules\ReportStudio\Blocks;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Analytics\Reports\PlayerReportAudience;
use TT\Modules\Analytics\Reports\TeamMonthlyComposers\KpiComposer;
use TT\Modules\Analytics\Reports\TeamMonthlyReport;
use TT\Modules\Analytics\Reports\TeamMonthlyReportBlock;
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
        // #4949 — the monthly report's key figures as this reader may read
        // them: composed and filtered by the report itself, so the
        // attendance tile follows the attendance right here as it does on
        // the report, with no second check in the block.
        $report = ( new TeamMonthlyReport() )->forTeam( $context->teamId, $context->from, $context->to, [ TeamMonthlyReportBlock::KPI ], $context->userId );
        $kpi    = $report['data'][ TeamMonthlyReportBlock::KPI ] ?? [];

        // The monthly report's tiles, in its order and with its labels: one
        // list, the composer's.
        $figures = [];
        foreach ( KpiComposer::tiles() as $tile ) {
            if ( ! array_key_exists( $tile['key'], $kpi ) ) continue;
            $m = is_array( $kpi[ $tile['key'] ] ?? null ) ? $kpi[ $tile['key'] ] : [];
            $figures[] = [
                'key'        => $tile['key'],
                'label'      => $tile['label'],
                'value'      => $m['value'] ?? null,
                'of'         => $tile['unit'] === 'count_of' ? (int) ( $m['of'] ?? 0 ) : null,
                'unit'       => $tile['unit'],
                'delta'      => $m['delta'] ?? null,
                'delta_unit' => $tile['delta_unit'],
            ];
        }

        return [
            'figures'  => $figures,
            'previous' => $report['previous'],
        ];
    }
}
