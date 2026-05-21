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

    /**
     * Operator-editable label. Resolves through `tt_translations` via
     * `LookupTranslator::byTypeAndName('invitation_kind', $value)`;
     * pre-migration installs fall back to the canonical English label.
     */
    public static function label( string $kind ): string {
        if ( $kind === '' ) return '';
        if ( class_exists( '\\TT\\Infrastructure\\Query\\LookupTranslator' ) ) {
            $label = \TT\Infrastructure\Query\LookupTranslator::byTypeAndName( 'invitation_kind', $kind );
            if ( $label !== '' && $label !== $kind ) return $label;
        }
        switch ( $kind ) {
            case self::PLAYER: return __( 'Player', 'talenttrack' );
            case self::PARENT: return __( 'Parent', 'talenttrack' );
            case self::STAFF:  return __( 'Staff', 'talenttrack' );
        }
        return $kind;
    }
}
