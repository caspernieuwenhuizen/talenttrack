<?php
namespace TT\Modules\ReportStudio\Blocks;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Goals\GoalsRepository;
use TT\Modules\Analytics\Reports\PlayerReportAudience;
use TT\Modules\ReportStudio\Domain\ReportBlock;
use TT\Modules\ReportStudio\Domain\FamilySection;
use TT\Modules\ReportStudio\Domain\ReportContext;

/**
 * Goals completed (#4572), as they stand now: one player's completed goals,
 * through `GoalsRepository::countCompletedForPlayer()`, the read behind the
 * player's "goals completed" KPI. Not period-bound, and there is no team
 * read, so it is a player block only. Gated on the goals read, as open goals
 * are; on the family list, off the scout's.
 */
final class GoalsCompletedBlock implements ReportBlock, FamilySection {

    public function key(): string {
        return 'goals_completed';
    }

    public function label(): string {
        return __( 'Goals completed so far', 'talenttrack' );
    }

    public function scopes(): array {
        return [ ReportContext::SCOPE_PLAYER ];
    }

    public function audiences(): array {
        return [ PlayerReportAudience::INTERNAL, PlayerReportAudience::FAMILY ];
    }

    public function familySection(): string {
        return \TT\Modules\Analytics\Reports\PlayerReportBlock::GOALS;
    }

    public function capability(): string {
        return 'tt_view_reports';
    }

    public function options( ReportContext $context ): array {
        return [];
    }

    public function available( ReportContext $context ): bool {
        return $context->scope === ReportContext::SCOPE_PLAYER && OpenGoalsBlock::readsGoals( $context );
    }

    public function externalKeys(): array {
        return [ 'value', 'unit', 'measure_label' ];
    }

    public function render( ReportContext $context, array $options ): array {
        return [
            'value'         => ( new GoalsRepository() )->countCompletedForPlayer( $context->playerId ),
            'unit'          => '',
            'measure_label' => __( 'Goals completed so far', 'talenttrack' ),
        ];
    }
}
