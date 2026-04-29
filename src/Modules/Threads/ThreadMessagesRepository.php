<?php
namespace TT\Modules\Threads;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Threads\Domain\ThreadVisibility;

/**
 * ThreadMessagesRepository — CRUD on tt_thread_messages (#0028).
 *
 * Enforces:
 *   - 5-minute edit window (server-side, in `update()`).
 *   - Soft-delete only; original body wiped, deleted_by/at stamped.
 *   - Visibility filtering at read time (private_to_coach hidden from
 *     non-coach viewers).
 *   - club_id scoping per the SaaS-readiness rule.
 */
final class ThreadMessagesRepository {

    public const EDIT_WINDOW_SECONDS = 300;

    public function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'tt_thread_messages';
    }

    /**
     * @param array{thread_type:string, thread_id:int, author_user_id:int, body:string, visibility?:string, is_system?:int} $data
     * @return int message id (0 on failure)
     */
    public function insert( array $data ): int {
        global $wpdb;
        $row = [
            'club_id'         => CurrentClub::id(),
            'uuid'            => wp_generate_uuid4(),
            'thread_type'     => sanitize_key( (string) $data['thread_type'] ),
            'thread_id'       => (int) $data['thread_id'],
            'author_user_id'  => (int) $data['author_user_id'],
            'body'            => (string) $data['body'],
            'visibility'      => ThreadVisibility::isValid( (string) ( $data['visibility'] ?? '' ) )
                ? (string) $data['visibility']
                : ThreadVisibility::PUBLIC_LEVEL,
            'is_system'       => (int) ( $data['is_system'] ?? 0 ),
            'created_at'      => current_time( 'mysql', true ),
        ];
        $ok = $wpdb->insert( $this->table(), $row );
        return $ok ? (int) $wpdb->insert_id : 0;
    }

    public function find( int $id ): ?object {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE id = %d AND club_id = %d",
            $id, CurrentClub::id()
        ) );
        return $row ?: null;
    }

    /**
     * Fetch messages for a thread, oldest first, applying visibility
     * filtering. Pass `since_id` to fetch only newer rows (for the
     * 30-second polling path).
     *
     * @return list<object>
     */
    public function listForThread( string $thread_type, int $thread_id, bool $can_see_private, int $since_id = 0 ): array {
        global $wpdb;
        $thread_type = sanitize_key( $thread_type );
        $sql = "SELECT * FROM {$this->table()}
                 WHERE thread_type = %s
                   AND thread_id   = %d
                   AND club_id     = %d";
        $args = [ $thread_type, $thread_id, CurrentClub::id() ];
        if ( $since_id > 0 ) {
            $sql   .= " AND id > %d";
            $args[] = $since_id;
        }
        if ( ! $can_see_private ) {
            $sql .= " AND visibility = %s";
            $args[] = ThreadVisibility::PUBLIC_LEVEL;
        }
        $sql .= " ORDER BY created_at ASC, id ASC";
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ) );
        return is_array( $rows ) ? $rows : [];
    }

    /**
     * Edit body within the 5-minute window. Returns false when window
     * elapsed, message missing, or author mismatch.
     */
    public function update( int $id, int $author_user_id, string $body, ?string $visibility = null ): bool {
        $msg = $this->find( $id );
        if ( ! $msg ) return false;
        if ( (int) $msg->author_user_id !== $author_user_id ) return false;
        if ( ! $this->withinEditWindow( (string) $msg->created_at ) ) return false;
        if ( (int) $msg->is_system === 1 ) return false; // system messages are immutable

        global $wpdb;
        $update = [
            'body'      => $body,
            'edited_at' => current_time( 'mysql', true ),
        ];
        if ( $visibility !== null && ThreadVisibility::isValid( $visibility ) ) {
            $update['visibility'] = $visibility;
        }
        $ok = $wpdb->update( $this->table(), $update, [ 'id' => $id, 'club_id' => CurrentClub::id() ] );
        return (bool) $ok;
    }

    /**
     * Soft-delete: blank the body, stamp deleted_by/at. Author can do
     * this at any time; the controller permits admin override too.
     */
    public function softDelete( int $id, int $deleted_by ): bool {
        $msg = $this->find( $id );
        if ( ! $msg ) return false;
        if ( $msg->deleted_at !== null ) return true; // idempotent

        global $wpdb;
        $ok = $wpdb->update(
            $this->table(),
            [
                'body'       => __( 'Message deleted.', 'talenttrack' ),
                'deleted_at' => current_time( 'mysql', true ),
                'deleted_by' => $deleted_by,
            ],
            [ 'id' => $id, 'club_id' => CurrentClub::id() ]
        );
        return (bool) $ok;
    }

    public function withinEditWindow( string $created_at ): bool {
        $created_ts = strtotime( $created_at . ' UTC' );
        if ( $created_ts === false ) return false;
        return ( time() - $created_ts ) <= self::EDIT_WINDOW_SECONDS;
    }
}
