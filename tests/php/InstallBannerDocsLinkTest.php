<?php
/**
 * #3739 — where the install banner's "Install instructions" button points.
 *
 * The banner is the first thing a new family is asked to act on, and it is
 * the step that gets push notifications working. It built its href on
 * `home_url( '/' )`, but `tt_view` is only read where the
 * `[talenttrack_dashboard]` shortcode runs — so on any install whose
 * dashboard is not the front page, a parent following the link landed on
 * the theme's homepage and the walkthrough was unreachable.
 *
 * The fixture is only meaningful because the dashboard page and the site
 * root differ; every assertion below leans on that.
 */

use TT\Infrastructure\Query\QueryHelpers;
use TT\Shared\Frontend\Components\RecordLink;
use TT\Shared\Frontend\FrontendInstallBanner;

class InstallBannerDocsLinkTest extends WP_UnitTestCase {

    private const IPHONE  = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 Safari/604.1';
    private const ANDROID = 'Mozilla/5.0 (Linux; Android 14; Pixel 7) AppleWebKit/537.36 Chrome/122 Mobile Safari/537.36';

    /** @var int */
    private $page = 0;

    /** @var int */
    private $parent_id = 0;

    /** @var string|null */
    private $request_uri = null;

    /** @var string|null */
    private $user_agent = null;

    public function set_up(): void {
        parent::set_up();

        // WordPress reads REQUEST_URI from add_query_arg(), so it is saved
        // and restored rather than left missing for whatever runs next.
        $this->request_uri = $_SERVER['REQUEST_URI'] ?? null;
        $this->user_agent  = $_SERVER['HTTP_USER_AGENT'] ?? null;

        $this->page = self::factory()->post->create( [
            'post_type'    => 'page',
            'post_title'   => 'TalentTrack',
            'post_status'  => 'publish',
            'post_content' => '[talenttrack_dashboard]',
        ] );
        QueryHelpers::set_config( 'dashboard_page_id', (string) $this->page );
        QueryHelpers::set_config( 'install_banner.enabled', '1' );

        // A parent needs no phone on file for the banner to render, which
        // keeps the fixture to the one thing under test.
        $this->parent_id = self::factory()->user->create( [ 'role' => 'tt_parent' ] );
        wp_set_current_user( $this->parent_id );
    }

    public function tear_down(): void {
        if ( $this->request_uri === null ) {
            unset( $_SERVER['REQUEST_URI'] );
        } else {
            $_SERVER['REQUEST_URI'] = $this->request_uri;
        }
        if ( $this->user_agent === null ) {
            unset( $_SERVER['HTTP_USER_AGENT'] );
        } else {
            $_SERVER['HTTP_USER_AGENT'] = $this->user_agent;
        }
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    private function renderWith( ?string $user_agent ): string {
        if ( $user_agent === null ) {
            unset( $_SERVER['HTTP_USER_AGENT'] );
        } else {
            $_SERVER['HTTP_USER_AGENT'] = $user_agent;
        }

        ob_start();
        FrontendInstallBanner::render();
        return (string) ob_get_clean();
    }

    /** The href of the "Install instructions" anchor, or '' when absent. */
    private function articleHref( string $html ): string {
        if ( ! preg_match( '#<a class="tt-btn tt-btn-secondary" href="([^"]+)"#', $html, $m ) ) {
            return '';
        }
        return html_entity_decode( $m[1], ENT_QUOTES );
    }

    public function test_the_fixture_puts_the_dashboard_off_the_front_page(): void {
        $this->assertNotSame(
            home_url( '/' ),
            get_permalink( $this->page ),
            'the bug is invisible unless the dashboard and the site root differ'
        );
    }

    /**
     * The acceptance criterion: the button resolves through the dashboard
     * page, not the site root.
     */
    public function test_the_link_is_built_on_the_dashboard_page(): void {
        $href = $this->articleHref( $this->renderWith( self::IPHONE ) );

        $this->assertNotSame( '', $href, 'the banner must render its instructions link' );
        $this->assertStringStartsWith( RecordLink::dashboardUrl(), $href );
        $this->assertSame( get_permalink( $this->page ), RecordLink::dashboardUrl() );
    }

    public function test_the_link_carries_the_docs_view_and_the_iphone_topic(): void {
        $href = $this->articleHref( $this->renderWith( self::IPHONE ) );

        $this->assertStringContainsString( 'tt_view=docs', $href );
        $this->assertStringContainsString( 'topic=install-on-iphone', $href );
    }

    public function test_an_android_user_agent_gets_the_android_topic(): void {
        $href = $this->articleHref( $this->renderWith( self::ANDROID ) );

        $this->assertStringStartsWith( RecordLink::dashboardUrl(), $href );
        $this->assertStringContainsString( 'topic=install-on-android', $href );
    }

    /**
     * No user agent is the generic walkthrough, and it is resolved the same
     * way — the fallback was broken by the same line.
     */
    public function test_the_fallback_topic_resolves_through_the_dashboard_too(): void {
        $href = $this->articleHref( $this->renderWith( null ) );

        $this->assertStringStartsWith( RecordLink::dashboardUrl(), $href );
        $this->assertStringContainsString( 'topic=notifications-setup', $href );
    }

    /**
     * The regression itself, stated directly: nothing in the banner may be
     * a bare site-root `tt_view` URL any more.
     */
    public function test_the_banner_no_longer_links_to_the_site_root(): void {
        $html = $this->renderWith( self::IPHONE );

        $this->assertStringNotContainsString(
            esc_url( add_query_arg( [ 'tt_view' => 'docs', 'topic' => 'install-on-iphone' ], home_url( '/' ) ) ),
            $html
        );
    }

    /**
     * Link and target proven together: following the href must reach a
     * topic this reader is actually served.
     */
    public function test_the_topic_the_link_names_is_readable_by_a_parent(): void {
        $href = $this->articleHref( $this->renderWith( self::IPHONE ) );
        $args = [];
        parse_str( (string) wp_parse_url( $href, PHP_URL_QUERY ), $args );
        $slug = (string) ( $args['topic'] ?? '' );

        $this->assertSame( 'install-on-iphone', $slug );

        $res = rest_do_request( new WP_REST_Request( 'GET', '/talenttrack/v1/docs/' . $slug ) );

        $data = (array) $res->get_data();

        $this->assertSame( 200, $res->get_status() );
        $this->assertSame( $slug, $data['slug'] ?? '' );
        $this->assertNotEmpty( $data['html'] ?? '' );
    }

    /**
     * #2186's rule, applied here: a disabled Documentation module leaves no
     * dangling entry point. The banner keeps its other two controls.
     */
    public function test_the_link_disappears_when_the_documentation_module_is_off(): void {
        $docs = 'TT\\Modules\\Documentation\\DocumentationModule';

        \TT\Core\ModuleRegistry::setEnabled( $docs, false );
        $html = $this->renderWith( self::IPHONE );
        \TT\Core\ModuleRegistry::setEnabled( $docs, true );

        $this->assertSame( '', $this->articleHref( $html ) );
        $this->assertStringContainsString( 'data-tt-install-action="enable"', $html );
        $this->assertStringContainsString( 'data-tt-install-action="dismiss"', $html );
    }
}
