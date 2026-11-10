<?php
namespace TT\Modules\MatchExecution\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * ContributionsCeiling (#4506, #4994) — the most goals, and the most assists,
 * a match can plausibly hold.
 *
 * One read-only rule with two users: the write refusal in
 * `MatchExecutionRepository::contributionsProblem()`, and the goals and
 * assists drill-down, which flags a stored match over it. Neither carries a
 * threshold of its own.
 *
 *  - **a score is stored**: our score bounds each player's goals and assists,
 *    and the match's attributed goals and its assists added up;
 *  - **no score is stored**: {@see CAP_WITHOUT_SCORE} per player, goals and
 *    assists each, and no match total.
 */
final class ContributionsCeiling {

    /** No youth match legitimately needs more for one player. */
    public const CAP_WITHOUT_SCORE = 15;

    /** The ceiling for one count in a match with `$our_score` stored (null: none). */
    public static function limit( ?int $our_score ): int {
        return $our_score !== null ? max( 0, $our_score ) : self::CAP_WITHOUT_SCORE;
    }

    /** Whether one player's goals or assists in a match are over the ceiling. */
    public static function playerOver( ?int $our_score, int $count ): bool {
        return $count > self::limit( $our_score );
    }

    /**
     * Whether a match's goals or assists added up are over the ceiling. Only
     * a stored score bounds a total.
     */
    public static function matchOver( ?int $our_score, int $total ): bool {
        return $our_score !== null && $total > max( 0, $our_score );
    }
}
