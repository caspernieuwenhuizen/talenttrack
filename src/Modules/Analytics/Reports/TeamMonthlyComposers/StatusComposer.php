<?php
namespace TT\Modules\Analytics\Reports\TeamMonthlyComposers;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Squad status roll-up (#4590): green / amber / red / unknown counts now, and
 * the split as it stood at the end of the previous window.
 */
final class StatusComposer {

    /** @return array<string,mixed> */
    public static function compose( TeamReportContext $ctx ): array {
        $previous = $ctx->previous;
        $has_prev = count( $ctx->activitiesIn( $previous['from'], $previous['to'] ) ) > 0;
        return [
            'counts'   => TeamReportFigures::statusCounts( $ctx->verdictsAt( $ctx->to ) ),
            'previous' => $has_prev ? TeamReportFigures::statusCounts( $ctx->verdictsAt( $previous['to'] ) ) : null,
        ];
    }
}
