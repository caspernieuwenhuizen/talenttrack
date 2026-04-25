<?php
namespace TT\Modules\Methodology\Components;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Methodology\Helpers\MultilingualField;
use TT\Modules\Methodology\Repositories\FormationsRepository;

/**
 * FormationDiagram — reusable SVG renderer for football formations.
 *
 * Inputs come from `tt_formations.diagram_data_json`:
 *
 *   {
 *     "positions": {
 *       "1": {"x": 50, "y": 92, "label": "K"},
 *       "2": {"x": 82, "y": 75, "label": "RB"},
 *       ...
 *     }
 *   }
 *
 * Coordinates are normalized 0–100 (0,0 = top-left from the
 * defending team's perspective; the goal we're defending is at
 * y = 100). The renderer maps these onto an SVG viewBox of 100×140
 * so the pitch reads as a vertically-oriented field, which is the
 * shape coaches recognize.
 *
 * Optional inputs:
 *   - **highlight_position** (jersey number) → that node renders in
 *     accent color so the surrounding detail page can say "this is
 *     the player you're looking at."
 *   - **overlay_data** → a JSON-decoded structure with `arrows` and
 *     `zones`. v1 supports both as static decorations. Animation is
 *     a v2 concern.
 *
 * Single SVG — no Chart.js, no JS dependency. The diagram is
 * inline-styled and colors come from CSS variables so theme
 * inheritance (#0023) keeps it on-brand.
 */
class FormationDiagram {

    /** Render the diagram as inline SVG. Returns HTML. */
    public static function render( int $formation_id, array $opts = [] ): string {
        $repo = new FormationsRepository();
        $formation = $repo->find( $formation_id );
        if ( ! $formation ) return '';

        $diagram = MultilingualField::decode( $formation->diagram_data_json );
        if ( ! is_array( $diagram ) ) {
            // Fall back to a generic 4-2-3-1 layout if the formation row
            // doesn't carry diagram data yet (Casper hasn't authored
            // coordinates for this row, but the formation should still
            // render something sensible).
            $diagram = self::fallbackDiagram();
        }
        $positions = isset( $diagram['positions'] ) && is_array( $diagram['positions'] )
            ? $diagram['positions']
            : [];

        $highlight = isset( $opts['highlight_position'] ) ? (int) $opts['highlight_position'] : 0;
        $overlay   = $opts['overlay_data'] ?? null;
        $aria_label = isset( $opts['aria_label'] )
            ? (string) $opts['aria_label']
            : sprintf(
                /* translators: %s is the formation slug like 1-4-2-3-1 */
                __( 'Formation diagram: %s', 'talenttrack' ),
                (string) $formation->slug
            );

        ob_start();
        ?>
        <svg class="tt-formation-diagram" viewBox="0 0 100 140" role="img" aria-label="<?php echo esc_attr( $aria_label ); ?>" preserveAspectRatio="xMidYMid meet">
            <?php self::renderPitch(); ?>
            <?php if ( $overlay !== null ) self::renderOverlay( $overlay ); ?>
            <?php foreach ( $positions as $jersey => $pos ) :
                $jn   = (int) $jersey;
                $x    = isset( $pos['x'] ) ? (float) $pos['x'] : 50;
                $y    = isset( $pos['y'] ) ? (float) $pos['y'] : 70;
                $lbl  = isset( $pos['label'] ) ? (string) $pos['label'] : '';
                $is_h = ( $highlight > 0 && $highlight === $jn );
                $node_class = $is_h ? 'tt-fp-node tt-fp-node-highlight' : 'tt-fp-node';
                ?>
                <g class="<?php echo esc_attr( $node_class ); ?>" transform="translate(<?php echo esc_attr( (string) $x ); ?>,<?php echo esc_attr( (string) $y ); ?>)">
                    <circle r="5" />
                    <text y="1.5" text-anchor="middle" font-size="5" font-weight="700"><?php echo esc_html( (string) $jn ); ?></text>
                    <?php if ( $lbl !== '' ) : ?>
                        <text y="11" text-anchor="middle" font-size="3.4"><?php echo esc_html( $lbl ); ?></text>
                    <?php endif; ?>
                </g>
            <?php endforeach; ?>
        </svg>
        <?php
        return (string) ob_get_clean();
    }

    /** Pitch outline + center circle + penalty boxes. */
    private static function renderPitch(): void {
        ?>
        <rect x="2" y="2" width="96" height="136" rx="2" class="tt-fp-pitch" />
        <line x1="2" y1="70" x2="98" y2="70" class="tt-fp-line" />
        <circle cx="50" cy="70" r="9" class="tt-fp-line" fill="none" />
        <circle cx="50" cy="70" r="0.6" class="tt-fp-line" fill="currentColor" />
        <!-- Top (away) penalty box -->
        <rect x="22" y="2" width="56" height="18" class="tt-fp-line" fill="none" />
        <rect x="36" y="2" width="28" height="6"  class="tt-fp-line" fill="none" />
        <!-- Bottom (home) penalty box -->
        <rect x="22" y="120" width="56" height="18" class="tt-fp-line" fill="none" />
        <rect x="36" y="132" width="28" height="6"  class="tt-fp-line" fill="none" />
        <?php
    }

    /**
     * Optional overlay rendering — arrows and shaded zones.
     *
     * Overlay shape:
     *   {
     *     "arrows": [{"x1":..,"y1":..,"x2":..,"y2":..,"label":"...?"}],
     *     "zones":  [{"x":..,"y":..,"w":..,"h":..,"label":"...?"}]
     *   }
     *
     * @param mixed $overlay
     */
    private static function renderOverlay( $overlay ): void {
        if ( is_string( $overlay ) ) {
            $overlay = json_decode( $overlay, true );
        }
        if ( ! is_array( $overlay ) ) return;

        if ( ! empty( $overlay['zones'] ) && is_array( $overlay['zones'] ) ) {
            foreach ( $overlay['zones'] as $z ) {
                if ( ! is_array( $z ) ) continue;
                $x = (float) ( $z['x'] ?? 0 );
                $y = (float) ( $z['y'] ?? 0 );
                $w = (float) ( $z['w'] ?? 0 );
                $h = (float) ( $z['h'] ?? 0 );
                if ( $w <= 0 || $h <= 0 ) continue;
                printf(
                    '<rect x="%f" y="%f" width="%f" height="%f" class="tt-fp-zone" />',
                    $x, $y, $w, $h
                );
            }
        }

        if ( ! empty( $overlay['arrows'] ) && is_array( $overlay['arrows'] ) ) {
            // Define the arrowhead marker once per overlay.
            ?>
            <defs>
                <marker id="tt-fp-arrow-head" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="5" markerHeight="5" orient="auto-start-reverse">
                    <path d="M 0 0 L 10 5 L 0 10 z" />
                </marker>
            </defs>
            <?php
            foreach ( $overlay['arrows'] as $a ) {
                if ( ! is_array( $a ) ) continue;
                $x1 = (float) ( $a['x1'] ?? 0 );
                $y1 = (float) ( $a['y1'] ?? 0 );
                $x2 = (float) ( $a['x2'] ?? 0 );
                $y2 = (float) ( $a['y2'] ?? 0 );
                printf(
                    '<line x1="%f" y1="%f" x2="%f" y2="%f" class="tt-fp-arrow" marker-end="url(#tt-fp-arrow-head)" />',
                    $x1, $y1, $x2, $y2
                );
            }
        }
    }

    /**
     * @return array<string,mixed>
     */
    private static function fallbackDiagram(): array {
        // Minimal 1-4-2-3-1 layout when the formation row hasn't
        // been authored yet. Better than rendering an empty pitch.
        return [
            'positions' => [
                '1'  => [ 'x' => 50, 'y' => 124, 'label' => 'K' ],
                '2'  => [ 'x' => 82, 'y' => 102, 'label' => 'RB' ],
                '3'  => [ 'x' => 60, 'y' => 108, 'label' => 'CB' ],
                '4'  => [ 'x' => 40, 'y' => 108, 'label' => 'CB' ],
                '5'  => [ 'x' => 18, 'y' => 102, 'label' => 'LB' ],
                '6'  => [ 'x' => 60, 'y' => 78,  'label' => 'CDM' ],
                '8'  => [ 'x' => 40, 'y' => 78,  'label' => 'CDM' ],
                '7'  => [ 'x' => 80, 'y' => 50,  'label' => 'RW' ],
                '10' => [ 'x' => 50, 'y' => 50,  'label' => 'CAM' ],
                '11' => [ 'x' => 20, 'y' => 50,  'label' => 'LW' ],
                '9'  => [ 'x' => 50, 'y' => 24,  'label' => 'ST' ],
            ],
        ];
    }
}
