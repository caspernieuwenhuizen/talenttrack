<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Shared\CoreSurfaceRegistration;
use TT\Shared\Configuration\SettingsRegistry;
use TT\Shared\Frontend\FrontendConfigurationView;

/**
 * #4788 — a Configuration sub-form's way back is the breadcrumb.
 *
 * Thirteen sub-forms printed an extra "All configuration" link under their
 * header: a third navigation affordance next to the breadcrumb chain and
 * the `tt_back` pill (CLAUDE.md §5a), with an inline style. It is gone, and
 * every sub-form's chain carries the Configuration crumb that replaces it.
 */
final class ConfigurationSubFormNavTest extends WP_UnitTestCase {

    private int $admin = 0;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        CoreSurfaceRegistration::register();
        SettingsRegistry::clear();
        AuthorizationService::flushCache();
        $this->admin = (int) self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $this->admin );
    }

    public function tear_down(): void {
        unset( $_GET['config_sub'] );
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    /** @return array<string, array{0: string}> */
    public static function subForms(): array {
        $subs = [
            'general', 'appearance', 'branding', 'theme', 'rating', 'pdp-blocks',
            'pdp-prep-questions', 'match-minutes', 'football-form', 'menus',
            'profile-cards', 'dashboard', 'export', 'messages', 'lookups', 'mfa-policy',
        ];
        $out = [];
        foreach ( $subs as $sub ) $out[ $sub ] = [ $sub ];
        return $out;
    }

    /** @dataProvider subForms */
    public function test_the_breadcrumb_is_the_way_back( string $sub ): void {
        $html = $this->render( $sub );

        $this->assertSame( 1, preg_match( '#<nav class="tt-breadcrumbs"[^>]*>(.*?)</nav>#s', $html, $m ), "{$sub}: no breadcrumb chain" );
        $this->assertMatchesRegularExpression(
            '#<a href="[^"]*tt_view=configuration[^"]*">Configuration</a>#',
            $m[1],
            "{$sub}: the chain has no Configuration crumb to go back by"
        );
    }

    /** @dataProvider subForms */
    public function test_no_extra_back_link_is_printed( string $sub ): void {
        $html = $this->render( $sub );

        $this->assertStringNotContainsString( 'All configuration', $html, "{$sub}: the extra back link is back" );
        $this->assertStringNotContainsString( '&larr;', $this->outsideBreadcrumb( $html ), "{$sub}: a back arrow outside the breadcrumb" );
    }

    private function render( string $sub ): string {
        $_GET['config_sub'] = $sub;
        ob_start();
        FrontendConfigurationView::render( $this->admin, true );
        return (string) ob_get_clean();
    }

    private function outsideBreadcrumb( string $html ): string {
        return (string) preg_replace( '#<nav class="tt-breadcrumbs".*?</nav>#s', '', $html );
    }
}
