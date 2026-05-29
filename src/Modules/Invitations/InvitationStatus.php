<?php
namespace TT\Modules\Invitations;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Domain\Vocabularies\Lookups\InvitationStatus as CanonicalInvitationStatus;

/**
 * InvitationStatus — string constants for `tt_invitations.status`.
 *
 * Stored values: lowercase keys (`pending`, `accepted`, `expired`,
 * `revoked`). These are the contract between code and database; never
 * change them.
 *
 * Rendered labels: as of v3.110.192 (#803), `label()` delegates to
 * `LookupTranslator::byTypeAndName('invitation_status', $status)`,
 * which resolves through `tt_translations` for the current locale.
 * Migration 0108 seeds the four canonical rows + translations for
 * en_US / nl_NL / fr_FR / de_DE / es_ES. Operators relabel / translate
 * via the frontend Lookups admin (`?tt_view=configuration
 * &config_sub=lookups&category=invitation_statuses`); the constants
 * above stay sacred for code-side comparisons.
 *
 * v4.12.9 (#988 PR-set 7) — the canonical invitation status values
 * moved into `TT\Domain\Vocabularies\Lookups\InvitationStatus`. The
 * constants below alias the new vocabulary for one release per #988's
 * locked plan, and will be removed in the next minor; new code should
 * reference `TT\Domain\Vocabularies\Lookups\InvitationStatus::*`
 * directly. The module-local `label()` helper stays in place — it
 * encodes rendering rules that aren't part of the vocabulary contract.
 */
final class InvitationStatus {

    /** @deprecated since v4.12.9 — use {@see CanonicalInvitationStatus::PENDING}; removed in next minor. */
    public const PENDING  = CanonicalInvitationStatus::PENDING;

    /** @deprecated since v4.12.9 — use {@see CanonicalInvitationStatus::ACCEPTED}; removed in next minor. */
    public const ACCEPTED = CanonicalInvitationStatus::ACCEPTED;

    /** @deprecated since v4.12.9 — use {@see CanonicalInvitationStatus::EXPIRED}; removed in next minor. */
    public const EXPIRED  = CanonicalInvitationStatus::EXPIRED;

    /** @deprecated since v4.12.9 — use {@see CanonicalInvitationStatus::REVOKED}; removed in next minor. */
    public const REVOKED  = CanonicalInvitationStatus::REVOKED;

    /** @return list<string> */
    public static function all(): array {
        return [ self::PENDING, self::ACCEPTED, self::EXPIRED, self::REVOKED ];
    }

    public static function label( string $status ): string {
        if ( $status === '' ) return '';
        if ( class_exists( '\\TT\\Infrastructure\\Query\\LookupTranslator' ) ) {
            $label = \TT\Infrastructure\Query\LookupTranslator::byTypeAndName( 'invitation_status', $status );
            if ( $label !== '' ) return $label;
        }
        // Fallback for pre-migration installs where the lookup row
        // doesn't exist yet. Keeps the previous behaviour so the
        // surface never renders the raw lowercase key.
        switch ( $status ) {
            case self::PENDING:  return __( 'Pending', 'talenttrack' );
            case self::ACCEPTED: return __( 'Accepted', 'talenttrack' );
            case self::EXPIRED:  return __( 'Expired', 'talenttrack' );
            case self::REVOKED:  return __( 'Revoked', 'talenttrack' );
        }
        return $status;
    }
}
