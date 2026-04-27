<?php
namespace TT\Modules\Invitations;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * InvitationKind — three role variants of the invitation flow.
 */
final class InvitationKind {

    public const PLAYER = 'player';
    public const PARENT = 'parent';
    public const STAFF  = 'staff';

    /** @return list<string> */
    public static function all(): array {
        return [ self::PLAYER, self::PARENT, self::STAFF ];
    }

    public static function isValid( string $kind ): bool {
        return in_array( $kind, self::all(), true );
    }

    public static function label( string $kind ): string {
        switch ( $kind ) {
            case self::PLAYER: return __( 'Player', 'talenttrack' );
            case self::PARENT: return __( 'Parent', 'talenttrack' );
            case self::STAFF:  return __( 'Staff', 'talenttrack' );
        }
        return $kind;
    }
}
