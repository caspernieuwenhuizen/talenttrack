<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Shared\Frontend\FrontendStandardReportsView;

/**
 * #4299 — a standard report's last breadcrumb is its title in the reader's
 * language, not the English source string. An unknown slug is still refused.
 */
final class StandardReportBreadcrumbTitleTest extends WP_UnitTestCase {

    /** @var callable|null */
    private $gettext = null;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->ensureCapabilities();
        wp_set_current_user( (int) self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $this->gettext = static function ( $translation, $text, $domain ) {
            return ( $domain === 'talenttrack' && $text === 'Player · Progress & radar' )
                ? 'Speler · Voortgang & radar'
                : $translation;
        };
        add_filter( 'gettext', $this->gettext, 10, 3 );
    }

    public function tear_down(): void {
        if ( $this->gettext !== null ) {
            remove_filter( 'gettext', $this->gettext, 10 );
            $this->gettext = null;
        }
        unset( $_GET['slug'] );
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_the_last_crumb_is_the_translated_title(): void {
        $crumbs = $this->breadcrumbs( 'player-progress-radar' );

        $this->assertStringContainsString( 'Speler · Voortgang &amp; radar', $crumbs );
        $this->assertStringNotContainsString( 'Player · Progress', $crumbs );
    }

    public function test_an_unknown_slug_is_still_refused(): void {
        $html = $this->render( 'no-such-report' );

        $this->assertStringContainsString( 'Unknown standard report.', $html );
    }

    private function breadcrumbs( string $slug ): string {
        $html  = $this->render( $slug );
        $start = strpos( $html, '<nav class="tt-breadcrumbs"' );
        $this->assertNotFalse( $start, 'the view renders its breadcrumb chain' );
        $end = strpos( $html, '</nav>', (int) $start );
        return substr( $html, (int) $start, $end === false ? null : $end - (int) $start );
    }

    private function render( string $slug ): string {
        $_GET['slug'] = $slug;
        ob_start();
        FrontendStandardReportsView::render( get_current_user_id(), true );
        return (string) ob_get_clean();
    }
}
