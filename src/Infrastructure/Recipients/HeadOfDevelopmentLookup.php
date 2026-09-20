<?php
namespace TT\Infrastructure\Recipients;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * HeadOfDevelopmentLookup — the academy's heads of development (#3811).
 *
 * A handful of notifications are addressed to "this team's staff and the
 * head of development": the absence flag says so in its own code comment,
 * and has fired at club administrators since it shipped because nothing
 * could name the role.
 *
 * The head of development is not assigned per team — they hold an
 * academy-wide authorization scope — so this cannot come out of
 * {@see TeamStaffLookup}, which answers a per-team question. Two lookups
 * rather than one class that quietly means both.
 *
 * RESOLUTION
 *
 * An active `head_of_development` grant in `tt_user_role_scopes`, joined out
 * to the person's WP account. Active means the same thing it means in
 * `AuthorizationRepository::getActiveScopesForPerson()` — a grant with a
 * future start date or a past end date is not a grant — so a stand-in who
 * covered last season is not still being copied in.
 *
 * Club-scoped, because this one can be: the grant table carries `club_id`.
 */
final class HeadOfDevelopmentLookup {

    /** The authorization role key. Seeded by `Activator`, never renamed. */
    private const ROLE_KEY = 'head_of_development';

    /**
     * WP user ids of the club's heads of development.
     *
     * Ordinarily one person; the query does not assume it, because an
     * academy mid-handover has two and telling only the earlier one is the
     * failure mode this exists to avoid.
     *
     * @return list<int>
     */
    public static function forClub( ?int $club_id = null ): array {
        global $wpdb;

        $p       = $wpdb->prefix;
        $club_id = $club_id !== null && $club_id > 0 ? $club_id : CurrentClub::id();
        $today   = current_time( 'Y-m-d' );

        $rows = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT pe.wp_user_id
               FROM {$p}tt_user_role_scopes urs
         INNER JOIN {$p}tt_roles r ON r.id = urs.role_id AND r.club_id = urs.club_id
         INNER JOIN {$p}tt_people pe ON pe.id = urs.person_id
              WHERE r.role_key = %s
                AND urs.club_id = %d
                AND pe.wp_user_id > 0
                AND ( urs.start_date IS NULL OR urs.start_date <= %s )
                AND ( urs.end_date IS NULL OR urs.end_date >= %s )",
            self::ROLE_KEY,
            $club_id,
            $today,
            $today
        ) );

        $out = [];
        foreach ( is_array( $rows ) ? $rows : [] as $id ) {
            $id = (int) $id;
            if ( $id > 0 ) $out[] = $id;
        }

        return array_values( array_unique( $out ) );
    }
}
