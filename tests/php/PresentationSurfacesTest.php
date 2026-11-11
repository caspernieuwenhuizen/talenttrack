<?php
/**
 * Presentation surfaces: a room display without the app shell (#5088).
 *
 * The contract has three limits, and each has a test here: one record, one
 * way back (the close control `present()` draws, and nothing else), and the
 * normal screen with its chain for anyone the view refuses. The mechanism
 * proves itself on a test-only slug, because no shipped slug is claimed yet.
 *
 * The merge gate is the byte-identical test at the bottom: an unclaimed slug
 * must render exactly what it rendered before the chrome was split into
 * `openChrome()` / `closeChrome()`.
 */

use TT\Modules\AdminCenterClient\SupportGrantBanner;
use TT\Modules\AdminCenterClient\SupportGrants;
use TT\Modules\Authorization\Impersonation\ImpersonationBanner;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;
use TT\Shared\Frontend\Components\RecordLink;
use TT\Shared\Frontend\DashboardShortcode;
use TT\Shared\Frontend\FlashMessages;
use TT\Shared\Frontend\PresentationSurfaces;
use TT\Shared\Frontend\ShellPreference;
use TT\Shared\Frontend\TokenRoot;

class PresentationSurfacesTest extends WP_UnitTestCase {

    private const SLUG = 'tt-test-room-display';

    private int $user_id = 0;

    /** @var array<string, mixed> */
    private array $get = [];

    /** @var list<string> */
    private array $files = [];

    public function set_up(): void {
        parent::set_up();
        $this->get = $_GET;

        // A head of development: an administrator for tile visibility,
        // dressed as the HoD, so the shell draws its nav and its bar.
        ( new \TT\Infrastructure\Security\RolesService() )->installRoles();
        $this->user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
        ( new \WP_User( $this->user_id ) )->add_role( 'tt_head_dev' );
        update_user_meta( $this->user_id, 'tt_active_persona', 'head_of_development' );
        wp_set_current_user( $this->user_id );
        ShellPreference::setClubDefault( ShellPreference::APP );

        PresentationSurfaces::useFile( $this->config( [ self::SLUG => 'Test-only slug that proves the mechanism.' ] ) );
        FrontendBreadcrumbs::reset();
        SupportGrants::forget();
    }

    public function tear_down(): void {
        unset( $_SERVER['HTTP_SEC_PURPOSE'] );
        PresentationSurfaces::useFile( null );
        FrontendBreadcrumbs::reset();
        SupportGrants::forget();
        $_GET = $this->get;
        foreach ( $this->files as $file ) {
            if ( is_file( $file ) ) unlink( $file );
        }
        parent::tear_down();
    }

    /** Writes a config file returning `$value` and returns its path. */
    private function config( $value ): string {
        return $this->configSource( '<?php return ' . var_export( $value, true ) . ';' );
    }

    private function configSource( string $php ): string {
        $file = tempnam( sys_get_temp_dir(), 'tt-presentation-' );
        file_put_contents( $file, $php );
        $this->files[] = $file;
        return $file;
    }

    private function recordUrl( string $slug = 'players', $id = 5, array $extra = [] ): string {
        return add_query_arg( array_merge( [ 'tt_view' => $slug, 'id' => $id ], $extra ), RecordLink::dashboardUrl() );
    }

    /** @return array{0: bool, 1: string} what present() returned, and what it drew */
    private function present( string $url ): array {
        ob_start();
        $ok = PresentationSurfaces::present( $url );
        return [ $ok, (string) ob_get_clean() ];
    }

    private function chain( string $label = 'Here' ): string {
        ob_start();
        FrontendBreadcrumbs::fromDashboard( $label );
        return (string) ob_get_clean();
    }

    // ---------------------------------------------------------------
    // The resolver fails closed
    // ---------------------------------------------------------------

    public function test_the_shipped_config_claims_nothing_yet(): void {
        PresentationSurfaces::useFile( null );
        $this->assertSame( [], PresentationSurfaces::map() );
        $this->assertFalse( PresentationSurfaces::claims( 'players' ) );
        $this->assertFalse( PresentationSurfaces::claims( 'pdp' ) );
    }

    public function test_a_listed_slug_is_claimed_and_nothing_else(): void {
        $this->assertTrue( PresentationSurfaces::claims( self::SLUG ) );
        $this->assertNotSame( '', PresentationSurfaces::reason( self::SLUG ) );

        $this->assertFalse( PresentationSurfaces::claims( 'players' ) );
        $this->assertFalse( PresentationSurfaces::claims( '' ) );
        $this->assertFalse( PresentationSurfaces::claims( '   ' ) );
        $this->assertFalse( PresentationSurfaces::claims( 'no-such-view' ) );
        $this->assertSame( '', PresentationSurfaces::reason( 'players' ) );
    }

    public function test_a_missing_config_claims_nothing(): void {
        PresentationSurfaces::useFile( sys_get_temp_dir() . '/tt-presentation-does-not-exist.php' );
        $this->assertSame( [], PresentationSurfaces::map() );
        $this->assertFalse( PresentationSurfaces::claims( self::SLUG ) );
    }

    public function test_a_config_that_is_not_an_array_claims_nothing(): void {
        PresentationSurfaces::useFile( $this->configSource( '<?php return "' . self::SLUG . '";' ) );
        $this->assertFalse( PresentationSurfaces::claims( self::SLUG ) );

        PresentationSurfaces::useFile( $this->configSource( '<?php return null;' ) );
        $this->assertFalse( PresentationSurfaces::claims( self::SLUG ) );
    }

    public function test_a_malformed_entry_is_not_a_claim(): void {
        PresentationSurfaces::useFile( $this->config( [
            'no-reason'       => '',
            'blank-reason'    => '   ',
            'array-reason'    => [ 'why' ],
            'null-reason'     => null,
            0                 => 'A numeric key is not a slug.',
            'Upper-Case'      => 'Not a sanitised key.',
            'has space'       => 'Not a sanitised key.',
            'good-one'        => 'A well-formed sibling still counts.',
        ] ) );

        $this->assertFalse( PresentationSurfaces::claims( 'no-reason' ) );
        $this->assertFalse( PresentationSurfaces::claims( 'blank-reason' ) );
        $this->assertFalse( PresentationSurfaces::claims( 'array-reason' ) );
        $this->assertFalse( PresentationSurfaces::claims( 'null-reason' ) );
        $this->assertFalse( PresentationSurfaces::claims( '0' ) );
        $this->assertFalse( PresentationSurfaces::claims( 'upper-case' ) );
        $this->assertFalse( PresentationSurfaces::claims( 'Upper-Case' ) );
        $this->assertFalse( PresentationSurfaces::claims( 'hasspace' ) );
        $this->assertTrue( PresentationSurfaces::claims( 'good-one' ) );
        $this->assertSame( [ 'good-one' ], array_keys( PresentationSurfaces::map() ) );
    }

    // ---------------------------------------------------------------
    // present(): one declaration, one close control, fails closed
    // ---------------------------------------------------------------

    public function test_present_draws_one_close_control_back_to_the_record(): void {
        $_GET = [ 'tt_view' => self::SLUG, 'id' => '9' ];
        $url  = $this->recordUrl();

        [ $ok, $html ] = $this->present( $url );

        $this->assertTrue( $ok );
        $this->assertTrue( PresentationSurfaces::isPresenting() );
        $this->assertSame( 1, substr_count( $html, '<a ' ) );
        $this->assertSame( 1, substr_count( $html, 'data-tt-presentation-close' ) );
        $this->assertStringContainsString( 'href="' . esc_url( $url ) . '"', $html );
        $this->assertStringNotContainsString( 'tt-breadcrumbs', $html );
    }

    public function test_present_refuses_on_a_slug_that_is_not_claimed(): void {
        $_GET = [ 'tt_view' => 'players', 'id' => '9' ];

        [ $ok, $html ] = $this->present( $this->recordUrl() );

        $this->assertFalse( $ok );
        $this->assertSame( '', $html );
        $this->assertFalse( PresentationSurfaces::isPresenting() );
    }

    public function test_present_refuses_a_second_call(): void {
        $_GET = [ 'tt_view' => self::SLUG, 'id' => '9' ];
        $this->present( $this->recordUrl() );

        [ $ok, $html ] = $this->present( $this->recordUrl() );

        $this->assertFalse( $ok );
        $this->assertSame( '', $html, 'a second call would draw a second way back' );
        $this->assertTrue( PresentationSurfaces::isPresenting() );
    }

    public function test_present_refuses_once_a_chain_was_drawn(): void {
        // A view that drew its chain and then presents stays a normal view
        // with its chain, never a room screen with a stray chain in it.
        $_GET = [ 'tt_view' => self::SLUG, 'id' => '9' ];
        $this->assertStringContainsString( 'tt-breadcrumbs', $this->chain() );
        $this->assertTrue( FrontendBreadcrumbs::hasRendered() );

        [ $ok, $html ] = $this->present( $this->recordUrl() );

        $this->assertFalse( $ok );
        $this->assertSame( '', $html );
        $this->assertFalse( PresentationSurfaces::isPresenting() );
    }

    public function test_present_refuses_a_close_url_that_is_not_a_record_page(): void {
        $_GET = [ 'tt_view' => self::SLUG, 'id' => '9' ];
        $home = RecordLink::dashboardUrl();
        $host = (string) wp_parse_url( $home, PHP_URL_HOST );

        $refused = [
            'another site'           => str_replace( '//' . $host, '//evil.example', $this->recordUrl() ),
            'an extra argument'      => $this->recordUrl( 'players', 5, [ 'tt_back' => 'x' ] ),
            'no id'                  => add_query_arg( [ 'tt_view' => 'players' ], $home ),
            'a zero id'              => $this->recordUrl( 'players', 0 ),
            'a negative id'          => $this->recordUrl( 'players', -3 ),
            'a non-numeric id'       => $this->recordUrl( 'players', 'abc' ),
            'a padded id'            => $this->recordUrl( 'players', '05' ),
            'an unknown slug'        => $this->recordUrl( 'no-such-view-5088' ),
            'a presentation surface' => $this->recordUrl( self::SLUG ),
            'no slug'                => add_query_arg( [ 'id' => 5 ], $home ),
            'a script'               => 'javascript:alert(1)',
            'a relative url'         => '?tt_view=players&id=5',
            'credentials'            => str_replace( '//' . $host, '//user:pass@' . $host, $this->recordUrl() ),
        ];

        foreach ( $refused as $why => $url ) {
            $this->assertFalse( PresentationSurfaces::isRecordUrl( $url ), 'accepted ' . $why );
            [ $ok, $html ] = $this->present( $url );
            $this->assertFalse( $ok, 'presented with ' . $why );
            $this->assertSame( '', $html, 'drew a control for ' . $why );
        }
        $this->assertFalse( PresentationSurfaces::isPresenting() );
        $this->assertTrue( PresentationSurfaces::isRecordUrl( $this->recordUrl() ) );
        $this->assertTrue( PresentationSurfaces::isRecordUrl( $this->recordUrl() . '#tt-form' ), 'a fragment is not a query argument' );
    }

    // ---------------------------------------------------------------
    // Reader: FrontendBreadcrumbs::render()
    // ---------------------------------------------------------------

    public function test_the_chain_renders_unchanged_on_a_claimed_slug_that_did_not_present(): void {
        $_GET = [ 'tt_view' => 'players' ];
        $expected = $this->chain();
        FrontendBreadcrumbs::reset();

        $_GET = [ 'tt_view' => self::SLUG ];
        $this->assertSame( $expected, $this->chain() );
        $this->assertStringContainsString( 'tt-breadcrumbs', $expected );
    }

    public function test_the_chain_and_the_pill_render_nothing_once_the_request_presents(): void {
        $_GET = [ 'tt_view' => self::SLUG, 'id' => '9', 'tt_back' => rawurlencode( RecordLink::dashboardUrl() ) ];
        $this->present( $this->recordUrl() );

        $this->assertSame( '', $this->chain() );
        ob_start();
        FrontendBreadcrumbs::render( [ [ 'label' => 'Here' ] ] );
        $this->assertSame( '', (string) ob_get_clean() );
    }

    // ---------------------------------------------------------------
    // Reader: FrontendAccessControl::hideAdminBar()
    // ---------------------------------------------------------------

    public function test_a_claimed_slug_gets_no_admin_bar_and_others_are_unchanged(): void {
        $access = new \TT\Shared\Frontend\FrontendAccessControl( new \TT\Infrastructure\Config\ConfigService() );

        $_GET = [ 'tt_view' => self::SLUG ];
        $this->assertFalse( $access->hideAdminBar( true ) );

        $_GET = [ 'tt_view' => 'players' ];
        $this->assertTrue( $access->hideAdminBar( true ), 'an administrator keeps the bar elsewhere' );

        $_GET = [];
        $this->assertTrue( $access->hideAdminBar( true ) );
    }

    // ---------------------------------------------------------------
    // Reader: DashboardShortcode
    // ---------------------------------------------------------------

    private function renderPresenting(): string {
        $_GET = [ 'tt_view' => self::SLUG, 'id' => '9' ];
        $this->present( $this->recordUrl() );
        return DashboardShortcode::render();
    }

    public function test_a_presenting_request_renders_without_the_chrome(): void {
        $html = $this->renderPresenting();

        $this->assertStringContainsString( 'tt-dashboard--presentation', $html );
        foreach ( [
            'tt-dash-header'   => 'the top bar',
            'tt-spotlight'     => 'the search box',
            'data-tt-shell'    => 'the shell frame',
            'tt-shell-nav'     => 'the shell navigation',
            'tt-shell-bar'     => 'the bottom bar',
            'tt-flash-stack'   => 'the flash area',
            'tt-alert-bar'     => 'the alerts banner',
            'tt-dash-help'     => 'the help drawer',
            'tt-help-button'   => 'the help drawer',
            'tt-breadcrumbs'   => 'the chain',
            'tt-back-link'     => 'the pill',
        ] as $needle => $what ) {
            $this->assertStringNotContainsString( $needle, $html, 'a room display draws ' . $what );
        }
    }

    public function test_a_presenting_request_leaves_the_flash_queue_for_the_next_page(): void {
        FlashMessages::add( FlashMessages::TYPE_SUCCESS, 'Saved for later', $this->user_id );

        $html = $this->renderPresenting();

        $this->assertStringNotContainsString( 'Saved for later', $html );
        $this->assertCount( 1, FlashMessages::peek( $this->user_id ), 'the TV must not spend the message' );
    }

    public function test_a_claimed_slug_that_does_not_present_gets_the_normal_screen(): void {
        // The refused path: the view never declares. The shell, the chain and
        // the flash message are all where they always are.
        FlashMessages::add( FlashMessages::TYPE_SUCCESS, 'Shown on the refusal', $this->user_id );
        $_GET = [ 'tt_view' => self::SLUG, 'id' => '9' ];

        $html = DashboardShortcode::render();

        $this->assertFalse( PresentationSurfaces::isPresenting() );
        $this->assertStringNotContainsString( 'tt-dashboard--presentation', $html );
        $this->assertStringContainsString( 'tt-dash-header', $html );
        $this->assertStringContainsString( 'data-tt-shell', $html );
        $this->assertStringContainsString( 'tt-breadcrumbs', $html );
        $this->assertStringContainsString( 'Shown on the refusal', $html );
        $this->assertSame( [], FlashMessages::peek( $this->user_id ) );
        $this->assertLessThan(
            strpos( $html, 'tt-breadcrumbs' ),
            strpos( $html, 'Shown on the refusal' ),
            'the flash message sits above the body, as on every other page'
        );
    }

    public function test_the_support_grant_banner_shows_on_a_room_display(): void {
        $this->assertNotFalse(
            has_action( 'tt_dashboard_safety_banners', [ SupportGrantBanner::class, 'renderFrontend' ] ),
            'the support banner is a safety banner'
        );
        $this->assertFalse( has_action( 'tt_dashboard_before_body', [ SupportGrantBanner::class, 'renderFrontend' ] ) );

        SupportGrants::store( [ [
            'id'         => 4,
            'operator'   => 'Casper',
            'reason'     => 'Checking the room display',
            'expires_at' => gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ),
        ] ] );

        $html = $this->renderPresenting();

        $this->assertStringContainsString( 'tt-support-grant', $html );
        $this->assertStringContainsString( 'Checking the room display', $html );
    }

    public function test_the_impersonation_banner_is_drawn_outside_the_shortcode(): void {
        // It hooks wp_body_open, which the canvas template fires before the
        // shortcode runs, so a room display cannot leave it out.
        $this->assertNotFalse( has_action( 'wp_body_open', [ ImpersonationBanner::class, 'renderFrontend' ] ) );
        $this->assertStringContainsString( 'wp_body_open()', (string) file_get_contents( TT_PLUGIN_DIR . 'templates/canvas.php' ) );

        $html = $this->renderPresenting();
        $this->assertTrue( PresentationSurfaces::isPresenting() );
        $this->assertStringContainsString( 'tt-dashboard--presentation', $html );
    }

    // ---------------------------------------------------------------
    // The merge gate: unclaimed slugs render byte for byte as before
    // ---------------------------------------------------------------

    /**
     * The authenticated half of `renderUnwrapped()` exactly as it stood
     * before #5088, run in the class's own scope so it calls the same
     * private helpers. The support banner hooked `tt_dashboard_before_body`
     * at priority 4 then; it is put back there for the reference run.
     */
    private function renderBefore(): string {
        $reference = \Closure::bind( static function (): string {
            ob_start();
            $root_classes = trim( 'tt-dashboard ' . ShellPreference::rootClass() . ' ' . \TT\Shared\Frontend\ThemePreference::rootClass() );
            echo '<div class="' . esc_attr( $root_classes ) . '">';
            self::renderHeader();
            self::openShell();
            FlashMessages::render();
            do_action( 'tt_dashboard_before_body' );

            $user_id  = get_current_user_id();
            $is_admin = current_user_can( 'tt_edit_settings' );
            $player   = \TT\Infrastructure\Query\QueryHelpers::get_player_for_user( $user_id );
            $view     = isset( $_GET['tt_view'] ) ? sanitize_key( (string) $_GET['tt_view'] ) : '';

            if ( $view !== ''
                 && \TT\Shared\MobileDetector::phoneGateApplies()
                 && \TT\Shared\MobileSurfaceRegistry::isDesktopOnly( $view )
            ) {
                \TT\Shared\Frontend\FrontendMobilePromptView::render( $user_id, $view );
                self::closeShell();
                echo '</div>';
                $output = ob_get_clean() ?: '';
                return apply_filters( 'tt_dashboard_data', $output, $user_id );
            }

            if ( $view !== '' ) {
                if ( \TT\Shared\MobileSurfaceRegistry::isNative( $view ) ) {
                    wp_enqueue_style( 'tt-mobile-patterns', TT_PLUGIN_URL . 'assets/css/mobile-patterns.css', [ 'tt-public' ], TT_VERSION );
                    wp_enqueue_script( 'tt-mobile-helpers', TT_PLUGIN_URL . 'assets/js/mobile-helpers.js', [], TT_VERSION, true );
                }
                \TT\Shared\Frontend\FrontendDesktopPreferredBanner::render( $view );
            }
            \TT\Shared\Frontend\FrontendInstallBanner::render();

            \TT\Infrastructure\Usage\UsageTracker::record(
                $user_id,
                'frontend_view',
                $view !== '' ? $view : 'dashboard'
            );
            $opened_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
            if ( $opened_id > 0
                 && ( ! isset( $_GET['action'] ) || sanitize_key( (string) $_GET['action'] ) !== 'edit' )
                 && ! \TT\Infrastructure\Usage\UsageTracker::isSpeculativeRequest()
            ) {
                ( new \TT\Infrastructure\Search\RecentItems() )->recordView( $user_id, $view, $opened_id );
            }

            if ( $view === '' ) {
                if ( class_exists( '\\TT\\Modules\\PersonaDashboard\\Frontend\\PersonaLandingRenderer' )
                     && \TT\Modules\PersonaDashboard\Frontend\PersonaLandingRenderer::shouldRender() ) {
                    \TT\Modules\PersonaDashboard\Frontend\PersonaLandingRenderer::render( $user_id, self::shortcodeBaseUrl() );
                } else {
                    \TT\Shared\Frontend\FrontendTileGrid::render();
                }
            } elseif ( \TT\Shared\Tiles\TileRegistry::isViewSlugDisabled( $view ) ) {
                self::renderModuleDisabledNotice();
            } elseif ( class_exists( '\\TT\\Core\\FeatureRegistry' )
                       && \TT\Core\FeatureRegistry::viewSlugDisabled( $view ) ) {
                self::renderModuleDisabledNotice();
            } elseif ( ! self::tileCapAllows( $view, $user_id ) ) {
                FrontendBreadcrumbs::fromDashboard( __( 'Not authorized', 'talenttrack' ) );
                echo '<p class="tt-notice">' . esc_html__( 'You do not have access to this surface.', 'talenttrack' ) . '</p>';
            } elseif ( ! self::matrixDispatchAllows( $view, $user_id ) ) {
                FrontendBreadcrumbs::fromDashboard( __( 'Not authorized', 'talenttrack' ) );
                echo '<p class="tt-notice">' . esc_html__( 'You do not have access to this surface.', 'talenttrack' ) . '</p>';
            } else {
                \TT\Shared\Frontend\Components\DevelopmentPill::render( $view );
                if ( ! self::tryDispatch( $view, $user_id, $is_admin, $player ) ) {
                    FrontendBreadcrumbs::fromDashboard( __( 'Page not found', 'talenttrack' ) );
                    echo '<div class="tt-404">' . \TT\Shared\Frontend\Components\Tt404Page::innerHtml( false ) . '</div>';
                }
            }

            self::closeShell();
            echo '</div>';

            $output = ob_get_clean() ?: '';
            return apply_filters( 'tt_dashboard_data', $output, $user_id );
        }, null, DashboardShortcode::class );

        $moved = has_action( 'tt_dashboard_safety_banners', [ SupportGrantBanner::class, 'renderFrontend' ] );
        if ( $moved !== false ) {
            remove_action( 'tt_dashboard_safety_banners', [ SupportGrantBanner::class, 'renderFrontend' ], (int) $moved );
            add_action( 'tt_dashboard_before_body', [ SupportGrantBanner::class, 'renderFrontend' ], 4 );
        }
        try {
            return TokenRoot::wrap( $reference() );
        } finally {
            if ( $moved !== false ) {
                remove_action( 'tt_dashboard_before_body', [ SupportGrantBanner::class, 'renderFrontend' ], 4 );
                add_action( 'tt_dashboard_safety_banners', [ SupportGrantBanner::class, 'renderFrontend' ], (int) $moved );
            }
        }
    }

    /**
     * Renders the request in `$get` before and after, with the same queued
     * flash message in front of each, and asserts the two are identical.
     *
     * @param array<string, string> $get
     */
    private function assertUnchanged( array $get, int $user_id, bool $with_flash = false ): string {
        wp_set_current_user( $user_id );
        $_GET = $get;

        // One warm-up render first, so anything a first render sets up for
        // the request is in place before either side is captured. Then the
        // compared renders are marked speculative, which turns the usage and
        // recent-items writes off on both sides, so neither sees state the
        // other wrote.
        DashboardShortcode::render();
        $_SERVER['HTTP_SEC_PURPOSE'] = 'prefetch';

        // The same queued message, same id, in front of each side, so the
        // dismiss link is byte for byte the same.
        $queue = [];
        if ( $with_flash ) {
            delete_user_meta( $user_id, FlashMessages::META_KEY );
            FlashMessages::add( FlashMessages::TYPE_SUCCESS, 'Evaluation saved', $user_id );
            $queue = FlashMessages::peek( $user_id );
        }

        FrontendBreadcrumbs::reset();
        PresentationSurfaces::flush();
        $before = $this->renderBefore();

        if ( $queue !== [] ) {
            $this->assertSame( [], FlashMessages::peek( $user_id ), 'the reference spent the message' );
            update_user_meta( $user_id, FlashMessages::META_KEY, $queue );
        }

        FrontendBreadcrumbs::reset();
        PresentationSurfaces::flush();
        $after = DashboardShortcode::render();

        unset( $_SERVER['HTTP_SEC_PURPOSE'] );

        $before = $this->withStableIds( $before );
        $after  = $this->withStableIds( $after );

        $this->assertFalse( PresentationSurfaces::isPresenting() );
        $this->assertSame(
            $before,
            $after,
            'an unclaimed slug renders exactly as before: ' . wp_json_encode( $get ) . "\n" . $this->firstDifference( $before, $after )
        );

        return $after;
    }

    /**
     * `FrontendListTable` gives each table a random id per render
     * (`tt-list-` plus eight hex digits). Numbered in order of first
     * appearance, so the structure is still compared and only the random
     * part is not.
     */
    private function withStableIds( string $html ): string {
        $seen = [];
        return (string) preg_replace_callback( '/tt-list-[0-9a-f]{8}\b/', static function ( array $m ) use ( &$seen ): string {
            if ( ! isset( $seen[ $m[0] ] ) ) {
                $seen[ $m[0] ] = 'tt-list-n' . count( $seen );
            }
            return $seen[ $m[0] ];
        }, $html );
    }

    /** Where two renders part, with some context either side, for the failure message. */
    private function firstDifference( string $a, string $b ): string {
        $len = min( strlen( $a ), strlen( $b ) );
        $at  = 0;
        while ( $at < $len && $a[ $at ] === $b[ $at ] ) $at++;
        if ( $at === $len && strlen( $a ) === strlen( $b ) ) return '';
        $from = max( 0, $at - 300 );
        return sprintf(
            "first difference at byte %d of %d / %d\n--- before ---\n%s\n--- after ---\n%s",
            $at,
            strlen( $a ),
            strlen( $b ),
            substr( $a, $from, 700 ),
            substr( $b, $from, 700 )
        );
    }

    public function test_unclaimed_slugs_render_byte_for_byte_as_before(): void {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_players', [
            'club_id' => 1, 'team_id' => 0, 'first_name' => 'Room', 'last_name' => 'Display', 'status' => 'active',
        ] );
        $player_id = (int) $wpdb->insert_id;

        $this->assertUnchanged( [], $this->user_id );
        $this->assertUnchanged( [ 'tt_view' => 'players' ], $this->user_id );
        $this->assertUnchanged( [ 'tt_view' => 'players', 'id' => (string) $player_id ], $this->user_id );
        $this->assertUnchanged( [ 'tt_view' => 'no-such-view-5088' ], $this->user_id );

        $coach = self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $this->assertUnchanged( [ 'tt_view' => 'configuration' ], $coach );
    }

    public function test_an_unclaimed_slug_with_a_flash_message_and_an_alert_renders_as_before(): void {
        $key = $this->bannerAlertKey();
        global $wpdb;
        $now = current_time( 'mysql' );
        $wpdb->insert( $wpdb->prefix . 'tt_alert_occurrences', [
            'uuid'              => wp_generate_uuid4(),
            'club_id'           => 1,
            'alert_key'         => $key,
            'recipient_user_id' => $this->user_id,
            'subject_type'      => 'activity',
            'subject_id'        => 1,
            'dedupe_key'        => $key . '|' . $this->user_id . '|1',
            'severity'          => \TT\Modules\Alerts\Domain\Severity::ATTENTION,
            'payload_json'      => wp_json_encode( [ 'title' => 'Room display alert' ] ),
            'first_seen_at'     => $now,
            'last_seen_at'      => $now,
        ] );

        $html = $this->assertUnchanged( [ 'tt_view' => 'players' ], $this->user_id, true );

        $this->assertStringContainsString( 'Evaluation saved', $html, 'the flash message is in the compared output' );
        $this->assertStringContainsString( 'tt-alert-bar', $html, 'the alert is in the compared output' );
    }

    /** A registered alert whose default surfaces include the banner. */
    private function bannerAlertKey(): string {
        foreach ( \TT\Modules\Alerts\AlertRegistry::all() as $definition ) {
            $surfaces = \TT\Modules\Alerts\Domain\Surface::normalise( $definition->defaultSurfaces() );
            if ( in_array( \TT\Modules\Alerts\Domain\Surface::BANNER, $surfaces, true ) ) {
                return $definition->key();
            }
        }
        $this->fail( 'no registered alert shows on the banner' );
    }
}
