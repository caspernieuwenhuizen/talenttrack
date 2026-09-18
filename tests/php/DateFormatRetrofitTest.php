<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;

/**
 * #3528 — the academy's date notation reaches every surface.
 *
 * The setting and its chokepoint shipped in #1481; the retrofit that was
 * supposed to follow never did, so 50 call sites across 40 files read
 * WordPress' `date_format` option directly and silently ignored the academy's
 * choice. A club that picked "Long — 31 December 2026" got it in some places
 * and `juni 30, 2026` in others.
 *
 * This guards the finished state rather than any one surface: the only
 * legitimate reads of the option are the two inside `TTDate`, which is how the
 * `system` preset resolves. A new direct read anywhere else is the bug coming
 * back, one screen at a time.
 */
final class DateFormatRetrofitTest extends WP_UnitTestCase {

    /** The one file allowed to read the WordPress option directly. */
    private const CHOKEPOINT = 'src/Shared/Dates/TTDate.php';

    /** @return list<string> repo-relative paths of files reading the option */
    private function callSites(): array {
        $root = dirname( __DIR__, 2 );
        $out  = [];

        $rii = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator( $root . '/src', \FilesystemIterator::SKIP_DOTS )
        );

        foreach ( $rii as $file ) {
            if ( $file->isDir() || $file->getExtension() !== 'php' ) continue;

            $src = (string) file_get_contents( $file->getPathname() );
            if ( strpos( $src, "get_option( 'date_format'" ) === false ) continue;

            $rel = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $root ) + 1 ) );
            $out[] = $rel;
        }

        sort( $out );
        return $out;
    }

    public function test_only_ttdate_reads_the_wordpress_date_format_option(): void {
        $this->assertSame(
            [ self::CHOKEPOINT ],
            $this->callSites(),
            'A direct date_format read outside TTDate ignores the academy preset on that surface.'
        );
    }

    /**
     * The `system` preset has to keep rendering exactly what WordPress would,
     * or the retrofit is a behaviour change for every install that never
     * touched the setting — which is most of them.
     */
    public function test_the_system_preset_still_matches_the_wordpress_option(): void {
        update_option( 'date_format', 'F j, Y' );

        $this->assertSame( 'F j, Y', \TT\Shared\Dates\TTDate::dateFormat() );

        $ts = strtotime( '2026-06-30 12:00:00' );
        $this->assertSame( wp_date( 'F j, Y', $ts ), \TT\Shared\Dates\TTDate::date( $ts ) );
    }

    /**
     * And a date string renders the same as the `date_i18n( option, strtotime() )`
     * shape most of the retrofitted call sites used, so none of them changed
     * what they print on a default install.
     */
    public function test_a_date_string_renders_as_the_old_shape_did(): void {
        update_option( 'date_format', 'j F Y' );

        $this->assertSame(
            wp_date( 'j F Y', (int) strtotime( '2026-06-30' ) ),
            \TT\Shared\Dates\TTDate::date( '2026-06-30' )
        );
    }
}
