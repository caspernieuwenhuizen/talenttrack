<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\Authorization\Matrix\MatrixEditService;
use TT\Modules\Authorization\PersonaLabels;
use TT\Modules\Mfa\Settings\MfaSettings;
use TT\Modules\PersonaDashboard\Admin\EditorPage;
use TT\Modules\PersonaDashboard\Frontend\PersonaLandingRenderer;
use TT\Shared\Frontend\Components\FrontendAppChrome;

/**
 * #4504 — one persona label map, next to `PersonaResolver`. The shell nav,
 * the switcher, the landing, the persona dashboard editor, the matrix, the
 * MFA setting and the dashboard configuration all read it, so a persona has
 * one name on every screen.
 */
final class PersonaLabelsTest extends WP_UnitTestCase {

    /** The files that used to carry their own copy of the map. */
    private const FORMER_COPIES = [
        'src/Shared/Frontend/Components/FrontendAppChrome.php',
        'src/Shared/Frontend/DashboardShortcode.php',
        'src/Shared/Frontend/FrontendConfigurationView.php',
        'src/Modules/Authorization/Matrix/MatrixEditService.php',
        'src/Modules/Mfa/Settings/MfaSettings.php',
        'src/Modules/PersonaDashboard/Admin/EditorPage.php',
        'src/Modules/PersonaDashboard/Frontend/PersonaLandingRenderer.php',
    ];

    public function test_the_map_is_in_sentence_case(): void {
        $this->assertSame( 'Head of development', PersonaLabels::label( 'head_of_development' ) );
        $this->assertSame( 'Observer', PersonaLabels::label( 'readonly_observer' ) );
        $this->assertSame( 'Head coach', PersonaLabels::label( 'head_coach' ) );
        $this->assertSame( 'Staff', PersonaLabels::label( 'staff' ) );
        $this->assertSame( '', PersonaLabels::label( 'nobody' ) );
    }

    public function test_no_former_copy_keeps_its_own_label_for_a_persona(): void {
        foreach ( self::FORMER_COPIES as $rel ) {
            $source = (string) file_get_contents( TT_PLUGIN_DIR . $rel );
            $this->assertDoesNotMatchRegularExpression(
                "/'head_of_development'\s*=>\s*_[_x]\(/",
                $source,
                $rel . ' carries its own persona label map'
            );
        }
    }

    public function test_every_surface_names_a_persona_the_same_way(): void {
        foreach ( [ 'head_of_development', 'readonly_observer', 'academy_admin', 'team_manager' ] as $persona ) {
            $expected = PersonaLabels::label( $persona );
            $this->assertSame( $expected, FrontendAppChrome::personaLabel( $persona ), 'shell nav: ' . $persona );
            $this->assertSame( $expected, MatrixEditService::personaLabel( $persona ), 'matrix: ' . $persona );
            $this->assertSame( $expected, $this->privateLabel( PersonaLandingRenderer::class, $persona ), 'landing: ' . $persona );
            $this->assertSame( $expected, $this->privateLabel( EditorPage::class, $persona ), 'dashboard editor: ' . $persona );
        }
        $this->assertSame( 'Head of development', MfaSettings::operatorSelectablePersonas()['head_of_development'] );
    }

    public function test_each_surface_keeps_its_own_fallback_for_an_unknown_key(): void {
        $this->assertSame( '', FrontendAppChrome::personaLabel( 'made_up' ) );
        $this->assertSame( 'made_up', MatrixEditService::personaLabel( 'made_up' ) );
        $this->assertSame( 'made up', $this->privateLabel( EditorPage::class, 'made_up' ) );
        $this->assertSame( 'Dashboard', $this->privateLabel( PersonaLandingRenderer::class, 'made_up' ) );
    }

    public function test_a_surfaces_own_selection_keeps_its_keys_and_order(): void {
        $this->assertSame(
            [ 'academy_admin', 'head_of_development', 'head_coach', 'assistant_coach', 'scout', 'team_manager', 'parent', 'player', 'readonly_observer' ],
            array_keys( MfaSettings::operatorSelectablePersonas() )
        );
        $this->assertSame( [ 'scout', 'player' ], array_keys( PersonaLabels::forKeys( [ 'scout', 'nobody', 'player' ] ) ) );
    }

    private function privateLabel( string $class, string $persona ): string {
        $method = new \ReflectionMethod( $class, 'personaLabel' );
        $method->setAccessible( true );
        return (string) $method->invoke( null, $persona );
    }
}
