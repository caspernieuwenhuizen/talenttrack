<?php
namespace TT\Modules\TeamDevelopment\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * PitchSvg — renders a proportionally-correct football pitch as an
 * inline SVG. Replaces the v1 gradient-div + pseudo-elements approach
 * (which had no real pitch markings and the wrong aspect ratio).
 *
 * Geometry follows the FIFA Laws of the Game preferred dimensions
 * (105m × 68m). Internally we use decimetres so the viewBox is
 * `0 0 680 1050` — keeps every measurement an integer and stays
 * resolution-independent.
 *
 * Pitch markings drawn (top to bottom of the rendered pitch, with
 * y=0 at the top goal line, y=1050 at the bottom):
 *
 *   - Touchlines + goal lines (the outer rectangle)
 *   - Top penalty box (165dm × 403.2dm centered on x=340)
 *   - Top goal area (55dm × 183.2dm centered on x=340)
 *   - Top penalty spot at (340, 110)
 *   - Top penalty arc (radius 91.5dm centered on penalty spot)
 *   - Halfway line at y=525
 *   - Centre circle (radius 91.5dm centered on (340, 525))
 *   - Centre spot at (340, 525)
 *   - Bottom penalty box / goal area / spot / arc (mirror)
 *   - Four corner arcs (radius 10dm)
 *
 * Colors come from CSS custom properties so the component picks up
 * whatever the install's brand-style sets:
 *
 *   --tt-pitch-grass — main pitch fill (default #4ea35f)
 *   --tt-pitch-grass-2 — secondary stripe (default #3c8a4d)
 *   --tt-pitch-line — line color (default rgba(255,255,255,0.85))
 *
 * The render-mode flag controls perspective:
 *
 *   - `flat` (default) — top-down 2D pitch, accurate proportions
 *   - `isometric` — preserves the v1 tilted look as an opt-in
 *
 * Slots are rendered as HTML overlays positioned via percentage
 * offsets from the pitch corners (matching the slots_json `pos.x`
 * and `pos.y` semantics: 0,0 = top-left, 1,1 = bottom-right).
 */
final class PitchSvg {

    public const MODE_FLAT      = 'flat';
    public const MODE_ISOMETRIC = 'isometric';

    /**
     * Render the pitch + slot markers as an HTML block.
     *
     * @param list<array<string,mixed>>                                                                                      $slots
     * @param array<string, array{player_id:int, player_name:string, score:float, has_data:bool}>                            $suggested
     * @param string                                                                                                         $mode self::MODE_FLAT or self::MODE_ISOMETRIC
     */
    public static function render( array $slots, array $suggested, string $mode = self::MODE_FLAT ): void {
        $is_iso = ( $mode === self::MODE_ISOMETRIC );
        ?>
        <style>
            .tt-pitch-wrap {
                <?php if ( $is_iso ) : ?>perspective: 1100px;<?php endif; ?>
                margin: 16px 0 24px;
                width: 100%;
                max-width: 760px;
                /* Defaults — overridable via brand-style tokens. */
                --tt-pitch-grass:   var( --tt-pitch-grass-token,   #4ea35f );
                --tt-pitch-grass-2: var( --tt-pitch-grass-2-token, #3c8a4d );
                --tt-pitch-line:    var( --tt-pitch-line-token,    rgba(255,255,255,0.85) );
            }
            .tt-pitch {
                <?php if ( $is_iso ) : ?>
                transform: rotateX(28deg);
                transform-origin: 50% 100%;
                <?php endif; ?>
                position: relative;
                aspect-ratio: 68 / 105;
                width: 100%;
                border-radius: 14px;
                overflow: hidden;
                box-shadow: 0 18px 36px rgba(0,0,0,0.14);
            }
            .tt-pitch-svg {
                position: absolute;
                inset: 0;
                width: 100%;
                height: 100%;
                display: block;
            }
            .tt-pitch-svg .tt-pitch-line {
                fill: none;
                stroke: var( --tt-pitch-line );
                stroke-width: 3;
                stroke-linecap: square;
                vector-effect: non-scaling-stroke;
            }
            .tt-pitch-svg .tt-pitch-spot {
                fill: var( --tt-pitch-line );
            }
            .tt-pitch-slot {
                position: absolute;
                transform: translate(-50%, -50%) <?php echo $is_iso ? 'rotateX(-28deg)' : ''; ?>;
                transform-origin: 50% 50%;
                background: #fff;
                border: 2px solid #1a1d21;
                border-radius: 50%;
                width: 56px; height: 56px;
                display: flex; flex-direction: column; align-items: center; justify-content: center;
                font-size: 11px; line-height: 1.1;
                color: #1a1d21;
                text-align: center;
                box-shadow: 0 4px 8px rgba(0,0,0,0.18);
                cursor: help;
            }
            .tt-pitch-slot strong { font-size: 11px; }
            .tt-pitch-slot .tt-slot-score {
                background: #1d7874; color: #fff; border-radius: 10px;
                padding: 1px 6px; font-size: 10px; margin-top: 2px;
            }
            .tt-pitch-slot.tt-fit-low .tt-slot-score    { background: #b32d2e; }
            .tt-pitch-slot.tt-fit-mid .tt-slot-score    { background: #c9962a; }
            .tt-pitch-slot.tt-fit-unknown               { background: #f6f7f7; border-color: #8a9099; color: #5b6e75; }
            .tt-pitch-slot.tt-fit-unknown .tt-slot-score { background: #8a9099; }
            .tt-pitch-slot.tt-slot-empty                { background: #f6f7f7; border-style: dashed; border-color: #8a9099; color: #8a9099; }
            .tt-pitch-slot.tt-slot-empty .tt-slot-name  { font-size: 9px; }
            @media (max-width: 720px) {
                .tt-pitch-slot { width: 48px; height: 48px; font-size: 10px; }
                .tt-pitch-slot strong { font-size: 10px; }
            }
        </style>
        <div class="tt-pitch-wrap">
            <div class="tt-pitch" style="background: linear-gradient(180deg, var(--tt-pitch-grass) 0%, var(--tt-pitch-grass-2) 100%);">
                <?php self::renderSvgMarkings(); ?>
                <?php self::renderSlots( $slots, $suggested ); ?>
            </div>
        </div>
        <?php
    }

    /**
     * Inline SVG with all standard markings. viewBox in decimetres
     * (real pitch is 68m wide × 105m long → 680 × 1050 dm).
     */
    private static function renderSvgMarkings(): void {
        ?>
        <svg class="tt-pitch-svg" viewBox="0 0 680 1050" preserveAspectRatio="none" aria-hidden="true">
            <!-- Outer touchlines + goal lines -->
            <rect class="tt-pitch-line" x="20" y="20" width="640" height="1010" />

            <!-- Top penalty box (16.5m × 40.32m): half-width 201.6 dm centered on x=340; depth 165 dm from y=20 -->
            <rect class="tt-pitch-line" x="138.4" y="20" width="403.2" height="165" />
            <!-- Top goal area (5.5m × 18.32m): half-width 91.6 dm centered on x=340; depth 55 dm from y=20 -->
            <rect class="tt-pitch-line" x="248.4" y="20" width="183.2" height="55" />
            <!-- Top penalty spot at (340, 130): 11m = 110 dm from goal line at y=20 -->
            <circle class="tt-pitch-spot" cx="340" cy="130" r="3" />
            <!-- Top penalty arc — radius 91.5 dm centered on penalty spot, only the segment outside the penalty box. -->
            <!-- The arc enters from x = 340 - sqrt(91.5^2 - 55^2) ≈ 340 - 73.18 = 266.82 at y=185 (penalty box bottom) -->
            <!-- and exits at x ≈ 413.18 at y=185. SVG arc syntax: M start A rx ry x-axis-rotation large-arc-flag sweep-flag x y -->
            <path class="tt-pitch-line" d="M 266.82 185 A 91.5 91.5 0 0 0 413.18 185" />

            <!-- Halfway line -->
            <line class="tt-pitch-line" x1="20" y1="525" x2="660" y2="525" />
            <!-- Centre circle radius 91.5 dm -->
            <circle class="tt-pitch-line" cx="340" cy="525" r="91.5" />
            <!-- Centre spot -->
            <circle class="tt-pitch-spot" cx="340" cy="525" r="3" />

            <!-- Bottom penalty box (mirror) -->
            <rect class="tt-pitch-line" x="138.4" y="865" width="403.2" height="165" />
            <!-- Bottom goal area -->
            <rect class="tt-pitch-line" x="248.4" y="975" width="183.2" height="55" />
            <!-- Bottom penalty spot -->
            <circle class="tt-pitch-spot" cx="340" cy="920" r="3" />
            <!-- Bottom penalty arc — mirror of the top -->
            <path class="tt-pitch-line" d="M 266.82 865 A 91.5 91.5 0 0 1 413.18 865" />

            <!-- Corner arcs — radius 10 dm at each corner. -->
            <path class="tt-pitch-line" d="M 30 20 A 10 10 0 0 0 20 30" />
            <path class="tt-pitch-line" d="M 660 30 A 10 10 0 0 0 650 20" />
            <path class="tt-pitch-line" d="M 20 1020 A 10 10 0 0 0 30 1030" />
            <path class="tt-pitch-line" d="M 650 1030 A 10 10 0 0 0 660 1020" />
        </svg>
        <?php
    }

    /**
     * @param list<array<string,mixed>>                                                                                      $slots
     * @param array<string, array{player_id:int, player_name:string, score:float, has_data:bool}>                            $suggested
     */
    private static function renderSlots( array $slots, array $suggested ): void {
        foreach ( $slots as $slot ) {
            $label = (string) ( $slot['label'] ?? '' );
            if ( $label === '' ) continue;
            $x = (float) ( $slot['pos']['x'] ?? 0.5 );
            $y = (float) ( $slot['pos']['y'] ?? 0.5 );
            $assign = $suggested[ $label ] ?? null;

            if ( $assign === null ) {
                self::renderEmptySlot( $label, $x, $y );
                continue;
            }

            $has_data   = ! empty( $assign['has_data'] );
            $score      = (float) ( $assign['score'] ?? 0.0 );
            $name       = (string) ( $assign['player_name'] ?? '' );
            $first_name = $name !== '' ? explode( ' ', $name )[0] : '';

            if ( ! $has_data ) {
                self::renderUnknownSlot( $label, $x, $y, $first_name, $name );
                continue;
            }

            $fit_class = $score >= 4.0 ? '' : ( $score >= 3.0 ? 'tt-fit-mid' : 'tt-fit-low' );
            $tip = sprintf(
                /* translators: 1: player, 2: slot label, 3: score */
                __( '%1$s — best fit at %2$s (%3$.2f)', 'talenttrack' ),
                $name, $label, $score
            );
            ?>
            <div class="tt-pitch-slot <?php echo esc_attr( $fit_class ); ?>"
                 style="left:<?php echo esc_attr( (string) ( $x * 100 ) ); ?>%; top:<?php echo esc_attr( (string) ( $y * 100 ) ); ?>%;"
                 title="<?php echo esc_attr( $tip ); ?>">
                <strong><?php echo esc_html( $label ); ?></strong>
                <?php if ( $first_name !== '' ) : ?>
                    <span class="tt-slot-name" style="font-size:9px; color:#5b6e75;"><?php echo esc_html( $first_name ); ?></span>
                    <span class="tt-slot-score"><?php echo esc_html( number_format_i18n( $score, 2 ) ); ?></span>
                <?php endif; ?>
            </div>
            <?php
        }
    }

    private static function renderEmptySlot( string $label, float $x, float $y ): void {
        $tip = sprintf(
            /* translators: %s: slot label */
            __( 'No candidate for %s — roster is smaller than the formation needs.', 'talenttrack' ),
            $label
        );
        ?>
        <div class="tt-pitch-slot tt-slot-empty"
             style="left:<?php echo esc_attr( (string) ( $x * 100 ) ); ?>%; top:<?php echo esc_attr( (string) ( $y * 100 ) ); ?>%;"
             title="<?php echo esc_attr( $tip ); ?>">
            <strong><?php echo esc_html( $label ); ?></strong>
            <span class="tt-slot-name">—</span>
        </div>
        <?php
    }

    private static function renderUnknownSlot( string $label, float $x, float $y, string $first_name, string $full_name ): void {
        $tip = sprintf(
            /* translators: 1: player, 2: slot label */
            __( '%1$s suggested for %2$s based on roster only — not enough evaluations to compute a fit score yet.', 'talenttrack' ),
            $full_name, $label
        );
        ?>
        <div class="tt-pitch-slot tt-fit-unknown"
             style="left:<?php echo esc_attr( (string) ( $x * 100 ) ); ?>%; top:<?php echo esc_attr( (string) ( $y * 100 ) ); ?>%;"
             title="<?php echo esc_attr( $tip ); ?>">
            <strong><?php echo esc_html( $label ); ?></strong>
            <?php if ( $first_name !== '' ) : ?>
                <span class="tt-slot-name" style="font-size:9px;"><?php echo esc_html( $first_name ); ?></span>
            <?php endif; ?>
            <span class="tt-slot-score">?</span>
        </div>
        <?php
    }
}
