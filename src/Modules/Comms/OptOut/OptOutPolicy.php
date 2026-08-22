<?php
namespace TT\Modules\Comms\OptOut;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Comms\Domain\MessageType;

/**
 * OptOutPolicy (#0066) — per-recipient × per-message-type opt-out.
 *
 * Per spec Q5 lean ("per-message-type opt-out"): a parent can mute
 * "training_schedule" updates without losing safeguarding broadcasts.
 *
 * **Storage** (#2638): one row per (club, user, message type) in
 * `tt_comms_optouts`. Presence means opted out; absence means opted in,
 * which is the default.
 *
 * This was usermeta until #2638, keyed `tt_comms_optout_<message_type>`.
 * CLAUDE.md §4 forbids tenant-scoped data there — `wp_usermeta` is global to
 * the WordPress install, so a parent with children at two academies would
 * have muted a message type at both or neither, with no seam to separate
 * them. `MessageType`'s docblock had described a `tt_user_optouts` table
 * since the module shipped; the table simply was never built. Moving the
 * storage is finishing the original design, not changing it — the semantics
 * are identical.
 *
 * Operational message types (`*_OPERATIONAL` per `MessageType`) bypass the
 * check unconditionally — accounts can't mute account-recovery or
 * safeguarding broadcasts. The check still records "would have been opt-out"
 * in the comms log so retention reports show the override.
 *
 * The full opt-out preferences UI lands with the use cases (each use case
 * names the message types it ships and the operator's account settings page
 * surfaces them as togglable). It stays a separate screen from the alerts
 * preference matrix (#2632) per epic #2629 decision 11, with a cross-link
 * each way — different vocabularies and different semantics, one place each.
 */
final class OptOutPolicy {

    private function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'tt_comms_optouts';
    }

    /**
     * True when the user has opted out of receiving messages of the given
     * type. Operational types always return false.
     *
     * Fails open (returns false, i.e. "not opted out") when the table is
     * missing, which can only happen between the plugin updating and its
     * migration running. Failing closed would silently suppress every
     * message on that install — a comms module that quietly sends nothing is
     * far worse than one that briefly ignores an opt-out nobody has set,
     * given nothing wrote opt-outs before this table existed.
     */
    public function isOptedOut( int $userId, string $messageType ): bool {
        if ( $userId <= 0 ) return false;
        if ( MessageType::isOperational( $messageType ) ) return false;
        if ( ! $this->tableExists() ) return false;

        global $wpdb;
        $table = $this->table();

        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
              WHERE " . QueryHelpers::clubScopeWhere() . "
                AND user_id = %d AND message_type = %s",
            $userId,
            $messageType
        ) ) > 0;
    }

    public function setOptedOut( int $userId, string $messageType, bool $optedOut ): void {
        if ( $userId <= 0 ) return;
        if ( MessageType::isOperational( $messageType ) ) return;  // ignore — can't opt out
        if ( ! $this->tableExists() ) return;

        global $wpdb;
        $table = $this->table();

        if ( ! $optedOut ) {
            $wpdb->delete(
                $table,
                array_merge(
                    [ 'user_id' => $userId, 'message_type' => $messageType ],
                    QueryHelpers::clubScopeInsertColumn()
                ),
                [ '%d', '%s', '%d' ]
            );
            return;
        }

        // INSERT IGNORE against the unique key rather than a select-then-
        // insert: opting out twice is idempotent, and the second call must
        // not move `opted_out_at` — when the user muted this is worth being
        // able to answer.
        $wpdb->query( $wpdb->prepare(
            "INSERT IGNORE INTO {$table} ( club_id, user_id, message_type, opted_out_at )
             VALUES ( %d, %d, %s, %s )",
            CurrentClub::id(),
            $userId,
            $messageType,
            current_time( 'mysql' )
        ) );
    }

    /**
     * Every message type this user has muted, for the preferences screen.
     *
     * @return list<string>
     */
    public function optedOutTypesFor( int $userId ): array {
        if ( $userId <= 0 || ! $this->tableExists() ) return [];

        global $wpdb;
        $table = $this->table();

        $rows = $wpdb->get_col( $wpdb->prepare(
            "SELECT message_type FROM {$table}
              WHERE " . QueryHelpers::clubScopeWhere() . "
                AND user_id = %d
              ORDER BY message_type ASC",
            $userId
        ) );

        return array_values( array_map( 'strval', is_array( $rows ) ? $rows : [] ) );
    }

    /** @var bool|null per-request cache */
    private static $tableExists = null;

    /**
     * Cached per request: `isOptedOut()` is called once per recipient per
     * send, and a mass announcement to 300 parents would otherwise spend 300
     * `SHOW TABLES` queries learning something that cannot change mid-request.
     */
    private function tableExists(): bool {
        if ( self::$tableExists !== null ) return self::$tableExists;
        global $wpdb;
        $table = $this->table();
        self::$tableExists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
        return self::$tableExists;
    }

    /** Drop the per-request table cache. Tests use this. */
    public static function flushTableCache(): void {
        self::$tableExists = null;
    }
}
