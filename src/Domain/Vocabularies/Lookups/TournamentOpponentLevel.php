<?php
/**
 * TournamentOpponentLevel — typed constants for the values stored in
 * `tt_tournament_matches.opponent_level`. Backs the
 * `tournament_opponent_level` lookup (operator-editable) seeded by
 * migration 0098 in lowercase snake_case form: `weaker`, `equal`,
 * `stronger`, `much_stronger`. Each row's meta carries a colour code
 * that drives the visible pill on the match card.
 *
 * Use the constants in PHP comparisons:
 *
 *     if ( $match->opponent_level === TournamentOpponentLevel::STRONGER ) { ... }
 *
 * SQL string literals stay as literals — DB is the source of truth.
 *
 * REST endpoints accept BOTH the literal AND the constant for one release
 * per #988's backward-compat allowlist; see docs/rest-api.md for the
 * deprecation timeline.
 */

namespace TT\Domain\Vocabularies\Lookups;

if ( ! defined( 'ABSPATH' ) ) exit;

final class TournamentOpponentLevel {

    public const WEAKER         = 'weaker';
    public const EQUAL          = 'equal';
    public const STRONGER       = 'stronger';
    public const MUCH_STRONGER  = 'much_stronger';

    /** @var list<string> */
    public const ALL = [
        self::WEAKER,
        self::EQUAL,
        self::STRONGER,
        self::MUCH_STRONGER,
    ];

    public static function isValid( string $value ): bool {
        return in_array( $value, self::ALL, true );
    }
}
