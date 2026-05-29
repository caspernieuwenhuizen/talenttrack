<?php
/**
 * InvitationKind — typed constants for the three role variants of the
 * invitation flow stored on `tt_invitations.kind`. Backs the
 * operator-editable display labels via `tt_translations`
 * (`invitation_kind` row set); the stored keys themselves are the
 * canonical contract between the invitation service and the role
 * resolver that maps a `kind` to a WP role on acceptance.
 *
 * Per #988's locked decisions (2026-05-28):
 *
 *   - `Vocabularies\Lookups\InvitationKind` is the single source of
 *     truth for the three kind values.
 *   - `TT\Modules\Invitations\InvitationKind::*` constants alias the
 *     values here for one release as a backward-compatibility shim;
 *     they will be removed in the next minor. The legacy class
 *     retains its module-local `label()` / `isValid()` / `all()`
 *     helpers — only the constant values delegate to the canonical
 *     vocabulary.
 *
 * Use the constants in PHP comparisons:
 *
 *     if ( $kind === InvitationKind::PLAYER ) { ... }
 *     [ 'kind' => InvitationKind::STAFF ]
 *
 * SQL string literals stay as literals — DB is the source of truth.
 *
 * REST endpoints accept BOTH the literal AND the constant for one
 * release per #988's backward-compat allowlist; see docs/rest-api.md
 * for the deprecation timeline.
 */

namespace TT\Domain\Vocabularies\Lookups;

if ( ! defined( 'ABSPATH' ) ) exit;

final class InvitationKind {

    public const PLAYER = 'player';
    public const PARENT = 'parent';
    public const STAFF  = 'staff';

    /** @var list<string> */
    public const ALL = [
        self::PLAYER,
        self::PARENT,
        self::STAFF,
    ];

    public static function isValid( string $value ): bool {
        return in_array( $value, self::ALL, true );
    }
}
