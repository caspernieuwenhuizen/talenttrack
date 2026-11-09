<?php
namespace TT\Infrastructure\Security;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * ScopeMemo (#4671) — remembers the inputs of a scope decision while one is
 * being made, so a question asked per player does not re-read the same
 * links for every player.
 *
 * Asking "may this user read player N?" for each of a scout's linked players
 * reads the user's own player record, their guardian links and their scout
 * links again for every N, though none of them depends on N. A list-form
 * question (a scope, a search) asks it once per candidate, so its cost grows
 * with the size of the viewer's scope.
 *
 * Inside {@see self::run()} those reads are remembered; outside it they are
 * not. The window is the decision, not the request, on purpose: code that
 * links a guardian, releases a player or ends a panel seat and then asks
 * again in the same request gets the new answer, with or without a call to
 * `AuthorizationService::flushCache()`. `flushCache()` empties the memo too,
 * for a change made inside a window.
 *
 * Keys carry the club, so a window that spans `ClubIteration::withClub()`
 * cannot answer one academy with another's links.
 */
final class ScopeMemo {

    private static int $depth = 0;

    /** @var array<string, mixed> */
    private static array $store = [];

    /**
     * Run `$fn` with the memo on. Windows nest; the memo empties when the
     * outermost one ends.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public static function run( callable $fn ) {
        self::$depth++;
        try {
            return $fn();
        } finally {
            self::$depth--;
            if ( self::$depth === 0 ) self::$store = [];
        }
    }

    /**
     * The remembered answer for `$key` inside a window, or `$compute()`'s.
     * Outside a window `$compute()` runs every time.
     *
     * @template T
     * @param callable(): T $compute
     * @return T
     */
    public static function remember( string $key, callable $compute ) {
        if ( self::$depth === 0 ) return $compute();
        $key = (int) \TT\Infrastructure\Tenancy\CurrentClub::id() . '|' . $key;
        if ( ! array_key_exists( $key, self::$store ) ) self::$store[ $key ] = $compute();
        return self::$store[ $key ];
    }

    /**
     * #4742 — set the answer for `$key` ahead of the question, inside a
     * window: a bulk check reads a source once for a whole set of users and
     * leaves each user's answer here, where the unchanged single check finds
     * it. An answer already remembered is kept. Outside a window nothing is
     * kept, so the single check reads the source itself, as it always did.
     *
     * @param mixed $value
     */
    public static function prime( string $key, $value ): void {
        if ( self::$depth === 0 ) return;
        $key = (int) \TT\Infrastructure\Tenancy\CurrentClub::id() . '|' . $key;
        if ( ! array_key_exists( $key, self::$store ) ) self::$store[ $key ] = $value;
    }

    /** Is an answer for `$key` remembered? Always false outside a window. */
    public static function has( string $key ): bool {
        if ( self::$depth === 0 ) return false;
        return array_key_exists( (int) \TT\Infrastructure\Tenancy\CurrentClub::id() . '|' . $key, self::$store );
    }

    /**
     * The remembered answer for `$key`, null when there is none.
     *
     * @return mixed
     */
    public static function get( string $key ) {
        if ( self::$depth === 0 ) return null;
        return self::$store[ (int) \TT\Infrastructure\Tenancy\CurrentClub::id() . '|' . $key ] ?? null;
    }

    public static function active(): bool {
        return self::$depth > 0;
    }

    public static function clear(): void {
        self::$store = [];
    }
}
