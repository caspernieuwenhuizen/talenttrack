<?php
namespace TT\Shared\Frontend\Components;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Shared\Icons\IconRenderer;

/**
 * TileIconChip (#1553) — the shared "tile icon chip" treatment.
 *
 * Renders a Phosphor duotone glyph (from `assets/icons/duotone/`) inside
 * an accent-tinted rounded square. The chip is the design-of-record for
 * TILE surfaces only: the persona dashboard tiles
 * (`FrontendTileGrid` / `FrontendSectionedTileGrid`) and the Configuration
 * tiles (`.tt-cfg-tile-icon`). Small inline icons (buttons, wp-admin menu)
 * keep the line set via `IconRenderer::render()` — tiny duotone reads muddy.
 *
 * Chip spec (from the mockup design-of-record):
 *   - box ~52px, border-radius ~14px, glyph 32px centered
 *   - background: color-mix(in srgb, <accent> 14%, #fff)
 *   - glyph color: <accent> (drives the duotone via currentColor)
 *
 * Mobile-first: the chip is sized in `rem` so it scales cleanly at 360px
 * and degrades gracefully where `color-mix()` is unsupported (a flat tint
 * fallback is emitted first). The accent colour is passed per-tile.
 */
final class TileIconChip {

    /** Default accent when a tile declares no colour. */
    private const DEFAULT_ACCENT = '#5b6e75';

    /**
     * Return the chip HTML: a `.tt-tile-chip` span tinted to `$accent`
     * wrapping the duotone glyph for `$icon`. Returns the empty string
     * when no icon key is given (caller renders no chip).
     *
     * @param string $icon   tile icon key (resolves to a duotone variant,
     *                        falling back to the inline icon)
     * @param string $accent CSS colour for the glyph + tint base
     * @param array<string,string> $attrs extra attributes for the chip span
     */
    public static function render( string $icon, string $accent = '', array $attrs = [] ): string {
        $icon = trim( $icon );
        if ( $icon === '' ) {
            return '';
        }
        $accent = self::sanitizeAccent( $accent );

        $glyph = IconRenderer::renderDuotone( $icon, [
            'class'  => 'tt-tile-chip__glyph',
            'width'  => 32,
            'height' => 32,
        ] );

        $classes = 'tt-tile-chip';
        if ( isset( $attrs['class'] ) ) {
            $classes .= ' ' . $attrs['class'];
            unset( $attrs['class'] );
        }

        // `--tt-chip-accent` drives both the glyph colour and the tint
        // (via color-mix in the enqueued/inline CSS).
        $style = '--tt-chip-accent:' . $accent . ';';
        if ( isset( $attrs['style'] ) ) {
            $style .= $attrs['style'];
            unset( $attrs['style'] );
        }

        $attr_str = ' class="' . esc_attr( $classes ) . '" style="' . esc_attr( $style ) . '"';
        foreach ( $attrs as $k => $v ) {
            $attr_str .= ' ' . $k . '="' . esc_attr( (string) $v ) . '"';
        }

        return '<span' . $attr_str . '>' . $glyph . '</span>';
    }

    /**
     * The shared chip CSS. Idempotent per request — emits a single
     * `<style>` block the first time it's called, nothing thereafter, so
     * multiple tile surfaces on one page don't duplicate it. Mobile-first
     * base sizing; scales via the optional `--tt-tile-scale` custom
     * property the dashboard grid already sets.
     */
    public static function styles(): string {
        static $emitted = false;
        if ( $emitted ) {
            return '';
        }
        $emitted = true;

        return <<<'CSS'
<style>
.tt-tile-chip {
    --tt-chip-accent: #5b6e75;
    flex-shrink: 0;
    width: calc(3.25rem * var(--tt-tile-scale, 1));
    height: calc(3.25rem * var(--tt-tile-scale, 1));
    border-radius: 0.875rem;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    /* Flat tint fallback first, then color-mix where supported. */
    background: var(--tt-chip-accent);
    background: color-mix(in srgb, var(--tt-chip-accent) 14%, #fff);
    color: var(--tt-chip-accent);
}
.tt-tile-chip .tt-tile-chip__glyph {
    width: calc(2rem * var(--tt-tile-scale, 1));
    height: calc(2rem * var(--tt-tile-scale, 1));
    color: inherit;
}
@supports not (background: color-mix(in srgb, red 14%, #fff)) {
    /* Fallback: keep the glyph readable on a solid-ish tint by lightening
       the chip with opacity rather than a full accent fill. */
    .tt-tile-chip { background: var(--tt-chip-accent); opacity: 0.92; }
    .tt-tile-chip .tt-tile-chip__glyph { color: #fff; }
}
</style>
CSS;
    }

    /**
     * Normalise an accent colour to a safe CSS hex/rgb token. Falls back
     * to the default accent for anything unrecognised so the inline
     * `style` attribute can never carry hostile content.
     */
    private static function sanitizeAccent( string $accent ): string {
        $accent = trim( $accent );
        if ( $accent === '' ) {
            return self::DEFAULT_ACCENT;
        }
        // #rgb / #rrggbb / #rrggbbaa
        if ( preg_match( '/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $accent ) ) {
            return $accent;
        }
        // rgb()/rgba() — digits, commas, dots, %, spaces only.
        if ( preg_match( '/^rgba?\(\s*[\d.,%\s]+\)$/', $accent ) ) {
            return $accent;
        }
        return self::DEFAULT_ACCENT;
    }
}
