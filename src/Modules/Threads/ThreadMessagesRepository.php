<?php
namespace TT\Modules\Threads;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Threads\Domain\ThreadAccess;
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
     * #3396 — message counts for many threads of one type, in one query.
     *
     * The goals board needed a number per card and was calling
     * `listForThread()` once per goal, hydrating every message body to
     * `count()` the array. Same visibility rule, same answer, one round
     * trip and no bodies.
     *
     * #3672 — system messages are left out. "Goal created: …" renders in
     * the thread without an author header, so nobody reads it as a
     * message, and counting it made every freshly created goal advertise
     * a conversation that had not started. The thread itself still shows
     * it: `listForThread()` is deliberately untouched.
     *
     * @param  list<int> $thread_ids
     * @return array<int,int> thread_id => count, missing ids omitted.
     */
    public function countsForThreads( string $thread_type, array $thread_ids, bool $can_see_private ): array {
        $ids = array_values( array_unique( array_filter( array_map( 'intval', $thread_ids ) ) ) );
        if ( $ids === [] ) return [];

        global $wpdb;
        $thread_type  = sanitize_key( $thread_type );
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

        $sql  = "SELECT thread_id, COUNT(*) AS n FROM {$this->table()}
                  WHERE thread_type = %s
                    AND club_id     = %d
                    AND is_system   = 0
                    AND thread_id IN ({$placeholders})";
        $args = array_merge( [ $thread_type, CurrentClub::id() ], $ids );
        if ( ! $can_see_private ) {
            $sql   .= " AND visibility = %s";
            $args[] = ThreadVisibility::PUBLIC_LEVEL;
        }
        $sql .= " GROUP BY thread_id";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ) );
        $out  = [];
        foreach ( is_array( $rows ) ? $rows : [] as $row ) {
            $out[ (int) $row->thread_id ] = (int) $row->n;
        }
        return $out;
    }

    /**
     * #3858 — does moving a message from one visibility to another need
     * the staff-only right?
     *
     * Yes whenever `private_to_coach` is on either side of the move. Both
     * directions, one rule: hiding a note from a guardian and revealing
     * one to them are the same decision seen from two sides, and the
     * author who may not take the first may not take the second either.
     * A move that leaves the value alone needs nothing.
     */
    public static function visibilityChangeNeedsStaffOnlyRight( string $from, string $to ): bool {
        if ( $from === $to ) return false;
        return $from === ThreadVisibility::PRIVATE_COACH || $to === ThreadVisibility::PRIVATE_COACH;
    }

    /**
     * Edit body within the 5-minute window. Returns false when window
     * elapsed, message missing, author mismatch, or — #3858 — when the
     * author asked to change the staff-only flag without the right to.
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
            // #3858 — the entitlement check lives here as well as in the
            // controller, because this method accepted any valid value on
            // validity alone. Author and edit window were checked above;
            // whether the author was allowed to hide a note from a
            // guardian, or to reveal one to them, was checked nowhere.
            if ( self::visibilityChangeNeedsStaffOnlyRight( (string) $msg->visibility, $visibility )
                && ! ThreadAccess::canWritePrivate( (string) $msg->thread_type, (int) $msg->thread_id, $author_user_id )
            ) {
                return false;
            }
            $update['visibility'] = $visibility;
        }
        $ok = $wpdb->update( $this->table(), $update, [ 'id' => $id, 'club_id' => CurrentClub::id() ] );
        return (bool) $ok;
    }

    /**
     * #3781 — rewrite the body of a system message the plugin wrote
     * itself. `update()` above refuses system messages on purpose: a
     * person may not edit them. This is the plugin amending its own
     * entry while a coach is still saving the same goal, so twelve
     * progress edits read as one line instead of twelve.
     *
     * No `edited_at` stamp: the entry has no author header and no edit
     * marker in the thread, and an "(edited)" badge on a machine line
     * would be noise.
     *
     * Refuses once anyone has said anything after it — rewriting an
     * entry somebody has already replied to would put the newer fact
     * above the reply to the older one. The caller then writes a fresh
     * entry instead.
     */
    public function updateSystemBody( int $id, string $body ): bool {
        global $wpdb;
        // Presence, not affected rows: `$wpdb->update` reports 0 for an
        // unchanged body as well as for a missing row, and the caller
        // reads a false here as "gone, write a new one".
        $exists = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table()} m
              WHERE m.id = %d AND m.club_id = %d AND m.is_system = 1 AND m.deleted_at IS NULL
                AND NOT EXISTS (
                    SELECT 1 FROM {$this->table()} n
                     WHERE n.thread_type = m.thread_type
                       AND n.thread_id   = m.thread_id
                       AND n.club_id     = m.club_id
                       AND n.id          > m.id
                )",
            $id, CurrentClub::id()
        ) );
        if ( $exists === 0 ) return false;

        $wpdb->update(
            $this->table(),
            [ 'body' => $body ],
            [ 'id' => $id, 'club_id' => CurrentClub::id(), 'is_system' => 1 ]
        );
        return true;
    }

    /**
     * #3781 — remove a system message the plugin wrote and then found it
     * had nothing left to say (a coach who moved a value and moved it
     * straight back). Guarded on `is_system` so it can never reach a
     * person's message; those soft-delete instead.
     */
    public function deleteSystemMessage( int $id ): bool {
        global $wpdb;
        $ok = $wpdb->delete(
            $this->table(),
            [ 'id' => $id, 'club_id' => CurrentClub::id(), 'is_system' => 1 ]
        );
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

    /**
     * #1385 — the most recent public, non-system, non-deleted message on
     * any of a player's goal threads, authored by someone OTHER than the
     * player themselves. Powers the `coach_nudge` ("A note from your
     * coach") card. Goal threads use `thread_type = 'goal'` with
     * `thread_id = tt_goals.id`.
     *
     * @return object|null `{body: string, created_at: string}`
     */
    public function latestPublicGoalMessageForPlayer( int $player_id, int $exclude_user_id = 0 ): ?object {
        if ( $player_id <= 0 ) return null;
        global $wpdb;
        $p   = $wpdb->prefix;
        $tbl = $this->table();

        $sql  = "SELECT m.body, m.created_at
                   FROM {$tbl} m
                   JOIN {$p}tt_goals g ON g.id = m.thread_id
                  WHERE m.thread_type = 'goal'
                    AND m.club_id = %d
                    AND m.is_system = 0
                    AND m.deleted_at IS NULL
                    AND m.visibility = %s
                    AND g.player_id = %d
                    AND g.archived_at IS NULL";
        $args = [ CurrentClub::id(), ThreadVisibility::PUBLIC_LEVEL, $player_id ];
        if ( $exclude_user_id > 0 ) {
            $sql   .= " AND m.author_user_id <> %d";
            $args[] = $exclude_user_id;
        }
        $sql .= " ORDER BY m.created_at DESC, m.id DESC LIMIT 1";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared — args bound below.
        $row = $wpdb->get_row( $wpdb->prepare( $sql, $args ) );
        return $row ?: null;
    }

    public function withinEditWindow( string $created_at ): bool {
        $created_ts = strtotime( $created_at . ' UTC' );
        if ( $created_ts === false ) return false;
        return ( time() - $created_ts ) <= self::EDIT_WINDOW_SECONDS;
    }
}
