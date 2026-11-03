<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * The element that carries the design tokens (#4772).
 *
 * `tokens.css` and the theme sheets declare every token on `.tt-root`,
 * never `:root`, so the active WordPress theme is left alone. That class
 * used to come only from the custom CSS module's `body_class` filter, so
 * switching that module off, or a theme that never calls `body_class()`,
 * left every token undefined at once.
 *
 * The shell now owns it. Each TalentTrack entry point that enqueues
 * `tokens.css` puts its output inside a `.tt-root` wrapper of its own
 * (`wrap()`), and the body class is added here, from core, as a
 * convenience for custom CSS written against `body.tt-root`. A nested
 * `.tt-root` is harmless: the token sheets give it the same values.
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
