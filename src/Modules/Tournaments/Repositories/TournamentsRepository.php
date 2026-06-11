<?php
namespace TT\Modules\Tournaments\Repositories;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * TournamentsRepository — #1324 minimal repository for the activity
 * ↔ tournament linking surface. Today only carries
 * `listForTeamPicker()` (the narrow shape the activity-edit picker
 * needs). The fuller tournament CRUD lives in
 * `TournamentsRestController` and will graduate here as the
 * extraction continues per #1320's per-surface pattern.
 *
 * Scope: this repository is intentionally narrow. The picker exposes
 * id + name + dates only — no schema visibility leaks to coaches
 * who hold `tt_edit_activities` but not `tt_view_tournaments` (v1
 * admin-only per #0093). Caller code that needs deeper tournament
 * fields goes through `TournamentsRestController` instead.
 */
final class TournamentsRepository {

    /**
     * Narrow list of tournaments for a team, scoped to the current
     * club. Used by the activity-edit picker so a coach can link
     * their activity to an existing tournament without holding
     * `tt_view_tournaments`. Archived tournaments are excluded.
     *
     * Order: most-recent first by `start_date DESC` so an upcoming
     * tournament-day picker surfaces this season's planned events
     * before historical ones.
     *
     * @return list<object> Each row: id, name, start_date, end_date.
     */
    public function listForTeamPicker( int $team_id ): array {
        global $wpdb;
        if ( $team_id <= 0 ) return [];
        $p = $wpdb->prefix;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, name, start_date, end_date
               FROM {$p}tt_tournaments
              WHERE team_id = %d
                AND club_id = %d
                AND archived_at IS NULL
              ORDER BY start_date DESC, id DESC
              LIMIT 100",
            $team_id, CurrentClub::id()
        ) );
        return is_array( $rows ) ? $rows : [];
    }
}
