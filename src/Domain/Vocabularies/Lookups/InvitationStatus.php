<?php
/**
 * InvitationStatus — typed constants for the four values stored on
 * `tt_invitations.status`. Backs the operator-editable display labels
 * via `tt_translations` (`invitation_status` row set seeded by
 * migration 0108); the stored keys themselves are the canonical
 * contract between code and database and never change.
 *
 * Lifecycle:
 *
 *     PENDING -> ACCEPTED
 *             -> EXPIRED   (token TTL elapsed; flipped lazily on acceptance attempt)
 *             -> REVOKED   (invitation revoked by admin before acceptance)
 *
 * Per #988's locked decisions (2026-05-28):
 *
 *   - `Vocabularies\Lookups\InvitationStatus` is the single source of
 *     truth for the four status values.
 *   - `TT\Modules\Invitations\InvitationStatus::*` constants alias the
 *     values here for one release as a backward-compatibility shim;
 *     they will be removed in the next minor. The legacy class retains
 *     its module-local `label()` helper — only the constant values
 *     delegate to the canonical vocabulary.
 *
 * Use the constants in PHP comparisons:
 *
 *     if ( $row->status === InvitationStatus::PENDING ) { ... }
 *     [ 'status' => InvitationStatus::REVOKED ]
 *
 * SQL string literals stay as literals — DB is the source of truth.
 *
 * REST endpoints accept BOTH the literal AND the constant for one
 * release per #988's backward-compat allowlist; see docs/rest-api.md
 * for the deprecation timeline.
 */

namespace TT\Domain\Vocabularies\Lookups;

if ( ! defined( 'ABSPATH' ) ) exit;

final class InvitationStatus {

    public const PENDING  = 'pending';
    public const ACCEPTED = 'accepted';
    public const EXPIRED  = 'expired';
    public const REVOKED  = 'revoked';

    /** @var list<string> */
    public const ALL = [
        self::PENDING,
        self::ACCEPTED,
        self::EXPIRED,
        self::REVOKED,
    ];

    public static function isValid( string $value ): bool {
        return in_array( $value, self::ALL, true );
    }
}
