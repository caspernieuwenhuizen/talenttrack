<?php
namespace TT\Modules\Vct\Rules\Providers;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * RecentPicksProvider — VCT-owned interface returning exercise ids
 * recently picked for a team's sessions, so ExerciseSelectionPass can
 * score for variety vs. coach's history.
 *
 * Production implementation wraps VctSessionsRepository + its blocks;
 * tests inject a fake returning a fixed id list.
 */
interface RecentPicksProvider {

    /**
     * Return exercise ids the team has used in any session within the
     * trailing $lookback_days, deduplicated, ordered by most-recent first.
     *
     * Used by ExerciseSelectionPass to bias selection AWAY from recently
     * used exercises (variety score).
     *
     * @return list<int>
     */
    public function recentExerciseIds( int $team_id, int $lookback_days ): array;
}
