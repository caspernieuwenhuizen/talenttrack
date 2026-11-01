<?php
namespace TT\Modules\Analytics\Reports\TeamMonthlyComposers;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Activities\Services\ActivityRegisterProgress;

/**
 * The confidence statement, over the window's scheduled activities (#4590).
 *
 * Three outcomes, counted separately because they are three different
 * things to fix (#3746):
 *
 *   - completed with a register — the evidence every other block divides by;
 *   - completed without one — it silently shrinks every denominator, which
 *     is why this block exists at all;
 *   - past its date and never marked completed — nobody closed the session,
 *     so there is no register to be missing yet. Reporting that as "no
 *     register" would send a coach to the wrong screen.
 *
 * A future-dated activity in the window is not yet due and counts as
 * neither gap.
 */
final class CoverageComposer {

    /**
     * @return array{scheduled:int, completed:int, with_register:int, missing:list<array{activity_id:int,title:string,date:string}>, never_closed:list<array{activity_id:int,title:string,date:string}>, state:string}
     */
    public static function compose( TeamReportContext $ctx ): array {
        $scheduled = $ctx->scheduledIn( $ctx->from, $ctx->to );
        $today     = (string) current_time( 'Y-m-d' );

        $closed       = [];
        $never_closed = [];
        foreach ( $scheduled as $a ) {
            if ( strtolower( trim( (string) ( $a->activity_status_key ?? '' ) ) ) === 'completed' ) {
                $closed[] = $a;
                continue;
            }
            $date = substr( (string) ( $a->session_date ?? '' ), 0, 10 );
            if ( $date !== '' && $date <= $today ) $never_closed[] = TeamReportFigures::activityRef( $a );
        }

        ActivityRegisterProgress::prime( $closed );

        $with    = 0;
        $missing = [];
        foreach ( $closed as $a ) {
            $progress = ActivityRegisterProgress::forRow( $a );
            if ( $progress === null ) continue;
            if ( $progress['attendance']['recorded'] > 0 ) {
                $with++;
                continue;
            }
            $missing[] = TeamReportFigures::activityRef( $a );
        }
        $counted = $with + count( $missing );

        return [
            'scheduled'     => count( $scheduled ),
            'completed'     => $counted,
            'with_register' => $with,
            'missing'       => $missing,
            'never_closed'  => $never_closed,
            // "empty" means nothing was scheduled at all. A window full of
            // sessions nobody closed is the opposite of nothing to report.
            // "complete" is positive evidence, not merely the absence of a warning.
            'state'         => $scheduled === [] ? 'empty' : ( $missing === [] && $never_closed === [] ? 'complete' : 'partial' ),
        ];
    }
}
