<?php
namespace TT\Modules\Analytics\Reports\TeamMonthlyComposers;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Analytics\Reports\TeamMonthlyEvaluations;
use TT\Modules\Analytics\Reports\TeamMonthlyReportBlock;

/**
 * The evaluations section (#4590), over {@see TeamMonthlyEvaluations}, with the
 * squad and the previous window taken from the context.
 */
final class EvaluationsComposer {

    /** @return array<string,mixed> */
    public static function compose( TeamReportContext $ctx ): array {
        return TeamMonthlyEvaluations::compose(
            $ctx->team_id,
            $ctx->from,
            $ctx->to,
            $ctx->previous,
            $ctx->players(),
            $ctx->optionsFor( TeamMonthlyReportBlock::EVALUATIONS )
        );
    }
}
