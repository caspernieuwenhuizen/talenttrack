<?php
namespace TT\Modules\Vct\Rules;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Vct\Rules\Providers\ActivitiesReader;
use TT\Modules\Vct\Repositories\VctTeamSchedulesRepository;

/**
 * MdContextRule — Pass 2.
 *
 * Resolves the match-day context for the session_date:
 *   - U10/U11 (md_logic_enabled = false) → always `NONE`
 *   - Otherwise: look up the team's next match within 14 days forward
 *     (covers MD-4 through MD-1, MD itself) and the previous match
 *     within 7 days back (covers MD+1, MD+2). Whichever match is
 *     closer (forward or back) anchors the context.
 *
 * `VctTeamSchedulesRepository` is read but currently informational —
 * the engine doesn't use the weekday bitmask to alter MD resolution;
 * future cadence-aware logic (e.g. mid-week cup match disambiguation)
 * will consume it.
 */
class MdContextRule implements RulePass {

    private ActivitiesReader $activities;
    private VctTeamSchedulesRepository $team_schedules;

    public function __construct( ActivitiesReader $activities, VctTeamSchedulesRepository $team_schedules ) {
        $this->activities     = $activities;
        $this->team_schedules = $team_schedules;
    }

    public function apply( SessionPlanContext $ctx ): SessionPlanContext {
        if ( ! $ctx->md_logic_enabled ) {
            $ctx->md_context = 'NONE';
            return $ctx;
        }

        $session_ts = strtotime( $ctx->session_date );
        if ( $session_ts === false ) {
            $ctx->md_context = 'NONE';
            return $ctx;
        }

        // Look 14 days forward, 7 back. The 7-back window keeps MD+1/MD+2
        // anchored without pulling in matches from previous microcycles.
        $forward_end  = gmdate( 'Y-m-d', $session_ts + 14 * 86400 );
        $backward_end = gmdate( 'Y-m-d', $session_ts -  7 * 86400 );

        $next_match = $this->activities->nextMatchDate(
            $ctx->team_id,
            $ctx->session_date,
            $forward_end
        );
        $prev_match = $this->activities->previousMatchDate(
            $ctx->team_id,
            $backward_end,
            $ctx->session_date
        );

        $ctx->md_context = $this->resolveContext( $session_ts, $next_match, $prev_match );
        return $ctx;
    }

    private function resolveContext( int $session_ts, ?string $next_match, ?string $prev_match ): string {
        $session_date  = gmdate( 'Y-m-d', $session_ts );
        $forward_days  = $next_match !== null ? $this->daysBetween( $session_date, $next_match ) : null;
        $backward_days = $prev_match !== null ? $this->daysBetween( $prev_match, $session_date ) : null;

        // Same-day match wins (MD).
        if ( $forward_days === 0 ) return 'MD';

        // Otherwise: prefer the closer side. If post-match window (1-2
        // days) is closer or equal to pre-match, surface MD+N. This
        // gives recovery priority — coaches expect MD+1 to feel like
        // recovery even when the next match is also close.
        if ( $backward_days !== null && $backward_days <= 2 ) {
            switch ( $backward_days ) {
                case 1: return 'MD+1';
                case 2: return 'MD+2';
            }
        }

        if ( $forward_days !== null ) {
            switch ( $forward_days ) {
                case 1: return 'MD-1';
                case 2: return 'MD-2';
                case 3: return 'MD-3';
                case 4: return 'MD-4';
            }
        }

        return 'NONE';
    }

    private function daysBetween( string $earlier, string $later ): ?int {
        $a = strtotime( $earlier );
        $b = strtotime( $later );
        if ( $a === false || $b === false ) return null;
        return (int) floor( ( $b - $a ) / 86400 );
    }
}
