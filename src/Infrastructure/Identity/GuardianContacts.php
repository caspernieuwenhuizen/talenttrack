<?php
namespace TT\Infrastructure\Identity;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Audit\AuditService;
use TT\Modules\Invitations\PlayerParentsRepository;

/**
 * GuardianContacts (#4253) — the names, e-mail addresses and phone numbers
 * of a player's linked parents and guardians, and the audit row every read
 * of them writes. Guardian contact data is personal data about adults
 * around a minor, so a read is logged like other sensitive reads: who,
 * when, which player.
 *
 * Deciding who may read it stays with the caller (staff for the player,
 * `AuthorizationService::isStaffForPlayer()`).
 */
final class GuardianContacts {

    /**
     * One entry per linked guardian account, primary first. Links whose
     * account no longer exists are left out.
     *
     * @return list<array{user_id:int, name:string, email:string, phone:string, is_primary:bool}>
     */
    public static function forPlayer( int $player_id ): array {
        if ( $player_id <= 0 ) return [];

        $out = [];
        foreach ( ( new PlayerParentsRepository() )->linksForPlayer( $player_id ) as $row ) {
            $link = (array) $row;
            $user = get_userdata( (int) ( $link['parent_user_id'] ?? 0 ) );
            if ( ! $user ) continue;
            $out[] = [
                'user_id'    => (int) $user->ID,
                'name'       => $user->display_name !== '' ? (string) $user->display_name : (string) $user->user_email,
                'email'      => (string) $user->user_email,
                // Person row first, then the account's `tt_phone` meta (#3684).
                'phone'      => trim( (string) ContactResolver::phoneForUser( (int) $user->ID ) ),
                'is_primary' => ! empty( $link['is_primary'] ),
            ];
        }
        return $out;
    }

    /**
     * Audit one read of a player's guardian contacts. Written for every
     * read, including one that found none, so the log shows each time the
     * card was opened for this player.
     */
    public static function recordRead( int $player_id, string $surface, int $count ): void {
        if ( $player_id <= 0 ) return;
        ( new AuditService() )->record( 'player.guardian_contacts_viewed', 'player', $player_id, [
            'surface' => $surface,
            'count'   => $count,
        ] );
    }
}
