<?php
namespace TT\Modules\Push\Dispatchers;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Comms\Domain\Recipient;
use TT\Modules\Invitations\PlayerParentsRepository;

/**
 * ParentEmailDispatcher — sends to the linked parent(s) of a player
 * (#0042). Resolution path: target user → linked tt_players row →
 * tt_player_parents → parent WP user(s) → email each.
 *
 * Used in the dispatcher chain `[ Push, ParentEmail, Email ]` for
 * U8-U10 / U11-U12 cohorts where the player itself often has no
 * email address. For users who are not linked to a player record
 * (staff, coaches, scouts, admins) the dispatcher returns false and
 * the chain falls through to plain EmailDispatcher.
 *
 * The pivot table (`tt_player_parents`, #0032) and the legacy
 * `tt_players.parent_user_id` column both feed the resolution; the
 * pivot wins where present.
 */
final class ParentEmailDispatcher implements DispatcherInterface {

    private PlayerParentsRepository $parents;

    public function __construct( ?PlayerParentsRepository $parents = null ) {
        $this->parents = $parents ?? new PlayerParentsRepository();
    }

    public function key(): string { return 'parent_email'; }

    public function applicableTo( array $context ): bool {
        $user_id = (int) ( $context['user_id'] ?? 0 );
        if ( $user_id <= 0 ) return false;
        return ! empty( $this->parentEmailsFor( $user_id ) );
    }

    public function deliver( array $context ): bool {
        $user_id = (int) ( $context['user_id'] ?? 0 );
        if ( $user_id <= 0 ) return false;

        $recipients = $this->parentRecipientsFor( $user_id );
        if ( empty( $recipients ) ) return false;

        return NotificationDelivery::send( $context, $recipients );
    }

    /**
     * #2604 — the same resolution as `parentEmailsFor()`, but returning
     * `Recipient` value objects so each parent is opt-out checked and
     * audited individually instead of being an anonymous address in a
     * loop of `wp_mail()` calls.
     *
     * `Recipient::parent()` carries the child's player id, which is what
     * files the message against the right player in the log — the
     * question this epic exists to answer.
     *
     * @return list<Recipient>
     */
    private function parentRecipientsFor( int $user_id ): array {
        $player_id = $this->playerIdForUser( $user_id );
        if ( $player_id <= 0 ) return [];

        $out  = [];
        $seen = [];
        foreach ( $this->parentUserIdsFor( $player_id ) as $parent_user_id ) {
            $u = get_userdata( $parent_user_id );
            if ( ! $u || empty( $u->user_email ) ) continue;

            $email = (string) $u->user_email;
            if ( isset( $seen[ $email ] ) ) continue;
            $seen[ $email ] = true;

            $out[] = Recipient::parent(
                (int) $parent_user_id,
                $player_id,
                $email,
                (string) get_user_meta( $parent_user_id, 'tt_phone', true ),
                (string) get_user_meta( $parent_user_id, 'locale', true )
            );
        }
        return $out;
    }

    /**
     * Resolve linked parent emails for a target player WP user.
     * Pulls the player row by `wp_user_id`, then the pivot, then
     * the parent users' emails. Empty list = no parent on file.
     *
     * Still used by `applicableTo()`, which only needs to know whether
     * anyone is reachable.
     *
     * @return list<string>
     */
    private function parentEmailsFor( int $user_id ): array {
        $player_id = $this->playerIdForUser( $user_id );
        if ( $player_id <= 0 ) return [];

        $emails = [];
        foreach ( $this->parentUserIdsFor( $player_id ) as $pid ) {
            $u = get_userdata( $pid );
            if ( $u && ! empty( $u->user_email ) ) {
                $emails[] = (string) $u->user_email;
            }
        }
        return array_values( array_unique( $emails ) );
    }

    /** The player record linked to a WP user, or 0. */
    private function playerIdForUser( int $user_id ): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}tt_players
              WHERE wp_user_id = %d AND club_id = %d LIMIT 1",
            $user_id, CurrentClub::id()
        ) );
    }

    /**
     * Parent WP user ids for a player: the pivot first, falling back to
     * the legacy column.
     *
     * @return list<int>
     */
    private function parentUserIdsFor( int $player_id ): array {
        global $wpdb;
        $parent_ids = $this->parents->parentsForPlayer( $player_id );

        // Legacy column fallback — older installs may have written
        // tt_players.parent_user_id without ever populating the pivot.
        if ( empty( $parent_ids ) ) {
            $legacy = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT parent_user_id FROM {$wpdb->prefix}tt_players
                  WHERE id = %d AND club_id = %d LIMIT 1",
                $player_id, CurrentClub::id()
            ) );
            if ( $legacy > 0 ) $parent_ids = [ $legacy ];
        }

        return array_values( array_map( 'intval', $parent_ids ) );
    }
}
