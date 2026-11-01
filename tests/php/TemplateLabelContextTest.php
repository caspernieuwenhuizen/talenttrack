<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;

/**
 * #4614 — no screen uses the bare "Template" msgid, which ships with
 * "Template" as its Dutch. The training plan, PDP and VCT cycle screens
 * each carry their own context, translated as "Sjabloon".
 */
final class TemplateLabelContextTest extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
        require_once dirname( __DIR__, 2 ) . '/tools/lib/gettext-calls.php';
    }

    public function test_no_bare_template_msgid_remains_in_src(): void {
        $src  = dirname( __DIR__, 2 ) . '/src';
        $bare = [];
        $it   = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $src, \FilesystemIterator::SKIP_DOTS ) );
        foreach ( $it as $file ) {
            if ( $file->getExtension() !== 'php' ) continue;
            $source = (string) file_get_contents( $file->getPathname() );
            // Only files that carry the literal can hold the call.
            if ( strpos( $source, "'Template'" ) === false && strpos( $source, '"Template"' ) === false ) continue;
            foreach ( tt_gettext_calls( $source ) as $c ) {
                if ( $c['msgid'] === 'Template' && $c['msgctxt'] === '' ) {
                    $bare[] = substr( $file->getPathname(), strlen( $src ) + 1 );
                }
            }
        }

        $this->assertSame( [], $bare, 'the bare msgid ships untranslated' );
    }

    public function test_each_surface_has_its_own_context(): void {
        $root  = dirname( __DIR__, 2 ) . '/src/';
        $cases = [
            'Infrastructure/REST/TrainingPlansRestController.php' => [ 'training plan template', 1 ],
            'Modules/Pdp/Frontend/FrontendPdpManageView.php'      => [ 'PDP conversation template', 2 ],
            'Modules/Vct/Frontend/FrontendVctConfigView.php'      => [ 'VCT cycle template', 1 ],
        ];
        foreach ( $cases as $path => [ $context, $expected ] ) {
            $calls = tt_gettext_calls( (string) file_get_contents( $root . $path ) );
            $keyed = array_filter( $calls, static fn ( array $c ): bool => $c['msgid'] === 'Template' && $c['msgctxt'] === $context );
            $this->assertCount( $expected, $keyed, $path );
        }
    }
}
