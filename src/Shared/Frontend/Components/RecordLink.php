<?php
namespace TT\Shared\Frontend\Components;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * RecordLink — universal "open this record's detail page" link wrapper
 * (#0063).
 *
 * The convention introduced ad-hoc in v3.62.0 was a `tt-record-link`
 * CSS class wrapping a goal / activity row. This helper formalises it
 * so every admin table + dashboard tile + list view emits the same
 * markup + class + hover behaviour — a single edit fixes all sites.
 *
 * Two render modes:
 *   - `inline()` returns a single `<a>` containing escaped label text
 *     (safe for plain string labels).
 *   - `wrap()` echoes opening + closing tags around arbitrary inner
 *     HTML the caller emits between them. Use when the row already
 *     carries pills, badges, or sub-elements.
 *
 * Both emit `class="tt-record-link"`, which the global stylesheet
 * styles for hover / focus-visible. Callers can append extra classes
 * via `$css_class`.
 *
 * The link uses `rel="noopener"` only when `$external = true`; for
 * internal dashboard links (the common case) it stays plain so the
 * back button + history work cleanly.
 */
final class RecordLink {

    public const CLASS_BASE = 'tt-record-link';

    /**
     * Render an `<a class="tt-record-link">label</a>` link. Use for
     * plain text labels — name cells in admin tables, summary lines.
     */
    public static function inline( string $label, string $detail_url, string $css_class = '' ): string {
        if ( $label === '' || $detail_url === '' ) return esc_html( $label );
        $cls = self::CLASS_BASE . ( $css_class !== '' ? ' ' . $css_class : '' );
        return sprintf(
            '<a class="%s" href="%s">%s</a>',
            esc_attr( $cls ),
            esc_url( $detail_url ),
            esc_html( $label )
        );
    }

    /**
     * Echo an opening `<a class="tt-record-link">` so the caller can
     * emit arbitrary inner HTML (badges, pills, multi-line) and then
     * call `close()` to emit `</a>`. Use when the row needs more
     * structure than a single text label.
     */
    public static function wrap( string $detail_url, string $css_class = '' ): void {
        $cls = self::CLASS_BASE . ( $css_class !== '' ? ' ' . $css_class : '' );
        echo '<a class="' . esc_attr( $cls ) . '" href="' . esc_url( $detail_url ) . '">';
    }

    /**
     * Closing partner for `wrap()`. Always echo this in a try/finally
     * if the inner block can throw, so the link doesn't get orphaned.
     */
    public static function close(): void {
        echo '</a>';
    }

    /**
     * Helper for admin pages: build a frontend dashboard URL for a
     * given list slug + record id, so admins clicking a record name in
     * a wp-admin table land on the frontend detail view.
     *
     * Example: detailUrlFor('players', 42) →
     *   https://example.com/?tt_view=players&id=42
     */
    public static function detailUrlFor( string $list_slug, int $id ): string {
        if ( $list_slug === '' || $id <= 0 ) return '';
        $base = home_url( '/' );
        $url  = add_query_arg( [ 'tt_view' => $list_slug, 'id' => $id ], $base );
        return (string) $url;
    }
}
