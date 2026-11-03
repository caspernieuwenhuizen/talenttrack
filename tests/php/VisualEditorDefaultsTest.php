<?php
namespace TT\Tests\Php;

use TT\Modules\CustomCss\DesignSystem\TokenCatalogue;
use TT\Modules\CustomCss\VisualEditor;
use WP_UnitTestCase;

/**
 * #4839 — the visual editor writes only the tokens an academy changed.
 *
 * The editor posts every field on save, touched or not, and some catalogue
 * defaults differed from tokens.css. `generateCss()` wrote all of them on
 * `.tt-root`, so an install that ever saved the editor carried borders,
 * muted text, status colours, radii and shadows nobody chose. The catalogue
 * now holds the tokens.css values, and a value equal to a token's current
 * default or its pre-#4839 `legacy_default` is not written.
 */
final class VisualEditorDefaultsTest extends WP_UnitTestCase {

    /**
     * Every default this change moved: key, new default, legacy default,
     * a value an academy might really pick, and the CSS that value writes.
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: string}>
     */
    public static function changed(): array {
        return [
            'success'      => [ 'success_color',    '#2f9e5e', '#00a32a', '#117733', '--tt-success: #117733' ],
            'warning'      => [ 'warning_color',    '#e8902b', '#dba617', '#cc6600', '--tt-warning: #cc6600' ],
            'danger'       => [ 'danger_color',     '#d8453b', '#b32d2e', '#aa1111', '--tt-danger: #aa1111' ],
            'info'         => [ 'info_color',       '#2d6fb3', '#2271b1', '#114488', '--tt-info: #114488' ],
            'line'         => [ 'line_color',       '#e3e1d8', '#e5e7ea', '#cccccc', '--tt-line: #cccccc' ],
            'muted'        => [ 'muted_color',      '#6a6d66', '#5b6e75', '#555555', '--tt-muted: #555555' ],
            'radius md'    => [ 'corner_radius_md', '6',       '8',       '4',       '--tt-r-md: 4px' ],
            'radius lg'    => [ 'corner_radius_lg', '10',      '12',      '16',      '--tt-r-lg: 16px' ],
            'shadow small' => [ 'shadow_sm',        'none',    'light',   'strong',  '--tt-shadow-sm:' ],
            'heading 1'    => [ 'font_size_h1',     '1.75',    '2',       '2.25',    '--tt-fs-h1: 2.25rem' ],
        ];
    }

    /**
     * @dataProvider changed
     */
    public function test_the_catalogue_holds_the_new_default_and_remembers_the_old( string $key, string $default, string $legacy ): void {
        $def = TokenCatalogue::all()[ $key ];

        $this->assertSame( $default, $def['default'] );
        $this->assertSame( $legacy, $def['legacy_default'] ?? null );
    }

    /**
     * @dataProvider changed
     */
    public function test_an_untouched_value_is_not_written( string $key, string $default, string $legacy ): void {
        $def = TokenCatalogue::all()[ $key ];

        foreach ( [ $default, $legacy, strtoupper( $legacy ) ] as $value ) {
            $css = VisualEditor::generateCss( [ $key => $value ] );
            $this->assertStringNotContainsString( $def['css_var'] . ':', $css, $key . ' = ' . $value );
        }
    }

    /**
     * @dataProvider changed
     */
    public function test_a_value_the_academy_changed_is_still_written( string $key, string $default, string $legacy, string $picked, string $expect ): void {
        $this->assertStringContainsString( $expect, VisualEditor::generateCss( [ $key => $picked ] ) );
    }

    public function test_short_hex_and_case_count_as_the_default(): void {
        $def = TokenCatalogue::all()['background_color'];

        $this->assertSame( '#ffffff', $def['default'] );
        $this->assertTrue( VisualEditor::isDefaultValue( $def, '#FFF' ) );
        $this->assertTrue( VisualEditor::isDefaultValue( $def, '#FfFfFf' ) );
        $this->assertFalse( VisualEditor::isDefaultValue( $def, '#fefefe' ) );
    }

    /**
     * A save that touched nothing, with every field at its default as the
     * form posts it, writes no token rule and no shadow override.
     */
    public function test_an_untouched_save_writes_no_token_and_no_shadow(): void {
        $settings = [];
        foreach ( TokenCatalogue::all() as $key => $def ) {
            $settings[ $key ] = (string) ( $def['legacy_default'] ?? $def['default'] );
        }

        $css = VisualEditor::generateCss( $settings );

        $this->assertStringNotContainsString( '.tt-root {', $css );
        $this->assertStringNotContainsString( 'box-shadow:', $css );
    }
}
