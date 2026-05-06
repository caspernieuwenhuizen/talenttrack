<?php
namespace TT\Modules\Trials;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * TrialGroupTeam (#0081 child 4) — per-club trial-group pseudo-team.
 *
 * The trial group is a rolling-membership team a prospect-on-a-trial-case
 * attends weekly while their case is in `continue_in_trial_group` state.
 * Representing it as a regular `tt_teams` row with `team_kind =
 * 'trial_group'` lets it surface alongside academy teams in admin lists
 * + the HoD landing's team grid without adding a parallel surface.
 *
 * Membership is queried *via the trial case*, not via `tt_team_people`:
 * a player is in the trial-group team when they have an active
 * `tt_trial_cases` row with `decision = 'continue_in_trial_group'`.
 * Avoids the schema impedance with `tt_team_people` (which is
 * person-keyed, not player-keyed) and keeps the trial case as the
 * single source of truth for "who's on the trial track."
 */
final class TrialGroupTeam {

    public const TEAM_KIND = 'trial_group';

    /**
     * Ensure the per-club trial-group team for the given age group
     * exists. Returns its id. Idempotent.
     */
    public static function ensure( ?string $age_group = null ): int {
        global $wpdb;
        $club_id = CurrentClub::id();
        $age_group = $age_group !== null ? trim( $age_group ) : '';

        $name = $age_group !== ''
            ? sprintf( /* translators: %s: age group label, e.g. "U13" */ __( 'Trial group %s', 'talenttrack' ), $age_group )
            : __( 'Trial group', 'talenttrack' );

        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}tt_teams
              WHERE club_id = %d AND team_kind = %s AND age_group = %s
              LIMIT 1",
            $club_id, self::TEAM_KIND, $age_group
        ) );
        if ( $existing ) return (int) $existing;

        $wpdb->insert( $wpdb->prefix . 'tt_teams', [
            'club_id'   => $club_id,
            'name'      => $name,
            'age_group' => $age_group,
            'team_kind' => self::TEAM_KIND,
        ] );
        return (int) $wpdb->insert_id;
    }

    /**
     * Count players currently on the trial track for a given age group
     * (or all age groups if null). A player is on the trial track when
     * they have an open trial case with decision `continue_in_trial_group`
     * — the rolling-membership marker.
     */
    public static function activeMemberCount( ?string $age_group = null ): int {
        global $wpdb;
        $club_id = CurrentClub::id();

        if ( $age_group !== null && $age_group !== '' ) {
            $sql = $wpdb->prepare(
                "SELECT COUNT(DISTINCT tc.player_id)
                   FROM {$wpdb->prefix}tt_trial_cases tc
                   JOIN {$wpdb->prefix}tt_players pl ON pl.id = tc.player_id
                   LEFT JOIN {$wpdb->prefix}tt_teams t ON t.id = pl.team_id
                  WHERE tc.club_id = %d
                    AND tc.archived_at IS NULL
                    AND tc.decision = %s
                    AND COALESCE(t.age_group, '') = %s",
                $club_id, 'continue_in_trial_group', $age_group
            );
        } else {
            $sql = $wpdb->prepare(
                "SELECT COUNT(DISTINCT tc.player_id)
                   FROM {$wpdb->prefix}tt_trial_cases tc
                  WHERE tc.club_id = %d
                    AND tc.archived_at IS NULL
                    AND tc.decision = %s",
                $club_id, 'continue_in_trial_group'
            );
        }
        return (int) $wpdb->get_var( $sql );
    }
}
