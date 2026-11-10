<?php
namespace TT\Infrastructure\Players;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * PlayerPosition (#4978, P7 on epic #4975) — the one reader of a player's
 * stored `preferred_positions`.
 *
 * The column holds a JSON array (`["GK","CB"]`) on modern rows and a
 * comma-separated string on legacy ones. Several surfaces parsed it with
 * their own private helper, and the PDP conversation form now picks the
 * keeper or the outfield form from it, so the answer has one owner: the
 * same player is a goalkeeper on every surface or on none.
 *
 * The first code is the primary position. Codes are returned trimmed and as
 * stored; callers that compare them use `isGoalkeeper()` or upper-case them
 * themselves.
 */
final class PlayerPosition {

    /** The position code of a goalkeeper in the seeded vocabulary. */
    public const GOALKEEPER = 'GK';

    /**
     * A stored `preferred_positions` value as its position codes, in order,
     * empty ones and non-scalars dropped. Empty or unparseable input gives
     * an empty list.
     *
     * @return list<string>
     */
    public static function codes( string $stored ): array {
        $stored = trim( $stored );
        if ( $stored === '' ) return [];
        $decoded = json_decode( $stored, true );
        $codes   = is_array( $decoded ) ? $decoded : explode( ',', $stored );
        $out     = [];
        foreach ( $codes as $code ) {
            if ( ! is_scalar( $code ) ) continue;
            $code = trim( (string) $code );
            if ( $code !== '' ) $out[] = $code;
        }
        return $out;
    }

    /** The primary (first) position code of a stored value, or ''. */
    public static function primaryCode( string $stored ): string {
        return self::codes( $stored )[0] ?? '';
    }

    /** The primary position code of a player row, or '' when none is set. */
    public static function primaryCodeOf( object $player ): string {
        return self::primaryCode( (string) ( ( (array) $player )['preferred_positions'] ?? '' ) );
    }

    /** Is the player's primary position goalkeeper? No position is not. */
    public static function isGoalkeeper( object $player ): bool {
        return strtoupper( self::primaryCodeOf( $player ) ) === self::GOALKEEPER;
    }
}
