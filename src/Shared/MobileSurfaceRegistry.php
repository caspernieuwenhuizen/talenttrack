<?php
namespace TT\Shared;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * MobileSurfaceRegistry — per-`?tt_view=` mobile classification (#0084 Child 1).
 *
 * Three classes that govern how a surface should behave on phones:
 *
 *   - `native`        — mobile-first surface, designed for primary phone use.
 *                       Uses the mobile pattern library (#0084 Child 2) on top
 *                       of the #0056 mobile-first foundation.
 *   - `viewable`      — readable on mobile but optimised for desktop. Inherits
 *                       the existing responsive CSS. Default for unregistered
 *                       view slugs.
 *   - `desktop_only`  — must be on desktop. Phone access is intercepted by
 *                       the dispatcher and the user lands on a polite
 *                       desktop-prompt page (`FrontendMobilePromptView`) with
 *                       an "email me the link" affordance and a "go to
 *                       dashboard" fallback.
 *
 * Used by `DashboardShortcode` early in the dispatch flow to decide whether
 * to short-circuit a phone visit on a `desktop_only` route. The classification
 * also drives conditional asset enqueue in #0084 Child 2 (the pattern-library
 * CSS / JS load only on `native` surfaces).
 *
 * Backwards-compatible: any view slug not registered here resolves to
 * `viewable`, so the existing 25-ish slugs keep behaving exactly as they did
 * before #0084 ships. Child 3 of the epic walks the inventory and registers
 * each one explicitly.
 */
final class MobileSurfaceRegistry {

    public const CLASS_NATIVE       = 'native';
    public const CLASS_VIEWABLE     = 'viewable';
    public const CLASS_DESKTOP_ONLY = 'desktop_only';

    /** @var array<string,string>  view-slug → class */
    private static array $classifications = [];

    /**
     * Register a classification for `$view_slug`. Idempotent — last write
     * wins, which lets module unit tests overwrite a default.
     *
     * Defensive: an unrecognised class falls through to `viewable` so a
     * typo at the call site doesn't accidentally lock users out of a
     * surface.
     */
    public static function register( string $view_slug, string $class ): void {
        $view_slug = sanitize_key( $view_slug );
        if ( $view_slug === '' ) return;
        if ( ! in_array( $class, self::allowedClasses(), true ) ) {
            $class = self::CLASS_VIEWABLE;
        }
        self::$classifications[ $view_slug ] = $class;
    }

    /**
     * Look up the class for `$view_slug`. Returns `viewable` for any slug
     * that was never registered — every surface is viewable until proven
     * otherwise.
     */
    public static function classify( string $view_slug ): string {
        $view_slug = sanitize_key( $view_slug );
        return self::$classifications[ $view_slug ] ?? self::CLASS_VIEWABLE;
    }

    /**
     * Whether `$view_slug` is gated to desktop. Convenience wrapper.
     */
    public static function isDesktopOnly( string $view_slug ): bool {
        return self::classify( $view_slug ) === self::CLASS_DESKTOP_ONLY;
    }

    /**
     * Whether `$view_slug` is mobile-first.
     */
    public static function isNative( string $view_slug ): bool {
        return self::classify( $view_slug ) === self::CLASS_NATIVE;
    }

    /**
     * Wholesale dump for the operator-facing audit / diagnostics pages.
     *
     * @return array<string,string>
     */
    public static function all(): array {
        return self::$classifications;
    }

    /**
     * Reset between tests. Production callers never need this.
     */
    public static function clear(): void {
        self::$classifications = [];
    }

    /** @return string[] */
    private static function allowedClasses(): array {
        return [ self::CLASS_NATIVE, self::CLASS_VIEWABLE, self::CLASS_DESKTOP_ONLY ];
    }
}
