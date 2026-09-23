<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Authorization\LegacyCapMapper;

/**
 * #3985 — the report wizard is gone (#3955); its leftovers are too.
 *
 * Two halves, both cheap to keep true and expensive to notice by hand: no
 * selector or comment naming the retired wizard survives, and the one thing
 * that was deliberately NOT removed stays removed-proof — `tt_generate_report`
 * is kept although nothing reads it, because it is granted on every existing
 * install and bridged into their matrix.
 */
final class ReportWizardCleanupTest extends WP_UnitTestCase {

    /** @return list<string> */
    private function files( string $dir, string $extensions ): array {
        $root = dirname( __DIR__, 2 ) . '/' . $dir;
        if ( ! is_dir( $root ) ) return [];
        $out = [];
        $it  = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ) );
        foreach ( $it as $file ) {
            if ( ! $file instanceof \SplFileInfo || ! $file->isFile() ) continue;
            if ( ! preg_match( '/\.(' . $extensions . ')$/', $file->getFilename() ) ) continue;
            $out[] = (string) $file->getPathname();
        }
        return $out;
    }

    public function test_nothing_still_names_the_retired_wizard(): void {
        $needles = [ 'tt-rwz', 'tt-report-wizard', 'FrontendReportWizardView' ];
        $files   = array_merge(
            $this->files( 'src', 'php' ),
            $this->files( 'assets', 'css|js' ),
            $this->files( 'config', 'php' )
        );
        $this->assertNotEmpty( $files, 'precondition: the scan found files to read' );

        $hits = [];
        foreach ( $files as $path ) {
            // The redirect that retires the slug is allowed to name it.
            if ( str_ends_with( str_replace( '\\', '/', $path ), 'Reports/Frontend/ReportWizardRedirect.php' ) ) continue;
            $body = (string) file_get_contents( $path );
            foreach ( $needles as $needle ) {
                if ( strpos( $body, $needle ) !== false ) $hits[] = basename( $path ) . ' → ' . $needle;
            }
        }

        $this->assertSame( [], $hits, 'a selector or comment still names the retired report wizard' );
    }

    public function test_the_report_generation_cap_is_kept_and_still_bridged(): void {
        $this->assertContains(
            'tt_generate_report',
            RolesService::REPORT_CAPS,
            'kept on purpose: granted on existing installs, and removing it would rewrite their role grants for nothing'
        );
        $this->assertTrue( LegacyCapMapper::isKnown( 'tt_generate_report' ) );
        $this->assertSame(
            [ 'reports', 'create_delete' ],
            array_values( (array) LegacyCapMapper::tupleFor( 'tt_generate_report' ) ),
            'the bridge is what makes an existing matrix cell keep meaning what it meant'
        );
    }
}
