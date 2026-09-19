<?php
namespace TT\Modules\DemoData;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * DemoGenerationContext (#3576) — "this request is writing demo data".
 *
 * The generator opens trial cases, publishes plans and cancels trainings
 * through the same code a club uses, so the same hooks fire — deliberately:
 * the journey timeline and the alerts need them. But nothing a generator
 * writes is a message anybody should receive, and every send it triggered
 * for a demo player with no parent landed in the admin's error log as a
 * zero-recipient warning — fifty of them in two seconds.
 *
 * Comms asks this before it sends. It is per request (a static, not an
 * option): a long run spans many requests, and a site-wide flag would also
 * mute real sends other users make while it is in progress.
 *
 * Nesting-safe — `begin()` / `end()` count, so a step that re-enters the
 * generator does not switch the flag off early. Always pair them in a
 * `try` / `finally`.
 */
final class DemoGenerationContext {

    private static int $depth = 0;

    public static function begin(): void {
        self::$depth++;
    }

    public static function end(): void {
        self::$depth = max( 0, self::$depth - 1 );
    }

    public static function isActive(): bool {
        return self::$depth > 0;
    }
}
