<?php
namespace TT\Modules\Auth;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WpUserUnlink (#1772) — clear TalentTrack account links when a WP user
 * is deleted.
 *
 * `tt_players.wp_user_id`, `tt_people.wp_user_id` and the
 * `tt_player_parents.parent_user_id` pivot all reference a `wp_users` id.
 * Without cleanup, deleting a WP user left dangling references: the
 * orphaned id could later be re-issued to a different person, silently
 * re-linking them to someone else's record — a safeguarding problem for
 * minors, and a hard-to-diagnose relinking bug.
 *
 * Hooked on `delete_user`, which fires BEFORE the row leaves `wp_users`,
 * so the cleanup runs while the id is still meaningful. The player /
 * person links are nulled (the records are real people, just unlinked);
 * the parent-pivot rows are deleted (a pivot row with no parent user is
 * meaningless). Not club-scoped: a WP user id is global to the install,
 * so every club's reference to it must be cleared.
 */
final class WpUserUnlink {

    public static function register(): void {
        add_action( 'delete_user', [ self::class, 'onDeleteUser' ] );
    }

    /**
     * @param int $user_id The WP user about to be deleted.
     */
    public static function onDeleteUser( int $user_id ): void {
        if ( $user_id <= 0 ) return;

        global $wpdb;
        $p = $wpdb->prefix;

        // Unlink players (keep the player record — it's a real player).
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$p}tt_players SET wp_user_id = NULL WHERE wp_user_id = %d",
            $user_id
        ) );

        // Unlink people (coaches/scouts/parents-as-people).
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$p}tt_people SET wp_user_id = NULL WHERE wp_user_id = %d",
            $user_id
        ) );

        // Remove the guardian→child pivot rows — a parent link with no
        // parent user is meaningless and would otherwise dangle.
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$p}tt_player_parents WHERE parent_user_id = %d",
            $user_id
        ) );
    }
}
