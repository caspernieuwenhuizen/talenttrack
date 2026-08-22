<?php
namespace TT\Modules\Knowledge;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * KnowledgePerson — the `tt_people` row behind a WordPress login.
 *
 * Every enrolment is keyed on `person_id`, never `wp_user_id`: the
 * WordPress user is one authentication backend, and the identity that has
 * to survive a SaaS migration is the person (CLAUDE.md §4). Somebody has
 * to do that translation, and doing it in three views and a REST
 * controller is how the four drift.
 *
 * A login with no person record is a real state, not an error — an
 * administrator account that was never linked to a staff record. Those
 * users can read the library; they simply have nothing to record progress
 * against, and the views say so rather than failing.
 */
final class KnowledgePerson {

    /** @var array<int, int> memo, per request */
    private static array $memo = [];

    /**
     * The person id for a WordPress user, or 0 when the login is not
     * linked to one.
     */
    public static function forUser( int $user_id ): int {
        if ( $user_id <= 0 ) {
            return 0;
        }

        if ( isset( self::$memo[ $user_id ] ) ) {
            return self::$memo[ $user_id ];
        }

        global $wpdb;

        $person_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}tt_people
              WHERE wp_user_id = %d AND club_id = %d AND archived_at IS NULL
              ORDER BY id LIMIT 1",
            $user_id,
            CurrentClub::id()
        ) );

        self::$memo[ $user_id ] = $person_id;

        return $person_id;
    }

    /** The person id for whoever is logged in, or 0. */
    public static function current(): int {
        return self::forUser( get_current_user_id() );
    }

    /** Tests mutate people rows; the memo must not outlive them. */
    public static function flush(): void {
        self::$memo = [];
    }
}
