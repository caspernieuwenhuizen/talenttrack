<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;

/**
 * #5118 — the gate behind `tools/check-archive-list-view.php`, on snippets:
 * a `filterClause()` call outside the archive code passes only a literal
 * ordinary-list state, a choice between two of them, or a `listView()`
 * result; anything else could hand the recycle bin to a list.
 */
final class ArchiveListViewGateTest extends WP_UnitTestCase {

    public static function set_up_before_class(): void {
        parent::set_up_before_class();
        require_once dirname( __DIR__, 2 ) . '/tools/lib/archive-list-view.php';
    }

    public function test_the_gate_finds_a_state_that_could_be_the_bin(): void {
        $code = "<?php\n"
            . "\$a = ArchiveRepository::filterClause( \$view, 'g' );\n"
            . "\$b = \\TT\\Infrastructure\\Archive\\ArchiveRepository::filterClause( ArchiveRepository::sanitizeView( \$_GET['tt_view'] ), 'g' );\n"
            . "\$c = ArchiveRepository::filterClause( 'trashed', 'g' );\n"
            . "\$d = ArchiveRepository::filterClause( 'active', 'g' );\n"
            . "\$e = ArchiveRepository::filterClause( ArchiveRepository::listView( \$raw ), 'g' );\n"
            . "\$f = ArchiveRepository::filterClause(\n    \$archived === 'archived' ? 'archived' : 'active',\n    'p'\n);\n"
            . "\$g = ArchiveRepository::listViewClause( \$view, 'g' );\n"
            . "// ArchiveRepository::filterClause( \$view ) in a comment\n";
        $this->assertSame( [ 2, 3, 4 ], tt_archive_view_offenders( $code ) );
    }

    public function test_the_repository_passes(): void {
        $root = dirname( __DIR__, 2 );
        $out  = [];
        $it   = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/src', \FilesystemIterator::SKIP_DOTS ) );
        foreach ( $it as $file ) {
            if ( ! $file instanceof \SplFileInfo || $file->getExtension() !== 'php' ) continue;
            $path = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $root ) + 1 ) );
            foreach ( TT_ARCHIVE_VIEW_EXEMPT as $exempt ) {
                if ( strpos( $path, $exempt ) === 0 ) continue 2;
            }
            foreach ( tt_archive_view_offenders( (string) file_get_contents( $file->getPathname() ) ) as $line ) {
                $out[] = "{$path}:{$line}";
            }
        }
        $this->assertSame( [], $out );
    }
}
