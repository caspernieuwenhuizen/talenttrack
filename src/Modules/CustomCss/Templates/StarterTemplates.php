<?php
namespace TT\Modules\CustomCss\Templates;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * StarterTemplates — three light-leaning starter themes for the
 * #0064 custom-CSS authoring surface.
 *
 * Each template returns a complete CSS body that the operator can
 * drop in via the "Apply template" button on Path A / Path B. Sets
 * `--tt-*` token values on `.tt-root` so it composes with TT's
 * layout cap and CLAUDE.md § 2 mobile-first guarantees — only colour
 * + typography tokens are touched, never layout.
 *
 * All three lean toward lighter colour palettes per shaping note —
 * easier on the eye in the academy office during the day, plus the
 * core dashboard is light by default so darker overrides need more
 * careful sub-token coordination.
 */
final class StarterTemplates {

    public const TEMPLATE_FRESH_LIGHT     = 'fresh-light';
    public const TEMPLATE_CLASSIC_FOOTBALL = 'classic-football';
    public const TEMPLATE_MINIMAL         = 'minimal';

    /** @return array<string, array{label:string, description:string, css:string}> */
    public static function all(): array {
        return [
            self::TEMPLATE_FRESH_LIGHT => [
                'label'       => __( 'Fresh light', 'talenttrack' ),
                'description' => __( 'Soft mint-and-teal palette with rounded corners and a soft-shadow card style. Reads bright in daylight; works well for academies that lean modern.', 'talenttrack' ),
                'css'         => self::cssFreshLight(),
            ],
            self::TEMPLATE_CLASSIC_FOOTBALL => [
                'label'       => __( 'Classic football', 'talenttrack' ),
                'description' => __( 'Forest green + gold + cream — the traditional academy crest palette. Sharper corners and a slightly heavier card border for a club-shop feel.', 'talenttrack' ),
                'css'         => self::cssClassicFootball(),
            ],
            self::TEMPLATE_MINIMAL => [
                'label'       => __( 'Minimal', 'talenttrack' ),
                'description' => __( 'Neutral greys with a single charcoal accent. Skips drop shadows and rounds corners only slightly. Sits behind any club brand without competing with it.', 'talenttrack' ),
                'css'         => self::cssMinimal(),
            ],
        ];
    }

    public static function find( string $key ): ?array {
        $all = self::all();
        return $all[ $key ] ?? null;
    }

    private static function cssFreshLight(): string {
        return <<<CSS
/* Fresh light — TalentTrack starter template (#0064).
 * Only --tt-* tokens are touched; layout stays untouched. */

.tt-root {
    --tt-primary:    #2a9d8f;
    --tt-secondary:  #f4a261;
    --tt-accent:     #2a9d8f;
    --tt-success:    #2a9d8f;
    --tt-info:       #4cb8c4;
    --tt-warning:    #e9c46a;
    --tt-danger:     #e76f51;
    --tt-focus-ring: #4cb8c4;

    --tt-bg:         #f7fafa;
    --tt-surface:    #ffffff;
    --tt-line:       #d4e7e3;
    --tt-text:       #14342f;
    --tt-muted:      #547875;

    --tt-r-md:       12px;
    --tt-r-lg:       16px;
}

.tt-root .tt-panel,
.tt-root .tt-card,
.tt-root .tt-mc-card,
.tt-root .tt-cfg-tile {
    box-shadow: 0 4px 14px rgba(42, 157, 143, 0.08);
}
CSS;
    }

    private static function cssClassicFootball(): string {
        return <<<CSS
/* Classic football — TalentTrack starter template (#0064).
 * Forest green + gold + cream. */

.tt-root {
    --tt-primary:    #1d5b3f;
    --tt-secondary:  #c9a227;
    --tt-accent:     #1d5b3f;
    --tt-success:    #1d5b3f;
    --tt-info:       #4a6c5d;
    --tt-warning:    #c9a227;
    --tt-danger:     #8b1d1d;
    --tt-focus-ring: #c9a227;

    --tt-bg:         #faf6ec;
    --tt-surface:    #ffffff;
    --tt-line:       #e0d4b3;
    --tt-text:       #1a2e23;
    --tt-muted:      #5b6e5e;

    --tt-r-md:       6px;
    --tt-r-lg:       8px;
}

.tt-root .tt-panel,
.tt-root .tt-card,
.tt-root .tt-mc-card,
.tt-root .tt-cfg-tile {
    border-color: var(--tt-secondary);
    border-width: 1px;
}
CSS;
    }

    private static function cssMinimal(): string {
        return <<<CSS
/* Minimal — TalentTrack starter template (#0064).
 * Neutral greys with one charcoal accent. */

.tt-root {
    --tt-primary:    #2c3338;
    --tt-secondary:  #6b7280;
    --tt-accent:     #2c3338;
    --tt-success:    #475569;
    --tt-info:       #64748b;
    --tt-warning:    #b45309;
    --tt-danger:     #991b1b;
    --tt-focus-ring: #2c3338;

    --tt-bg:         #ffffff;
    --tt-surface:    #ffffff;
    --tt-line:       #e5e7eb;
    --tt-text:       #111827;
    --tt-muted:      #6b7280;

    --tt-r-md:       4px;
    --tt-r-lg:       6px;
}

.tt-root .tt-panel,
.tt-root .tt-card,
.tt-root .tt-mc-card,
.tt-root .tt-cfg-tile {
    box-shadow: none;
}
CSS;
    }
}
