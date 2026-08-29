<?php
namespace TT\Shared\Sharing;

use TT\Infrastructure\Tenancy\CurrentClub;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * ShareViewQuery (#3096) — how many people opened a share link, and when
 * the last of them did.
 *
 * The counting lives here rather than in the view (CLAUDE.md §4): the
 * rendered page and `GET /match-analyses/{id}/share-views` must not be able
 * to disagree, and the only way to guarantee that is for both to ask the
 * same object.
 *
 * The answer is the sum of two things — the visitor rows still held, and
 * what the 90-day purge folded into `tt_share_view_totals` before deleting
 * them. Reading only the live rows would make the number walk backwards on
 * a Tuesday for no reason a coach could see.
 */
final class ShareViewQuery {

    /**
     * @return array{unique:int, opens:int, last_seen_at:?string}
     */
    public function summaryFor( string $subject_type, int $subject_id ): array {
        $empty = [ 'unique' => 0, 'opens' => 0, 'last_seen_at' => null ];
        if ( $subject_type === '' || $subject_id <= 0 ) return $empty;

        global $wpdb;
        $club   = CurrentClub::id();
        $views  = $wpdb->prefix . 'tt_share_views';
        $totals = $wpdb->prefix . 'tt_share_view_totals';

        $live = $wpdb->get_row( $wpdb->prepare(
            "SELECT COUNT(*) AS uniques, COALESCE(SUM(open_count),0) AS opens, MAX(last_seen_at) AS last_seen
               FROM {$views}
              WHERE club_id = %d AND subject_type = %s AND subject_id = %d",
            $club,
            $subject_type,
            $subject_id
        ), ARRAY_A );

        $archived = $wpdb->get_row( $wpdb->prepare(
            "SELECT archived_unique, archived_opens, last_seen_at
               FROM {$totals}
              WHERE club_id = %d AND subject_type = %s AND subject_id = %d",
            $club,
            $subject_type,
            $subject_id
        ), ARRAY_A );

        $unique = (int) ( $live['uniques'] ?? 0 ) + (int) ( $archived['archived_unique'] ?? 0 );
        $opens  = (int) ( $live['opens'] ?? 0 ) + (int) ( $archived['archived_opens'] ?? 0 );

        $last = '';
        foreach ( [ (string) ( $live['last_seen'] ?? '' ), (string) ( $archived['last_seen_at'] ?? '' ) ] as $candidate ) {
            if ( $candidate !== '' && $candidate > $last ) $last = $candidate;
        }

        return [
            'unique'       => $unique,
            'opens'        => $opens,
            'last_seen_at' => $last !== '' ? $last : null,
        ];
    }

    /**
     * Fold rows older than `$days` into the per-subject totals, then delete
     * them.
     *
     * Fold first, delete second, and never the other way round: a crash
     * between the two over-counts by one purge, which is a number slightly
     * too high. The opposite order loses the readers entirely, which is the
     * number walking backwards that the totals table exists to prevent.
     *
     * @return int rows deleted
     */
    public function purgeOlderThan( int $days, string $now ): int {
        global $wpdb;
        $club   = CurrentClub::id();
        $views  = $wpdb->prefix . 'tt_share_views';
        $totals = $wpdb->prefix . 'tt_share_view_totals';

        $cutoff = gmdate( 'Y-m-d H:i:s', strtotime( $now . ' -' . max( 1, $days ) . ' days' ) );

        $stale = $wpdb->get_results( $wpdb->prepare(
            "SELECT subject_type, subject_id,
                    COUNT(*) AS uniques,
                    COALESCE(SUM(open_count),0) AS opens,
                    MAX(last_seen_at) AS last_seen
               FROM {$views}
              WHERE club_id = %d AND last_seen_at < %s
              GROUP BY subject_type, subject_id",
            $club,
            $cutoff
        ), ARRAY_A );

        if ( ! is_array( $stale ) || $stale === [] ) return 0;

        foreach ( $stale as $row ) {
            $wpdb->query( $wpdb->prepare(
                "INSERT INTO {$totals}
                    (club_id, subject_type, subject_id, archived_unique, archived_opens, last_seen_at)
                 VALUES (%d, %s, %d, %d, %d, %s)
                 ON DUPLICATE KEY UPDATE
                    archived_unique = archived_unique + VALUES(archived_unique),
                    archived_opens  = archived_opens + VALUES(archived_opens),
                    last_seen_at    = GREATEST(COALESCE(last_seen_at, '1970-01-01 00:00:00'), VALUES(last_seen_at))",
                $club,
                (string) $row['subject_type'],
                (int) $row['subject_id'],
                (int) $row['uniques'],
                (int) $row['opens'],
                (string) $row['last_seen']
            ) );
        }

        $deleted = $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$views} WHERE club_id = %d AND last_seen_at < %s",
            $club,
            $cutoff
        ) );

        return is_int( $deleted ) ? $deleted : 0;
    }
}
