<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Shared\Frontend\FrontendWizardView;
use TT\Shared\Wizards\WizardState;

/**
 * #4747 — the wizard chrome runtime (the Next loading guard and the mobile
 * rail toggle) is an enqueued script, not an inline `<script>` in the view,
 * and its loading label comes from `TT.i18n`, not a hardcoded English
 * string. The Playwright specs check the guard still behaves the same.
 */
final class WizardChromeScriptTest extends WP_UnitTestCase {

    private int $user = 0;

    /** @var array<string, mixed> */
    private array $get_backup = [];

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();

        $this->user = (int) self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $this->user );
        $this->get_backup = $_GET;
    }

    public function tear_down(): void {
        WizardState::clear( $this->user, 'new-player' );
        $_GET = $this->get_backup;
        wp_dequeue_script( 'tt-wizard' );
        wp_set_current_user( 0 );
        MatrixRepository::clearCache();
        parent::tear_down();
    }

    public function test_the_view_prints_no_inline_script(): void {
        $html = $this->render( 'new-player' );

        $this->assertStringContainsString( 'tt-wizard-form', $html, 'the wizard rendered' );
        $this->assertStringNotContainsString( '<script', $html );
    }

    public function test_the_chrome_runtime_is_enqueued(): void {
        $this->render( 'new-player' );

        $this->assertTrue( wp_script_is( 'tt-wizard', 'enqueued' ) );
        $registered = wp_scripts()->registered['tt-wizard'] ?? null;
        $this->assertNotNull( $registered );
        $this->assertStringEndsWith( 'assets/js/wizard.js', (string) $registered->src );
    }

    public function test_the_runtime_reads_its_label_from_tt_i18n(): void {
        $js = (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/js/wizard.js' );

        $this->assertStringContainsString( 'i18n.wizard_loading', $js );
        $this->assertStringNotContainsString( "'Loading", $js, 'no hardcoded English label' );

        $shortcode = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Shared/Frontend/DashboardShortcode.php' );
        $this->assertMatchesRegularExpression( '/\'wizard_loading\'\s*=>\s*__\(\s*\'Loading…\'/u', $shortcode );
    }

    private function render( string $slug ): string {
        $_GET = [ 'tt_view' => 'wizard', 'slug' => $slug ];
        $_SERVER['REQUEST_METHOD'] = 'GET';

        ob_start();
        FrontendWizardView::render( $this->user, true );
        return (string) ob_get_clean();
    }
}
