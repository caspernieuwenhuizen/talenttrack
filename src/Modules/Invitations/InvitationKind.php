<?php
namespace TT\Modules\Invitations;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Domain\Vocabularies\Lookups\InvitationKind as CanonicalInvitationKind;

/**
 * InvitationKind — three role variants of the invitation flow.
 *
 * v4.12.9 (#988 PR-set 7) — the canonical invitation kind values moved
 * into `TT\Domain\Vocabularies\Lookups\InvitationKind`. The constants
 * below alias the new vocabulary for one release per #988's locked
 * plan, and will be removed in the next minor; new code should
 * reference `TT\Domain\Vocabularies\Lookups\InvitationKind::*`
 * directly. The module-local `label()` / `isValid()` / `all()`
 * helpers stay in place — they encode rendering rules that aren't
 * part of the vocabulary contract.
 */
final class InvitationKind {

    /** @deprecated since v4.12.9 — use {@see CanonicalInvitationKind::PLAYER}; removed in next minor. */
    public const PLAYER = CanonicalInvitationKind::PLAYER;

    /** @deprecated since v4.12.9 — use {@see CanonicalInvitationKind::PARENT}; removed in next minor. */
    public const PARENT = CanonicalInvitationKind::PARENT;

    /** @deprecated since v4.12.9 — use {@see CanonicalInvitationKind::STAFF}; removed in next minor. */
    public const STAFF  = CanonicalInvitationKind::STAFF;

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
