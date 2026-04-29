<?php
namespace TT\Modules\Threads;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * ThreadReadsRepository — last-read timestamps per (user, thread) (#0028).
 *
 * Used by the goal list to render an unread-count badge and by the
 * thread renderer to draw the "Unread since [time]" divider.
 */
final class ThreadReadsRepository {

    public function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'tt_thread_reads';
    }

    public function markRead( int $user_id, string $thread_type, int $thread_id ): void {
        global $wpdb;
        $thread_type = sanitize_key( $thread_type );
        $now = current_time( 'mysql', true );
        // Upsert: try insert; on duplicate, update the timestamp.
        $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$this->table()} (user_id, thread_type, thread_id, club_id, last_read_at)
             VALUES (%d, %s, %d, %d, %s)
             ON DUPLICATE KEY UPDATE last_read_at = VALUES(last_read_at)",
            $user_id, $thread_type, $thread_id, CurrentClub::id(), $now
        ) );
    }

    public function lastReadAt( int $user_id, string $thread_type, int $thread_id ): ?string {
        global $wpdb;
        $row = $wpdb->get_var( $wpdb->prepare(
            "SELECT last_read_at FROM {$this->table()}
              WHERE user_id = %d AND thread_type = %s AND thread_id = %d",
            $user_id, sanitize_key( $thread_type ), $thread_id
        ) );
        return is_string( $row ) && $row !== '' ? $row : null;
    }

    public function unreadCount( int $user_id, string $thread_type, int $thread_id ): int {
        global $wpdb;
        $messages = $wpdb->prefix . 'tt_thread_messages';
        $last     = $this->lastReadAt( $user_id, $thread_type, $thread_id );
        if ( $last === null ) {
            // Never read — every message is unread.
            return (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$messages}
                  WHERE thread_type = %s AND thread_id = %d
                    AND club_id = %d AND deleted_at IS NULL
                    AND author_user_id <> %d",
                sanitize_key( $thread_type ), $thread_id, CurrentClub::id(), $user_id
            ) );
        }
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$messages}
              WHERE thread_type = %s AND thread_id = %d
                AND club_id = %d AND deleted_at IS NULL
                AND author_user_id <> %d
                AND created_at > %s",
            sanitize_key( $thread_type ), $thread_id, CurrentClub::id(), $user_id, $last
        ) );
    }

    /**
     * Batch unread counts for many threads of the same type — used by
     * the goal list to render badges in a single query.
     *
     * @param list<int> $thread_ids
     * @return array<int, int>  thread_id => unread count
     */
    public function unreadCountsForMany( int $user_id, string $thread_type, array $thread_ids ): array {
        if ( empty( $thread_ids ) ) return [];
        global $wpdb;
        $thread_type = sanitize_key( $thread_type );
        $messages    = $wpdb->prefix . 'tt_thread_messages';
        $reads       = $this->table();
        $thread_ids  = array_values( array_unique( array_map( 'intval', $thread_ids ) ) );
        $placeholders = implode( ',', array_fill( 0, count( $thread_ids ), '%d' ) );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql = "SELECT m.thread_id, COUNT(*) AS n
                  FROM {$messages} m
             LEFT JOIN {$reads} r
                       ON r.user_id = %d AND r.thread_type = m.thread_type AND r.thread_id = m.thread_id
                 WHERE m.thread_type = %s
                   AND m.thread_id IN ({$placeholders})
                   AND m.club_id = %d
                   AND m.deleted_at IS NULL
                   AND m.author_user_id <> %d
                   AND ( r.last_read_at IS NULL OR m.created_at > r.last_read_at )
              GROUP BY m.thread_id";
        $args = array_merge(
            [ $user_id, $thread_type ],
            $thread_ids,
            [ CurrentClub::id(), $user_id ]
        );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ) );
        $out = [];
        if ( is_array( $rows ) ) {
            foreach ( $rows as $r ) $out[ (int) $r->thread_id ] = (int) $r->n;
        }
        return $out;
    }
}
