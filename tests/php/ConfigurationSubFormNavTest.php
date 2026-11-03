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
        unset( $_GET['config_sub'], $_GET['category'] );
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

    /**
     * A lookup category goes back to the lookups index through a linked
     * Lookups crumb, not an "All lookups" link under its header.
     */
    public function test_a_lookup_category_goes_back_through_a_linked_lookups_crumb(): void {
        $_GET['category'] = 'positions';
        $html = $this->render( 'lookups' );

        $this->assertSame( 1, preg_match( '#<nav class="tt-breadcrumbs"[^>]*>(.*?)</nav>#s', $html, $m ) );
        $chain = $m[1];
        $this->assertMatchesRegularExpression( '#<a href="[^"]*tt_view=configuration[^"]*">Configuration</a>#', $chain );
        $this->assertMatchesRegularExpression( '#<a href="[^"]*config_sub=lookups[^"]*">Lookups</a>#', $chain, 'the Lookups crumb links to the index' );
        $this->assertStringContainsString( 'Positions', $chain, 'the category is the current crumb' );
        $this->assertDoesNotMatchRegularExpression( '#<a [^>]*>Positions</a>#', $chain, 'the current crumb is not a link' );

        $this->assertStringNotContainsString( 'All lookups', $html );
        $this->assertStringNotContainsString( '&larr;', $this->outsideBreadcrumb( $html ) );
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function headerLabels(): array {
        return [
            'menus'     => [ 'menus', 'wp-admin menus' ],
            'dashboard' => [ 'dashboard', 'Default dashboard' ],
            'lookups'   => [ 'lookups', 'Lookups' ],
        ];
    }

    /**
     * These three fell back to the English slug ("Menus", "Dashboard") for
     * their last crumb; it now reads the header's translated label.
     *
     * @dataProvider headerLabels
     */
    public function test_the_last_crumb_uses_the_translated_label( string $sub, string $label ): void {
        $html = $this->render( $sub );

        $this->assertSame( 1, preg_match( '#<nav class="tt-breadcrumbs"[^>]*>(.*?)</nav>#s', $html, $m ) );
        $this->assertStringContainsString( $label, wp_strip_all_tags( $m[1] ), "{$sub}: last crumb" );
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
