<?php
/**
 * GameSubtype — typed constants for the values stored in
 * `tt_activities.game_subtype_key`. Backs the `game_subtype` lookup
 * (operator-editable). The three seeded values below trace back to
 * migration 0013 (original `competition_type`: League / Cup) and
 * migration 0027 (renamed to `game_subtype`, added Friendly).
 *
 * Stored with TitleCase, intentionally — the original `competition_type`
 * lookup used display labels as the stored name; the rename in 0027 kept
 * that convention. Filters game-only reports.
 *
 * Use the constants in PHP comparisons:
 *
 *     if ( $activity->game_subtype_key === GameSubtype::FRIENDLY ) { ... }
 *
 * SQL string literals stay as literals — DB is the source of truth.
 *
 * REST endpoints accept BOTH the literal AND the constant for one release
 * per #988's backward-compat allowlist; see docs/rest-api.md for the
 * deprecation timeline.
 */

namespace TT\Domain\Vocabularies\Lookups;

if ( ! defined( 'ABSPATH' ) ) exit;

final class GameSubtype {

    public const FRIENDLY = 'Friendly';
    public const LEAGUE   = 'League';
    public const CUP      = 'Cup';

    /** @var list<string> */
    public const ALL = [
        self::FRIENDLY,
        self::LEAGUE,
        self::CUP,
    ];

    public static function isValid( string $value ): bool {
        return in_array( $value, self::ALL, true );
    }
}
