<?php
namespace TT\Tests\Php;

use TT\Modules\License\UpgradePanel;
use TT\Shared\Frontend\DashboardShortcode;
use WP_UnitTestCase;

/**
 * #4796 — nothing inside `.tt-dashboard` carries its own `tt-root` class.
 *
 * `tokens.css` declares its defaults on `.tt-root`, and the themes
 * redeclare their values on `.tt-dashboard`. A `.tt-root` below
 * `.tt-dashboard` therefore reset the theme's neutrals to the defaults
 * for everything it contained: the guardian contact view and the upgrade
 * panel showed the default greys on a Leon Hutten or federation install.
 * The shell's own wrapper, outside `.tt-dashboard`, is the only token root
 * a surface needs (#4772).
 */
final class NoInnerTokenRootTest extends WP_UnitTestCase {

    /** @var array<string, mixed> */
    private $get = [];

    public function setUp(): void {
        parent::setUp();
        $this->get = $_GET;
    }

    public function tearDown(): void {
        $_GET = $this->get;
        parent::tearDown();
    }

    /**
     * Elements carrying `tt-root` that sit inside a `.tt-dashboard`.
     */
    private function innerRoots( string $html ): int {
        $doc = new \DOMDocument();
        libxml_use_internal_errors( true );
        $doc->loadHTML( '<?xml encoding="utf-8"?><html><body>' . $html . '</body></html>' );
        libxml_clear_errors();

        $found = ( new \DOMXPath( $doc ) )->query(
            "//*[contains(concat(' ', normalize-space(@class), ' '), ' tt-dashboard ')]"
            . "//*[contains(concat(' ', normalize-space(@class), ' '), ' tt-root ')]"
        );

        return $found === false ? 0 : $found->length;
    }

    /**
     * @return string[] the class tokens of every element in `$html`
     */
    private function classTokens( string $html ): array {
        preg_match_all( '/class="([^"]*)"/', $html, $m );
        return preg_split( '/\s+/', implode( ' ', $m[1] ) ) ?: [];
    }

    public function test_the_guardian_contact_view_inherits_the_shell_tokens(): void {
        $_GET = [ 'tt_view' => 'guardian-contact' ];
        $html = DashboardShortcode::render();

        $this->assertStringContainsString( 'tt-guardian-contact', $html );
        $this->assertSame( 0, $this->innerRoots( $html ) );
    }

    public function test_the_upgrade_panel_carries_no_token_root(): void {
        $panels = [
            'cap'     => UpgradePanel::renderCap( 'teams' ),
            'revoked' => UpgradePanel::renderRevokedShareLink(),
            'paused'  => UpgradePanel::interruptedShell( 'Reports' ),
            'locked'  => UpgradePanel::renderLabelled( 'Reports', 'pro' ),
        ];

        foreach ( $panels as $name => $html ) {
            $this->assertStringContainsString( 'tt-upgrade-panel', $html, $name );
            $this->assertNotContains( 'tt-root', $this->classTokens( $html ), $name );
        }
    }

    /**
     * No view or component writes `tt-root` into its own markup. The two
     * standalone print documents put it on their `<body>`, which is not
     * inside a `.tt-dashboard`, and `TokenRoot` is the wrapper itself.
     */
    public function test_no_component_writes_its_own_token_root(): void {
        $root    = dirname( __DIR__, 2 ) . '/src';
        $allowed = [
            'Shared/Frontend/TokenRoot.php',
            'Modules/MatchAnalysis/Print/MatchAnalysisPrintRouter.php',
            'Modules/Trials/Print/TrialLetterPrintRouter.php',
        ];
        $offenders = [];

        $files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ) );
        foreach ( $files as $file ) {
            if ( $file->getExtension() !== 'php' ) continue;
            $relative = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $root ) + 1 ) );
            if ( in_array( $relative, $allowed, true ) ) continue;
            if ( preg_match( '/class=(\\\\?["\'])(?:[^"\']*\s)?tt-root(?:\s|\\\\?["\'])/', (string) file_get_contents( $file->getPathname() ) ) ) {
                $offenders[] = $relative;
            }
        }

        $this->assertSame( [], $offenders );
    }
}
