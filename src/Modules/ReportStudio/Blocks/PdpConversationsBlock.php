<?php
namespace TT\Modules\ReportStudio\Blocks;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Analytics\Reports\PlayerReportAudience;
use TT\Modules\Pdp\Services\PdpPlanningService;
use TT\Modules\ReportStudio\Domain\ReportBlock;
use TT\Modules\ReportStudio\Domain\ReportContext;
use TT\Modules\ReportStudio\Domain\SharedReads;

/**
 * PDP conversations held against planned (#4572) — one team's row of the PDP
 * planning matrix (`PdpPlanningService::buildMatrix()`) for the current
 * season, block by block: the squad expected, the talks planned, held and
 * marked as not held, and each block's window. It follows the season's
 * blocks, not the report's period, as the planning page and
 * `GET /pdp-planning` do.
 *
 * Gated on the planning read (`tt_view_pdp_planning` and the teams
 * `PdpPlanningService::accessibleTeamIds()` allows), the gate of that page
 * and route. Staff only.
 */
final class PdpConversationsBlock implements ReportBlock {

    public function key(): string {
        return 'pdp_conversations';
    }

    public function label(): string {
        return __( 'PDP conversations held against planned', 'talenttrack' );
    }

    public function scopes(): array {
        return [ ReportContext::SCOPE_TEAM ];
    }

    public function audiences(): array {
        return [ PlayerReportAudience::INTERNAL ];
    }

    public function capability(): string {
        return 'tt_view_pdp_planning';
    }

    public function options( ReportContext $context ): array {
        return [];
    }

    public function available( ReportContext $context ): bool {
        if ( $context->scope !== ReportContext::SCOPE_TEAM ) return false;
        $teams = PdpPlanningService::accessibleTeamIds( $context->userId );
        if ( $context->teamId <= 0 ) return $teams !== [];
        return $teams === null || in_array( $context->teamId, $teams, true );
    }

    public function externalKeys(): array {
        return [];
    }

    public function render( ReportContext $context, array $options ): array {
        $season_id = PdpPlanningService::currentSeasonId();
        $matrix    = SharedReads::remember(
            'pdp_planning_matrix',
            $context,
            static fn(): array => PdpPlanningService::buildMatrix( $season_id, [ $context->teamId ] )
        );

        $team = is_array( $matrix['teams'][ $context->teamId ] ?? null ) ? $matrix['teams'][ $context->teamId ] : [];
        $rows = [];
        foreach ( (array) ( $team['blocks'] ?? [] ) as $sequence => $cell ) {
            if ( ! is_array( $cell ) ) continue;
            $rows[] = [
                'block'        => (int) $sequence,
                'window_start' => (string) ( $cell['window_start'] ?? '' ),
                'window_end'   => (string) ( $cell['window_end'] ?? '' ),
                'expected'     => (int) ( $cell['expected'] ?? 0 ),
                'planned'      => (int) ( $cell['planned'] ?? 0 ),
                'conducted'    => (int) ( $cell['conducted'] ?? 0 ),
                'not_held'     => (int) ( $cell['not_held'] ?? 0 ),
            ];
        }
        usort( $rows, static fn( array $a, array $b ): int => $a['block'] <=> $b['block'] );

        return [
            'breakdown' => 'block',
            'rows'      => $rows,
            'season_id' => $season_id,
        ];
    }
}
