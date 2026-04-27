<?php
namespace TT\Modules\Invitations;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * InvitationStatus — string constants for `tt_invitations.status`.
 */
final class InvitationStatus {

    public const PENDING  = 'pending';
    public const ACCEPTED = 'accepted';
    public const EXPIRED  = 'expired';
    public const REVOKED  = 'revoked';

    /** @return list<string> */
    public static function all(): array {
        return [ self::PENDING, self::ACCEPTED, self::EXPIRED, self::REVOKED ];
    }

    public static function label( string $status ): string {
        switch ( $status ) {
            case self::PENDING:  return __( 'Pending', 'talenttrack' );
            case self::ACCEPTED: return __( 'Accepted', 'talenttrack' );
            case self::EXPIRED:  return __( 'Expired', 'talenttrack' );
            case self::REVOKED:  return __( 'Revoked', 'talenttrack' );
        }
        return $status;
    }
}
