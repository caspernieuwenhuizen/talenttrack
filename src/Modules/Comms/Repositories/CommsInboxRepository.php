<?php
namespace TT\Modules\Comms\Repositories;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * CommsInboxRepository (#2605, Gate D) — the read side of
 * `tt_comms_inbox`.
 *
 * `InappChannelAdapter` has written this table since v3.110.0 and nothing
 * has ever read it, so every in-app message sent so far has been
 * delivered into a room with no door. This is the door.
 *
 * Every method takes the recipient's user id explicitly and puts it in
 * the WHERE clause. There is no "all inboxes" read: a parent's messages
 * about their own child are the most sensitive rows the module holds, and
 * the way to guarantee no cross-family leak is for the query never to be
 * capable of one.
 */
final class CommsInboxRepository {

    public const MAX_PER_PAGE = 100;

    private string $table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'tt_comms_inbox';
    }

    /**
     * One page of a user's own messages, newest first.
     *
     * @return list<object>
     */
    public function forUser( int $user_id, bool $unread_only = false, int $page = 1, int $per_page = 25 ): array {
        if ( $user_id <= 0 ) return [];
        global $wpdb;

        $per_page = max( 1, min( self::MAX_PER_PAGE, $per_page ) );
        $offset   = ( max( 1, $page ) - 1 ) * $per_page;
        $unread   = $unread_only ? ' AND read_at IS NULL' : '';

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, uuid, created_at, recipient_player_id, template_key, message_type,
                    subject, body, payload_json, read_at
               FROM {$this->table}
              WHERE recipient_user_id = %d AND club_id = %d{$unread}
              ORDER BY created_at DESC, id DESC
              LIMIT %d OFFSET %d",
            $user_id, CurrentClub::id(), $per_page, $offset
        ) );
        return is_array( $rows ) ? $rows : [];
    }

    public function countForUser( int $user_id, bool $unread_only = false ): int {
        if ( $user_id <= 0 ) return 0;
        global $wpdb;
        $unread = $unread_only ? ' AND read_at IS NULL' : '';
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table}
              WHERE recipient_user_id = %d AND club_id = %d{$unread}",
            $user_id, CurrentClub::id()
        ) );
    }

    public function unreadCount( int $user_id ): int {
        return $this->countForUser( $user_id, true );
    }

    /**
     * One message, but only if it belongs to this user.
     */
    public function findForUser( int $id, int $user_id ): ?object {
        if ( $id <= 0 || $user_id <= 0 ) return null;
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, uuid, created_at, recipient_player_id, template_key, message_type,
                    subject, body, payload_json, read_at
               FROM {$this->table}
              WHERE id = %d AND recipient_user_id = %d AND club_id = %d
              LIMIT 1",
            $id, $user_id, CurrentClub::id()
        ) );
        return $row ?: null;
    }

    /**
     * Stamp (or clear) `read_at` on one of this user's messages.
     *
     * Reading is idempotent on purpose: the first stamp stands, so a
     * second GET from a second device does not rewrite when the message
     * was first opened. Marking unread clears it outright — that is the
     * user deliberately undoing the stamp, not a repeat view.
     *
     * @return bool False when the row is not this user's, or the write failed.
     */
    public function setRead( int $id, int $user_id, bool $read ): bool {
        $row = $this->findForUser( $id, $user_id );
        if ( $row === null ) return false;

        if ( $read && $row->read_at !== null && (string) $row->read_at !== '' ) return true;

        global $wpdb;
        // No format arrays: `read_at` is either a datetime string or a
        // genuine NULL, and wpdb only writes NULL when it is left to infer.
        return $wpdb->update(
            $this->table,
            [ 'read_at' => $read ? current_time( 'mysql' ) : null ],
            [ 'id' => $id, 'recipient_user_id' => $user_id, 'club_id' => CurrentClub::id() ]
        ) !== false;
    }
}
