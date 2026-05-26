<?php
namespace TT\Modules\Vct\Rules\Providers;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * NativeActivitiesReader — production implementation of ActivitiesReader.
 *
 * Reads from the Activities module's `tt_activities` table directly
 * (narrow query) rather than reaching into ActivitiesRepository's public
 * surface. Keeps VCT decoupled from Activities' internal class layout —
 * if Activities renames or relocates its repository, only this adapter
 * changes.
 *
 * "Match" detection looks for `activity_type` containing the word
 * `match` (covers `match`, `home_match`, `away_match`, etc.). The
 * Activities module's activity_type lookup vocabulary is the source of
 * truth; this adapter intentionally matches loosely so future
 * match-flavoured subtypes are picked up without code changes.
 */
class NativeActivitiesReader implements ActivitiesReader {

    public function nextMatchDate( int $team_id, string $window_start, string $window_end ): ?string {
        return $this->matchDate( $team_id, $window_start, $window_end, 'ASC' );
    }

    public function previousMatchDate( int $team_id, string $window_start, string $window_end ): ?string {
        return $this->matchDate( $team_id, $window_start, $window_end, 'DESC' );
    }

    private function matchDate( int $team_id, string $window_start, string $window_end, string $direction ): ?string {
        if ( $team_id <= 0 ) return null;
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $window_start ) ) return null;
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $window_end ) ) return null;
        $direction = $direction === 'DESC' ? 'DESC' : 'ASC';

        global $wpdb;
        $activities = $wpdb->prefix . 'tt_activities';
        $date = $wpdb->get_var( $wpdb->prepare(
            "SELECT session_date FROM {$activities}
              WHERE club_id = %d
                AND team_id = %d
                AND activity_type LIKE %s
                AND session_date BETWEEN %s AND %s
              ORDER BY session_date {$direction}
              LIMIT 1",
            CurrentClub::id(), $team_id, '%match%', $window_start, $window_end
        ) );
        return $date !== null ? (string) $date : null;
    }
}
