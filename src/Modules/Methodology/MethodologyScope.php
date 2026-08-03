<?php
namespace TT\Modules\Methodology;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * MethodologyScope (#2319, epic #2316) — the ambient "which methodology
 * set am I reading/writing" for the current request, parallel to how
 * CurrentClub::id() carries ambient tenancy.
 *
 * Repositories filter their list reads and stamp their creates by
 * `active()`. By default that is the install's active set
 * (ActiveMethodologyResolver::forInstall()), so the read view and REST
 * need no wiring — they simply see the active methodology. The manage
 * surface (#2320) calls set() to pin a specific set while editing it.
 *
 * active() returns 0 before the tables exist (pre-migration) or when no
 * set resolves; repositories treat 0 as "unscoped" and keep their
 * legacy, all-content behaviour.
 */
final class MethodologyScope {

    private static bool $hasOverride = false;
    private static int  $override    = 0;

    /** Pin the scope to a specific set for the rest of the request. */
    public static function set( int $id ): void {
        self::$hasOverride = true;
        self::$override    = max( 0, $id );
    }

    /** Drop any override (test seam / end of a manage request). */
    public static function reset(): void {
        self::$hasOverride = false;
        self::$override    = 0;
    }

    /** The set to scope by: an explicit override, else the install active set. */
    public static function active(): int {
        if ( self::$hasOverride ) {
            return self::$override;
        }
        return ActiveMethodologyResolver::forInstall();
    }
}
