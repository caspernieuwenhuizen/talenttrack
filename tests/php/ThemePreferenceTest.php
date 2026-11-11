<?php
namespace TT\Tests\Php;

use TT\Infrastructure\Config\ConfigService;
use TT\Modules\Analytics\Reports\ReportBrandColour;
use TT\Shared\Frontend\ThemePreference;
use WP_UnitTestCase;

/**
 * #5089 — the theme registry, the default rollback and the bundled fonts.
 *
 * Every registered theme has a label, a short label and a sheet on disk.
 * `default` loads no sheet and stamps no class. A theme that loads a
 * webfont loads it from the plugin, never from Google Fonts.
 */
final class ThemePreferenceTest extends WP_UnitTestCase {

    private const NEW_THEMES = [
        ThemePreference::CLUBROOD,
        ThemePreference::ORANJE,
        ThemePreference::TACTIEKBORD,
        ThemePreference::BORDEAUX,
        ThemePreference::POLDER,
    ];

    /** @var mixed */
    private $saved;

    public function setUp(): void {
        parent::setUp();
        $this->saved = ( new ConfigService() )->get( ThemePreference::CONFIG_KEY, ThemePreference::DEFAULT_THEME );
    }

    public function tearDown(): void {
        ( new ConfigService() )->set( ThemePreference::CONFIG_KEY, (string) $this->saved );
        parent::tearDown();
    }

    private function root(): string {
        return dirname( __DIR__, 2 );
    }

    public function test_every_theme_has_labels_and_a_sheet(): void {
        $labels = ThemePreference::labels();
        $short  = ThemePreference::shortLabels();

        foreach ( self::NEW_THEMES as $theme ) {
            $this->assertContains( $theme, ThemePreference::themes() );
        }
        foreach ( ThemePreference::themes() as $theme ) {
            $this->assertArrayHasKey( $theme, $labels, $theme );
            $this->assertArrayHasKey( $theme, $short, $theme );
            if ( $theme === ThemePreference::DEFAULT_THEME ) {
                continue;
            }
            $this->assertFileExists( $this->root() . '/assets/css/theme-' . $theme . '.css', $theme );
        }
    }

    public function test_default_is_a_complete_rollback(): void {
        $user = self::factory()->user->create();
        ThemePreference::setClubDefault( ThemePreference::DEFAULT_THEME );
        ThemePreference::setUserOverride( $user, ThemePreference::INHERIT );

        $this->assertSame( ThemePreference::DEFAULT_THEME, ThemePreference::resolve( $user ) );
        $this->assertFalse( ThemePreference::hasThemeSheet( $user ) );
        $this->assertSame( '', ThemePreference::styleFile( $user ) );
        $this->assertSame( '', ThemePreference::rootClass( $user ) );
    }

    public function test_a_new_theme_resolves_to_its_sheet_and_class(): void {
        $user = self::factory()->user->create();
        ThemePreference::setClubDefault( ThemePreference::DEFAULT_THEME );

        foreach ( self::NEW_THEMES as $theme ) {
            ThemePreference::setUserOverride( $user, $theme );
            $this->assertSame( 'theme-' . $theme . '.css', ThemePreference::styleFile( $user ) );
            $this->assertSame( 'tt-theme-' . $theme . ' ' . ThemePreference::SHARED_CLASS, ThemePreference::rootClass( $user ) );
            $this->assertSame( ThemePreference::SHARED_SHEET, ThemePreference::sharedStyleFile( $user ) );
        }

        ThemePreference::setUserOverride( $user, ThemePreference::INHERIT );
        ThemePreference::setClubDefault( ThemePreference::POLDER );
        $this->assertSame( 'theme-polder.css', ThemePreference::styleFile( $user ) );
    }

    /** Federation and Kristalblauw keep their own rules: no shared sheet, one class. */
    public function test_the_two_older_themes_do_not_load_the_shared_sheet(): void {
        $user = self::factory()->user->create();
        foreach ( [ ThemePreference::FEDERATION, ThemePreference::LEON_HUTTEN ] as $theme ) {
            ThemePreference::setUserOverride( $user, $theme );
            $this->assertSame( '', ThemePreference::sharedStyleFile( $user ), $theme );
            $this->assertSame( 'tt-theme-' . $theme, ThemePreference::rootClass( $user ), $theme );
        }
        ThemePreference::setUserOverride( $user, ThemePreference::DEFAULT_THEME );
        $this->assertSame( '', ThemePreference::sharedStyleFile( $user ) );
    }

    /**
     * A token-only theme sheet is @font-face rules and one token block, so
     * every rule it draws lives in the shared sheet and cannot drift.
     */
    public function test_a_shared_theme_sheet_is_fonts_and_tokens_only(): void {
        $this->assertFileExists( $this->root() . '/assets/css/' . ThemePreference::SHARED_SHEET );

        foreach ( ThemePreference::sharedSheetThemes() as $theme ) {
            $css = (string) file_get_contents( $this->root() . '/assets/css/theme-' . $theme . '.css' );
            $css = (string) preg_replace( '#/\*.*?\*/#s', '', $css );
            $css = (string) preg_replace( '/@font-face\s*\{[^}]*\}/', '', $css );

            $this->assertSame( 1, preg_match_all( '/([^{}]+)\{[^}]*\}/', $css, $blocks ), $theme . ' has rules besides its token block' );
            $this->assertSame( '.tt-dashboard.tt-theme-' . $theme, trim( $blocks[1][0] ), $theme );
            $this->assertDoesNotMatchRegularExpression( '/^\s*(?!--)[a-z-]+\s*:/m', $blocks[0][0], $theme . ' sets a property in its token block' );
        }
    }

    /** Reports take the theme's primary, so the map has to match every sheet. */
    public function test_report_colour_follows_each_theme_sheet(): void {
        $user = self::factory()->user->create();

        foreach ( ThemePreference::themes() as $theme ) {
            if ( $theme === ThemePreference::DEFAULT_THEME ) {
                continue;
            }
            $css = (string) file_get_contents( $this->root() . '/assets/css/theme-' . $theme . '.css' );
            $this->assertSame( 1, preg_match( '/--tt-primary:\s*(#[0-9a-fA-F]{6})\b/', $css, $m ), $theme );

            ThemePreference::setUserOverride( $user, $theme );
            $this->assertSame( strtolower( $m[1] ), ReportBrandColour::primary( $user ), $theme );
        }
    }

    public function test_bundled_fonts_load_from_the_plugin_with_swap(): void {
        $sheets = array_merge( self::NEW_THEMES, [ ThemePreference::FEDERATION ] );
        $this->assertStringNotContainsString( 'fonts.googleapis.com', (string) file_get_contents( $this->root() . '/assets/css/player-card.css' ), 'player-card.css' );

        foreach ( $sheets as $theme ) {
            $css = (string) file_get_contents( $this->root() . '/assets/css/theme-' . $theme . '.css' );

            $this->assertStringNotContainsString( 'fonts.googleapis.com', $css, $theme );
            $this->assertStringNotContainsString( 'fonts.gstatic.com', $css, $theme );

            $faces = preg_match_all( '/@font-face\s*\{([^}]*)\}/', $css, $m );
            $this->assertGreaterThan( 0, $faces, $theme . ' declares no @font-face' );

            foreach ( $m[1] as $face ) {
                $this->assertMatchesRegularExpression( '/font-display\s*:\s*swap/', $face, $theme );
                $this->assertSame( 1, preg_match( '/url\("\.\.\/fonts\/([a-z0-9-]+)\/([a-z0-9-]+\.woff2)"\)/', $face, $url ), $theme );
                $this->assertFileExists( $this->root() . '/assets/fonts/' . $url[1] . '/' . $url[2], $theme );
                $this->assertFileExists( $this->root() . '/assets/fonts/' . $url[1] . '/OFL.txt', $url[1] . ' ships without its licence' );
            }
        }
    }
}
