<?php
namespace TT\Modules\StaffDevelopment\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * Tiny helper used by the five staff-development frontend views to
 * resolve the current WP user → `tt_people` row and to fetch the
 * current season id. Keeps the per-view code focused on render.
 */
final class StaffPersonHelper {

    public static function personForUser( int $user_id ): ?object {
        if ( $user_id <= 0 ) return null;
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tt_people
              WHERE wp_user_id = %d AND club_id = %d LIMIT 1",
            $user_id, CurrentClub::id()
        ) );
        return $row ?: null;
    }

    public static function currentSeasonId(): ?int {
        global $wpdb;
        $row = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}tt_seasons
              WHERE is_current = 1 AND club_id = %d LIMIT 1",
            CurrentClub::id()
        ) );
        return $row ? (int) $row : null;
    }
}
