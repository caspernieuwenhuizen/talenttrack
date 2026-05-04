<?php
namespace TT\Infrastructure\Query;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * PlayerFileCounts — single source for the player-file tab badge counts.
 *
 * One method, one round-trip per tab type, mirroring the existing
 * SELECT shapes used by the per-tab renderers in FrontendPlayerDetailView.
 * Result keys match the tab slugs so the view can index directly.
 */
final class PlayerFileCounts {

    /**
     * @return array{goals:int, evaluations:int, activities:int, pdp:int, trials:int}
     */
    public static function for( int $player_id ): array {
        global $wpdb;
        $p = $wpdb->prefix;

        $goals = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}tt_goals WHERE player_id = %d AND archived_at IS NULL",
            $player_id
        ) );
        $evaluations = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}tt_evaluations WHERE player_id = %d AND archived_at IS NULL",
            $player_id
        ) );
        $activities = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*)
               FROM {$p}tt_attendance att
               JOIN {$p}tt_activities a ON a.id = att.activity_id
              WHERE att.player_id = %d AND att.is_guest = 0 AND a.archived_at IS NULL",
            $player_id
        ) );
        $pdp = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}tt_pdp_files WHERE player_id = %d",
            $player_id
        ) );
        $trials = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}tt_trial_cases WHERE player_id = %d AND archived_at IS NULL",
            $player_id
        ) );

        return [
            'goals'       => $goals,
            'evaluations' => $evaluations,
            'activities'  => $activities,
            'pdp'         => $pdp,
            'trials'      => $trials,
        ];
    }
}
