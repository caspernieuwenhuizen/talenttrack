<?php
namespace TT\Modules\Vct\Rules\Providers;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * NativeRecentPicksProvider — production wrapper that reads exercise
 * picks directly from `tt_vct_sessions` joined to `tt_vct_session_blocks`.
 *
 * Returns the distinct exercise_ids the team has used in any session
 * (any status) within $lookback_days, ordered by recency. The
 * ExerciseSelectionPass uses this list to bias selection AWAY from
 * recently used exercises (variety score).
 */
class NativeRecentPicksProvider implements RecentPicksProvider {

    public function recentExerciseIds( int $team_id, int $lookback_days ): array {
        if ( $team_id <= 0 ) return [];
        global $wpdb;
        $sessions = $wpdb->prefix . 'tt_vct_sessions';
        $blocks   = $wpdb->prefix . 'tt_vct_session_blocks';
        $cutoff   = gmdate( 'Y-m-d', strtotime( '-' . max( 1, $lookback_days ) . ' days' ) );

        $rows = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT b.exercise_id
               FROM {$blocks} b
               JOIN {$sessions} s ON s.id = b.vct_session_id
              WHERE b.club_id = %d
                AND s.team_id = %d
                AND s.session_date >= %s
                AND b.exercise_id IS NOT NULL
              ORDER BY s.session_date DESC, b.sequence ASC",
            CurrentClub::id(), $team_id, $cutoff
        ) );
        if ( ! is_array( $rows ) ) return [];
        return array_values( array_unique( array_map( 'intval', $rows ) ) );
    }
}
