<?php
namespace TT\Shared\Frontend\Components;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * MaskedValue (#4690) — what a view prints where a sensitive field is
 * hidden from the reader (`RecordRedactor::isMasked()`).
 *
 * One word, "Hidden", in a neutral style, with an accessible label that
 * says why: the value exists and this role does not see it. It is never an
 * empty cell, which a reader would take for "not filled in".
 */
final class MaskedValue {

    private static bool $css_enqueued = false;

    /** The markup, escaped and ready to print. */
    public static function html(): string {
        self::enqueue();
        // The visible word for everyone, the reason for a screen reader: an
        // aria-label on a plain span is not read out everywhere.
        return sprintf(
            '<span class="tt-masked-value" title="%1$s"><span aria-hidden="true">%2$s</span><span class="tt-screen-reader-text">%3$s</span></span>',
            esc_attr__( 'Hidden for your role', 'talenttrack' ),
            esc_html_x( 'Hidden', 'masked field value', 'talenttrack' ),
            esc_html__( 'Hidden for your role', 'talenttrack' )
        );
    }

    public static function render(): void {
        echo self::html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in html().
    }

    /**
     * The stylesheet. Enqueued on first use, which on a dashboard render is
     * before the head is printed; a view that builds markup later can call
     * this up front.
     */
    public static function enqueue(): void {
        if ( self::$css_enqueued ) return;
        if ( ! function_exists( 'wp_enqueue_style' ) ) return;
        wp_enqueue_style(
            'tt-frontend-masked-value',
            TT_PLUGIN_URL . 'assets/css/frontend-masked-value.css',
            [ 'tt-public' ],
            TT_VERSION
        );
        self::$css_enqueued = true;
    }
}
