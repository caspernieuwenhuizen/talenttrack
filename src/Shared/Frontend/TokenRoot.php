<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * The element that carries the design tokens (#4772).
 *
 * `tokens.css` declares every neutral token on `.tt-root`, never
 * `:root`, so the active WordPress theme is left alone. That class used
 * to come only from the custom CSS module's `body_class` filter, so
 * switching that module off, or a theme that never calls `body_class()`,
 * left every token undefined at once.
 *
 * The shell now owns it. Each TalentTrack entry point that enqueues
 * `tokens.css` puts its output inside a `.tt-root` wrapper of its own
 * (`wrap()`), and the body class is added here, from core, for custom CSS
 * that targets `body.tt-root` for anything but tokens.
 *
 * Every `.tt-root` re-sets the token defaults. Two consequences:
 *
 * - A token override written on `body.tt-root` no longer reaches the
 *   page, because the wrapper is closer and sets the default again.
 *   Operators write token overrides on `.tt-root`, which matches the
 *   wrapper as well (docs/custom-css.md).
 * - A `.tt-root` placed inside `.tt-dashboard` is not harmless: the theme
 *   sheets redeclare their tokens on `.tt-dashboard`, and a `.tt-root`
 *   below it resets them to the defaults for everything it contains. The
 *   wrapper therefore goes outside `.tt-dashboard`, never within it.
 */
final class TokenRoot {

    public const CLASS_NAME = 'tt-root';

    public static function init(): void {
        add_filter( 'body_class', [ __CLASS__, 'addBodyClass' ] );
        add_filter( 'admin_body_class', [ __CLASS__, 'addAdminBodyClass' ] );
    }

    /**
     * Put `$html` inside a `.tt-root` wrapper, directly around it.
     *
     * A wrapper of its own rather than the class on `.tt-dashboard`, so a
     * rule written as `.tt-root .tt-dashboard …` keeps matching.
     */
    public static function wrap( string $html ): string {
        return '<div class="' . self::CLASS_NAME . '">' . $html . '</div>';
    }

    /**
     * @param array<int, string> $classes
     * @return array<int, string>
     */
    public static function addBodyClass( array $classes ): array {
        if ( ! in_array( self::CLASS_NAME, $classes, true ) ) {
            $classes[] = self::CLASS_NAME;
        }
        return $classes;
    }

    /**
     * The wp-admin equivalent. `admin_body_class` passes a single
     * space-separated string, not an array.
     */
    public static function addAdminBodyClass( string $classes ): string {
        return trim( $classes . ' ' . self::CLASS_NAME );
    }
}
