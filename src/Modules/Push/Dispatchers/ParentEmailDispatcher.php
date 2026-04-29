<?php
namespace TT\Modules\Push\Dispatchers;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
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
        $emails = $this->parentEmailsFor( $user_id );
        if ( empty( $emails ) ) return false;

        $title = (string) ( $context['title'] ?? '' );
        $body  = (string) ( $context['body']  ?? '' );
        $url   = (string) ( $context['url']   ?? '' );

        $lines = [ $body ];
        if ( $url !== '' ) {
            $lines[] = '';
            $lines[] = $url;
        }
        $sent_any = false;
        foreach ( $emails as $email ) {
            if ( wp_mail( $email, $title, implode( "\n", $lines ) ) ) {
                $sent_any = true;
            }
        }
        return $sent_any;
    }

    /**
     * Resolve linked parent emails for a target player WP user.
     * Pulls the player row by `wp_user_id`, then the pivot, then
     * the parent users' emails. Empty list = no parent on file.
     *
     * @return list<string>
     */
    private function parentEmailsFor( int $user_id ): array {
        global $wpdb;
        $player_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}tt_players
              WHERE wp_user_id = %d AND club_id = %d LIMIT 1",
            $user_id, CurrentClub::id()
        ) );
        if ( $player_id <= 0 ) return [];

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

        $emails = [];
        foreach ( $parent_ids as $pid ) {
            $u = get_userdata( $pid );
            if ( $u && ! empty( $u->user_email ) ) {
                $emails[] = (string) $u->user_email;
            }
        }
        return array_values( array_unique( $emails ) );
    }
}
