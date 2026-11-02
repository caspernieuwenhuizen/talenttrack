<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;

/**
 * #4702 — the player profile's key facts and KPI tiles fit a 320px phone.
 *
 * Both grids used bare `1fr` tracks. A `1fr` track cannot shrink below its
 * content's min-content width, so one unbreakable label ("GEBOORTEDATUM",
 * "AANWEZIGHEID") widened its column and the page scrolled sideways by 6px.
 * The tracks are now `minmax(0, 1fr)` and the labels break inside the tile,
 * the fix the team tiles already had (#4213).
 *
 * A stylesheet assertion guards this, as in `TapTargetFloorTest`: the
 * defect is a declaration, not markup.
 */
final class PlayerProfileNarrowGridTest extends WP_UnitTestCase {

    private function css(): string {
        return (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/css/frontend-player-detail.css' );
    }

    /**
     * The declarations of the first rule whose selector is exactly
     * `$selector`, at the start of a line: the mobile base rule, not a
     * media-query override further down.
     */
    private function rule( string $selector ): string {
        $css = $this->css();
        $at  = strpos( $css, "\n" . $selector . ' {' );

        $this->assertNotFalse( $at, 'No `' . $selector . '` rule in frontend-player-detail.css' );

        $open  = strpos( $css, '{', (int) $at );
        $close = strpos( $css, '}', (int) $open );

        return substr( $css, (int) $open + 1, (int) $close - (int) $open - 1 );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function grids(): array {
        return [
            'key facts' => [ '.tt-player-facts' ],
            'KPI tiles' => [ '.tt-player-glance__grid' ],
        ];
    }

    /**
     * @dataProvider grids
     */
    public function test_the_phone_grid_tracks_can_shrink_below_their_content( string $selector ): void {
        $rule = $this->rule( $selector );

        $this->assertStringContainsString( 'grid-template-columns: repeat(3, minmax(0, 1fr))', $rule );
        $this->assertStringNotContainsString( '1fr 1fr 1fr', $rule );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function labels(): array {
        return [
            'key facts label' => [ '.tt-player-facts__label' ],
            'KPI label'       => [ '.tt-player-kpi__label' ],
        ];
    }

    /**
     * Without the last-resort wrap a shrunken track only moves the overflow
     * from the page into the tile. Automatic hyphenation stays off: at 360
     * and 390px, where most coaches' phones are, a label must not break
     * inside a word, so the labels are short forms instead.
     *
     * @dataProvider labels
     */
    public function test_a_label_wraps_only_as_a_last_resort( string $selector ): void {
        $rule = $this->rule( $selector );

        $this->assertStringContainsString( 'overflow-wrap: anywhere', $rule );
        $this->assertStringContainsString( 'hyphens: manual', $rule );
        $this->assertStringNotContainsString( 'hyphens: auto', $rule );
    }

    /**
     * The short forms have their own context, so the full words used
     * elsewhere ("Aanwezigheid", "Geboortedatum") keep their translation.
     */
    public function test_the_tile_labels_use_their_own_short_forms(): void {
        $php = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Shared/Frontend/FrontendPlayerDetailView.php' );

        foreach ( [ 'DOB', 'Joined', 'Avg rating', 'Attended', 'Goals scored', 'Tests' ] as $msgid ) {
            $this->assertStringContainsString( "esc_html_x( '" . $msgid . "', 'player facts label', 'talenttrack' )", $php );
        }
    }
}
