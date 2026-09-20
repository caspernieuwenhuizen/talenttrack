<?php
namespace TT\Modules\Trials\Services;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Config\ConfigService;
use TT\Infrastructure\Query\QueryHelpers;

/**
 * TrialDecisionDeadline (#3801) — how long is left to decide a trial.
 *
 * Three surfaces need the same answer and none of them should derive it:
 * the case REST read, the banner on the case screen, and the
 * `trials.decision_due_soon` alert. Before this class the answer did not
 * exist anywhere — nothing in the product compared `end_date` against
 * `decision IS NULL`, which is why a case could end tomorrow in silence.
 *
 * The lead time is `alerts_trial_decision_due_days` in `tt_config`,
 * defaulting to three days, on the same pattern as the evaluation-window
 * warning. An academy running two-week trials wants a shorter warning than
 * one running six-week ones.
 *
 * A case that is already decided is never due: the deadline exists to make
 * somebody decide, and there is nothing left to do once they have.
 */
final class TrialDecisionDeadline {

    /** tt_config key holding the lead time, in days. */
    public const CONFIG_KEY_LEAD_DAYS = 'alerts_trial_decision_due_days';

    private const DEFAULT_LEAD_DAYS = 3;

    /**
     * Days from today until the trial window closes.
     *
     * Negative when the window has already closed — the caller needs that
     * distinction, because "ends in two days" and "ended a week ago with
     * nobody deciding" are different sentences.
     */
    public static function daysRemaining( string $end_date ): ?int {
        $date = QueryHelpers::usableDate( $end_date );
        if ( $date === null ) return null;
        $ts = strtotime( $date );
        if ( $ts === false ) return null;
        $today = strtotime( (string) current_time( 'Y-m-d' ) );
        if ( $today === false ) return null;

        return (int) round( ( $ts - $today ) / DAY_IN_SECONDS );
    }

    /** The configured lead time in days, floored at 1. */
    public static function leadDays(): int {
        $value = ( new ConfigService() )->getInt( self::CONFIG_KEY_LEAD_DAYS, self::DEFAULT_LEAD_DAYS );
        return $value > 0 ? $value : self::DEFAULT_LEAD_DAYS;
    }

    /**
     * Is this case close enough to its end date to warrant saying so?
     *
     * An undecided case whose window has already closed stays due — the
     * warning does not stop being true because the date went past, and a
     * case that quietly ran out is precisely the one somebody needs to
     * hear about.
     */
    public static function isDueSoon( ?string $end_date, ?string $decision ): bool {
        if ( $decision !== null && trim( (string) $decision ) !== '' ) return false;
        $days = self::daysRemaining( (string) $end_date );
        if ( $days === null ) return false;

        return $days <= self::leadDays();
    }
}
