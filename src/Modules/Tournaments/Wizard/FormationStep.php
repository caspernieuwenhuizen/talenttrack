<?php
namespace TT\Modules\Tournaments\Wizard;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Shared\Wizards\WizardStepInterface;

/**
 * Step 2 — Default formation. Radio-card grid sourced from the
 * `tournament_formation` lookup. Each card carries a tiny hand-drawn
 * dot glyph of the formation shape (per #975 mockup decision; not
 * PitchSvg).
 *
 * Per-match override is possible on the matches step + on the planner
 * detail after creation.
 */
final class FormationStep implements WizardStepInterface {

    public function slug(): string { return 'formation'; }
    public function label(): string { return __( 'Formation', 'talenttrack' ); }

    public function render( array $state ): void {
        WizardAssets::enqueue();

        $current = (string) ( $state['default_formation'] ?? '' );
        $formations = QueryHelpers::get_lookup_names( 'tournament_formation' );

        echo '<div class="tt-tournament-wizard">';
        echo '<p class="ttw-step-desc">' . esc_html__( 'Pick the default formation for the tournament. You can override it per match in the next step.', 'talenttrack' ) . '</p>';
        echo '<div class="ttw-card">';
        echo '<div class="ttw-formation-grid">';

        // "No default" sentinel card.
        $sel_none = checked( $current, '', false );
        echo '<label class="ttw-formation-card">';
        echo '<input type="radio" name="default_formation" value="" ' . $sel_none . '>';
        echo '<span class="ttw-none-glyph" aria-hidden="true">—</span>';
        echo '<span class="ttw-label-row">' . esc_html__( 'No default', 'talenttrack' ) . '</span>';
        echo '</label>';

        foreach ( $formations as $f ) {
            $label = (string) $f;
            $sel   = checked( $current, $label, false );
            echo '<label class="ttw-formation-card">';
            echo '<input type="radio" name="default_formation" value="' . esc_attr( $label ) . '" ' . $sel . '>';
            echo '<span class="ttw-pitch" aria-hidden="true">';
            foreach ( self::dotsFor( $label ) as $dot ) {
                echo '<span class="ttw-dot" style="top:' . esc_attr( (string) $dot[1] ) . '%;left:' . esc_attr( (string) $dot[0] ) . '%;"></span>';
            }
            echo '</span>';
            echo '<span class="ttw-label-row">' . esc_html( $label ) . '</span>';
            echo '</label>';
        }

        echo '</div>'; // formation-grid
        echo '</div>'; // card
        echo '</div>'; // tournament-wizard
    }

    public function validate( array $post, array $state ) {
        $f = isset( $post['default_formation'] ) ? sanitize_text_field( wp_unslash( (string) $post['default_formation'] ) ) : '';
        return [ 'default_formation' => $f ];
    }

    public function nextStep( array $state ): ?string { return 'squad'; }
    public function submit( array $state ) { return null; }

    /**
     * Hand-drawn dot positions per formation label.
     *
     * Each entry is `[leftPct, topPct]`. The formation is parsed as
     * "GK-DEF-MID-FWD" or "GK-DEF-MID1-MID2-FWD" segments after the
     * implicit keeper at the bottom; dots distribute evenly across
     * each row band.
     *
     * The keeper dot sits at the bottom centre. Field rows progress
     * upward (back-line → … → forwards) so the visual matches the
     * mockup's pitch where the keeper is at the bottom.
     *
     * @return array<int, array{0:int|float,1:int|float}>
     */
    private static function dotsFor( string $formation ): array {
        $parts = preg_split( '/[\-x×\s]+/u', trim( $formation ) ) ?: [];
        $parts = array_values( array_filter( array_map( 'intval', $parts ), static function ( $n ) { return $n > 0; } ) );
        if ( ! $parts ) return [];

        $dots = [];
        // Keeper.
        $dots[] = [ 50, 88 ];

        // Field rows go from back (near keeper) to front (top).
        $row_count = count( $parts );
        // Top margin 10%, bottom (above keeper) 72%. Distribute evenly.
        $top_margin    = 12;
        $bottom_margin = 72;
        $usable        = max( 1, $bottom_margin - $top_margin );
        foreach ( $parts as $i => $n ) {
            // i=0 is the back-line, closest to the keeper.
            $row_top = $bottom_margin - ( $usable * ( $i / max( 1, $row_count - 1 ) ) );
            if ( $row_count === 1 ) $row_top = $top_margin + ( $usable / 2 );
            for ( $j = 0; $j < $n; $j++ ) {
                $left = $n === 1 ? 50 : ( 18 + ( ( 100 - 36 ) * ( $j / max( 1, $n - 1 ) ) ) );
                $dots[] = [ (float) round( $left, 1 ), (float) round( $row_top, 1 ) ];
            }
        }
        return $dots;
    }
}
