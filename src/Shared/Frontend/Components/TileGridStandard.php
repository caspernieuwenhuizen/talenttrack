<?php
namespace TT\Shared\Frontend\Components;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;

/**
 * TileGridStandard (#1587) — the single source of truth for tile size and
 * layout across every tile surface in the plugin.
 *
 * Before #1587 each tile surface hardcoded its own grid/card metrics:
 * the dashboard grid (`FrontendTileGrid`), the Configuration tiles
 * (`FrontendConfigurationView::tileGridStyles`), the Reports launcher and
 * the shared sectioned grid (`FrontendSectionedTileGrid`), plus the Teams
 * "Team development" pair. They drifted (220px vs 240px min-width, 8px vs
 * 14px padding) and could never respond to an academy-wide preference.
 *
 * This helper centralises:
 *   1. A small set of CSS custom properties that describe a tile grid —
 *      `--tt-tile-min-width`, `--tt-tile-gap`, `--tt-tile-padding`,
 *      `--tt-tile-min-height`, `--tt-tile-radius`.
 *   2. A shared CSS block (`styles()`) that every tile surface emits once;
 *      the grid + card classes consume the custom properties so they all
 *      render identically.
 *   3. A preset map (Compact / Comfortable / Spacious) resolved from the
 *      academy-wide `tile_appearance` config key, emitted onto a wrapper
 *      via `openWrap()` / `wrapVars()`.
 *
 * Preset model (locked, #1587):
 *   - `comfortable` (default) — today's config-tile standard.
 *   - `compact`     — denser/smaller for information-dense screens.
 *   - `spacious`    — larger, roomier tiles.
 *
 * Mobile-first: the grid uses `repeat(auto-fill, minmax(var(--tt-tile-min-width), 1fr))`
 * so every preset reflows to fewer columns as the viewport narrows and
 * collapses to a single column at 360px (Spacious never causes horizontal
 * scroll). Tap targets stay >= 48px in every preset.
 *
 * `tile_scale` reconciliation: the legacy `tile_scale` percentage (#0036)
 * remains honoured as an *additional* multiplier on the resolved
 * `--tt-tile-min-width` so nobody's existing override breaks. When
 * `tile_scale` is 100 / unset the preset alone governs sizing.
 */
final class TileGridStandard {

    /** Config key holding the academy-wide preset. */
    public const CONFIG_KEY = 'tile_appearance';

    /** Default preset — equals the pre-#1587 config-tile standard. */
    public const DEFAULT_PRESET = 'comfortable';

    /**
     * Config key holding the academy-wide tile *layout* (#1598). This is a
     * separate axis from the size preset above: `row` keeps the icon to the
     * left of a stacked title+description (the pre-#1598 behaviour);
     * `stacked` puts the icon and title together on the first line with the
     * description spanning the full tile width beneath. Size and layout
     * combine freely (e.g. spacious + stacked).
     */
    public const LAYOUT_CONFIG_KEY = 'tile_layout';

    /** Default layout — the pre-#1598 icon-left arrangement. */
    public const DEFAULT_LAYOUT = 'row';

    /** Valid layout keys, in settings-dropdown order. */
    private const LAYOUTS = [ 'row', 'stacked' ];

    /**
     * Preset → custom-property values. `comfortable` reproduces the
     * pre-#1587 config-tile metrics exactly (min-width 220px, gap 10px,
     * padding 14px, min-height 76px, radius 8px) so the liked standard
     * does not visually regress.
     *
     * @var array<string, array{min_width:string, gap:string, padding:string, min_height:string, radius:string}>
     */
    private const PRESETS = [
        'compact' => [
            'min_width'  => '180px',
            'gap'        => '8px',
            'padding'    => '10px',
            'min_height' => '64px',
            'radius'     => '8px',
        ],
        'comfortable' => [
            'min_width'  => '220px',
            'gap'        => '10px',
            'padding'    => '14px',
            'min_height' => '76px',
            'radius'     => '8px',
        ],
        'spacious' => [
            'min_width'  => '280px',
            'gap'        => '14px',
            'padding'    => '18px',
            'min_height' => '92px',
            'radius'     => '10px',
        ],
    ];

    /**
     * The three preset keys, in display order, for building the settings
     * dropdown. Labels are translated at the call site so this stays a
     * pure data accessor.
     *
     * @return string[]
     */
    public static function presetKeys(): array {
        return [ 'compact', 'comfortable', 'spacious' ];
    }

    /**
     * Resolve the active preset key from `tt_config`, falling back to the
     * default for an unknown / unset value.
     */
    public static function activePreset(): string {
        $raw = QueryHelpers::get_config( self::CONFIG_KEY, self::DEFAULT_PRESET );
        $key = strtolower( trim( (string) $raw ) );
        return isset( self::PRESETS[ $key ] ) ? $key : self::DEFAULT_PRESET;
    }

    /**
     * The layout keys, in display order, for building the settings dropdown.
     * Labels are translated at the call site.
     *
     * @return string[]
     */
    public static function layoutKeys(): array {
        return self::LAYOUTS;
    }

    /**
     * Resolve the active tile layout from `tt_config`, falling back to the
     * default (`row`) for an unknown / unset value.
     */
    public static function activeLayout(): string {
        $raw = QueryHelpers::get_config( self::LAYOUT_CONFIG_KEY, self::DEFAULT_LAYOUT );
        $key = strtolower( trim( (string) $raw ) );
        return in_array( $key, self::LAYOUTS, true ) ? $key : self::DEFAULT_LAYOUT;
    }

    /**
     * The `data-tt-tile-layout` attribute string to stamp on a tile-grid
     * wrapper so the shared/per-surface CSS can switch arrangement. Defaults
     * to the active layout.
     */
    public static function layoutAttr( ?string $layout = null ): string {
        $key = $layout !== null && in_array( $layout, self::LAYOUTS, true ) ? $layout : self::activeLayout();
        return 'data-tt-tile-layout="' . esc_attr( $key ) . '"';
    }

    /**
     * Build the inline `--tt-tile-*` custom-property declaration string for
     * a given preset (defaults to the active one). The min-width folds in
     * the legacy `tile_scale` multiplier when it is set to a non-100 value.
     *
     * @param string|null $preset preset key, or null for the active preset
     * @return string e.g. "--tt-tile-min-width:220px;--tt-tile-gap:10px;…"
     */
    public static function cssVars( ?string $preset = null ): string {
        $key    = $preset !== null && isset( self::PRESETS[ $preset ] ) ? $preset : self::activePreset();
        $values = self::PRESETS[ $key ];

        $min_width = $values['min_width'];
        $scale     = (int) QueryHelpers::get_config( 'tile_scale', '100' );
        if ( $scale < 50 || $scale > 150 ) {
            $scale = 100;
        }

        // #1663 — an explicit pixel width wins over both the preset and the
        // legacy percentage scale; blank/out-of-range falls back to them.
        $width_px = (int) QueryHelpers::get_config( 'tile_min_width', '0' );
        if ( $width_px >= 140 && $width_px <= 400 ) {
            $min_width = $width_px . 'px';
        } elseif ( $scale !== 100 ) {
            // Honour the legacy override as a multiplier on the preset's
            // min-width. calc() keeps the unit; the preset still governs
            // gap / padding / min-height / radius.
            $min_width = 'calc(' . $values['min_width'] . ' * ' . ( $scale / 100 ) . ')';
        }

        $vars = '--tt-tile-min-width:' . $min_width . ';'
            . '--tt-tile-gap:' . $values['gap'] . ';'
            . '--tt-tile-padding:' . $values['padding'] . ';'
            . '--tt-tile-min-height:' . $values['min_height'] . ';'
            . '--tt-tile-radius:' . $values['radius'] . ';';

        // #1663 — explicit pixel icon-glyph size. Unset → the CSS var()
        // fallback keeps the scale-derived sizing, so nothing changes.
        $icon_px = (int) QueryHelpers::get_config( 'tile_icon_size', '0' );
        if ( $icon_px >= 14 && $icon_px <= 64 ) {
            $vars .= '--tt-tile-icon-size:' . $icon_px . 'px;';
        }

        return $vars;
    }

    /**
     * Open a `.tt-tile-std` wrapper carrying the active preset's custom
     * properties. Every tile surface wraps its grid(s) in this so the
     * shared grid/card CSS resolves the right metrics. Caller must close
     * the wrapper with `closeWrap()`.
     */
    public static function openWrap(): string {
        return '<div class="tt-tile-std" style="' . esc_attr( self::cssVars() ) . '">';
    }

    /** Close the wrapper opened by `openWrap()`. */
    public static function closeWrap(): string {
        return '</div>';
    }

    /**
     * The shared tile-grid + tile-card CSS. Idempotent per request — emits
     * a single `<style>` block the first time it is called and nothing
     * thereafter, so several tile surfaces on one page don't duplicate it.
     *
     * The grid + card classes here consume the `--tt-tile-*` custom
     * properties set by `openWrap()`. Defaults baked into each `var()`
     * fallback equal the Comfortable preset, so a stray tile rendered
     * outside a `.tt-tile-std` wrapper still looks right.
     *
     * Mobile-first: `auto-fill minmax(var(--tt-tile-min-width), 1fr)`
     * reflows to fewer columns and collapses to one column at 360px.
     */
    public static function styles(): string {
        static $emitted = false;
        if ( $emitted ) {
            return '';
        }
        $emitted = true;

        return <<<'CSS'
<style>
.tt-tile-std {
    --tt-tile-min-width: 220px;
    --tt-tile-gap: 10px;
    --tt-tile-padding: 14px;
    --tt-tile-min-height: 76px;
    --tt-tile-radius: 8px;
}
.tt-tile-grid-std {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(var(--tt-tile-min-width, 220px), 1fr));
    gap: var(--tt-tile-gap, 10px);
}
.tt-tile-card-std {
    display: block;
    background: #fff;
    border: 1px solid var(--tt-line, #e5e7ea);
    border-radius: var(--tt-tile-radius, 8px);
    padding: var(--tt-tile-padding, 14px);
    min-height: var(--tt-tile-min-height, 76px);
    text-decoration: none;
    color: #1a1d21;
    box-shadow: var(--tt-shadow-sm, none);
    transition: transform var(--tt-motion-duration, 180ms) var(--tt-motion-easing, cubic-bezier(0.2, 0.8, 0.2, 1)),
                box-shadow var(--tt-motion-duration, 180ms) var(--tt-motion-easing, ease),
                border-color var(--tt-motion-duration, 180ms) var(--tt-motion-easing, ease);
}
.tt-tile-card-std:hover,
.tt-tile-card-std:focus,
.tt-tile-card-std:focus-visible {
    transform: translateY(-1px);
    box-shadow: var(--tt-shadow-md, 0 4px 12px rgba(0,0,0,0.08));
    border-color: #d0d4d8;
    color: #1a1d21;
}
@media (max-width: 360px) {
    .tt-tile-grid-std { grid-template-columns: 1fr; }
}
@media (prefers-reduced-motion: reduce) {
    .tt-tile-card-std { transition: none; }
    .tt-tile-card-std:hover, .tt-tile-card-std:focus { transform: none; }
}
</style>
CSS;
    }
}
