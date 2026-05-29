<?php
/**
 * PreferredFoot — typed constants for the canonical stored values on
 * `tt_players.preferred_foot`. Three lowercase keys plus the empty
 * string (not specified). Matches the allowlist that
 * `RosterDetailsStep::validate()` enforces via `sanitize_key()`:
 *
 *     in_array( $foot, [ '', 'left', 'right', 'both' ], true )
 *
 * Backs the `foot_option` lookup (operator-editable via the lookups
 * admin); the lookup row `name` carries the TitleCase display label
 * (`Left` / `Right` / `Both`) per migration 0001 + #987's canonical
 * normalisation, but the *stored player record value* is the lowercase
 * key — chemistry / compatibility engines compare against the
 * lowercase form (`$pref === 'left'`).
 *
 * Use the constants in PHP comparisons:
 *
 *     if ( $player_pref === PreferredFoot::LEFT ) { ... }
 *     in_array( $foot, [ '', PreferredFoot::LEFT, PreferredFoot::RIGHT, PreferredFoot::BOTH ], true );
 *
 * Empty-string sentinel ("not specified") is not part of `ALL` — it is
 * not one of the three foot options, it is the absence of one.
 * Comparisons that allow the unset case should test against `''` or
 * `null` separately, as the chemistry engines do today.
 *
 * REST endpoints accept BOTH the literal AND the constant for one
 * release per #988's backward-compat allowlist; see docs/rest-api.md
 * for the deprecation timeline.
 */

namespace TT\Domain\Vocabularies\Lookups;

if ( ! defined( 'ABSPATH' ) ) exit;

final class PreferredFoot {

    public const LEFT  = 'left';
    public const RIGHT = 'right';
    public const BOTH  = 'both';

    /** @var list<string> */
    public const ALL = [
        self::LEFT,
        self::RIGHT,
        self::BOTH,
    ];

    public static function isValid( string $value ): bool {
        return in_array( $value, self::ALL, true );
    }
}
