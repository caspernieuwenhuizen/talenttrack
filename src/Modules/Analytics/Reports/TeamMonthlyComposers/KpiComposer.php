<?php
namespace TT\Modules\Analytics\Reports\TeamMonthlyComposers;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Teams\TeamKpisRepository;
use TT\Modules\Analytics\EvalCoverageService;

/**
 * The headline tiles, each against the previous window (#4590). A previous
 * window with nothing scheduled gives a null delta, never 0.
 */
final class KpiComposer {

    /** @return array<string,mixed> */
    public static function compose( TeamReportContext $ctx ): array {
        $previous = $ctx->previous;
        $kpis     = new TeamKpisRepository();
        $cover    = new EvalCoverageService();
        // Both windows counted the same way, or the delta compares a scheduled
        // count against a completed one (#3746).
        $prev_n   = count( $ctx->scheduledIn( $previous['from'], $previous['to'] ) );
        $has_prev = $prev_n > 0;

        // #4068 — the attendance section's own squad average, so the tile and
        // the table under it cannot disagree. Both windows the same way.
        $att_now  = TeamReportFigures::squadAttendancePct( $ctx->attendanceRowsIn( $ctx->from, $ctx->to ) );
        $att_prev = $has_prev ? TeamReportFigures::squadAttendancePct( $ctx->attendanceRowsIn( $previous['from'], $previous['to'] ) ) : null;

        $min_now  = TeamReportFigures::medianShare( $ctx->minutesIn( $ctx->from, $ctx->to ) );
        $min_prev = $has_prev ? TeamReportFigures::medianShare( $ctx->minutesIn( $previous['from'], $previous['to'] ) ) : null;

        $eval_now      = $cover->coverageBetween( $ctx->team_id, $ctx->from, $ctx->to );
        $eval_pct      = TeamReportFigures::pct( $eval_now['evaluated'], $eval_now['squad'] );
        $eval_prev_pct = null;
        if ( $has_prev ) {
            $eval_prev     = $cover->coverageBetween( $ctx->team_id, $previous['from'], $previous['to'] );
            $eval_prev_pct = TeamReportFigures::pct( $eval_prev['evaluated'], $eval_prev['squad'] );
        }

        $rating_now  = $kpis->avgSquadRatingBetween( $ctx->team_id, $ctx->from, $ctx->to );
        $rating_prev = $has_prev ? $kpis->avgSquadRatingBetween( $ctx->team_id, $previous['from'], $previous['to'] ) : null;

        $attention_now  = TeamReportFigures::attentionCount( $ctx->verdictsAt( $ctx->to ) );
        $attention_prev = $has_prev ? TeamReportFigures::attentionCount( $ctx->verdictsAt( $previous['to'] ) ) : null;

        return [
            'activities'               => TeamReportFigures::measure( count( $ctx->scheduledIn( $ctx->from, $ctx->to ) ), $has_prev ? $prev_n : null ),
            'attendance_pct'           => TeamReportFigures::measure( $att_now, $att_prev ),
            'minutes_share_median_pct' => TeamReportFigures::measure( $min_now, $min_prev ),
            'evaluated'                => [
                'value' => $eval_now['evaluated'],
                'of'    => $eval_now['squad'],
                'pct'   => $eval_pct,
                'delta' => TeamReportFigures::delta( $eval_pct, $eval_prev_pct ),
            ],
            'squad_rating'             => TeamReportFigures::measure(
                $rating_now !== null ? round( $rating_now, 1 ) : null,
                $rating_prev !== null ? round( $rating_prev, 1 ) : null
            ),
            'needs_attention'          => TeamReportFigures::measure( $attention_now, $attention_prev ),
        ];
    }
}
