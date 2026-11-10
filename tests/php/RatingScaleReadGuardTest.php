<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Evaluations\RatingScale;
use TT\Infrastructure\Query\QueryHelpers;

/**
 * #4987 (P1 on epic #4975) — the academy's rating scale has one reader.
 *
 * `rating_min`, `rating_max` and `rating_step` were read in some fifty
 * places, most with the pre-#1641 defaults (a maximum of 10, a step of 0.5)
 * and a few with the locked ones (9 and 1), so "what is the scale" had two
 * answers on an install without the rows. `RatingScale` now owns the read
 * and the defaults; this test fails on a direct read anywhere else.
 */
final class RatingScaleReadGuardTest extends WP_UnitTestCase {

    private const OWNER = 'src/Infrastructure/Evaluations/RatingScale.php';

    public function test_no_file_outside_rating_scale_reads_the_three_keys(): void {
        $root  = dirname( __DIR__, 2 );
        $found = [];
        $files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/src', \FilesystemIterator::SKIP_DOTS ) );
        foreach ( $files as $file ) {
            if ( ! $file instanceof \SplFileInfo || $file->getExtension() !== 'php' ) continue;
            $path = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $root ) + 1 ) );
            if ( $path === self::OWNER ) continue;
            foreach ( self::directReads( (string) file_get_contents( $file->getPathname() ) ) as $line ) {
                $found[] = $path . ':' . $line;
            }
        }
        $this->assertSame( [], $found, 'Read the rating scale through RatingScale::current() / currentMin() / currentMax() / currentStep(), not tt_config directly.' );
    }

    public function test_the_guard_finds_a_planted_read(): void {
        $planted = "<?php\n\$max = (float) QueryHelpers::get_config( 'rating_max', '10' );\n"
            . "\$step = \\TT\\Infrastructure\\Query\\QueryHelpers::get_config(\"rating_step\");\n"
            . "\$row = \$wpdb->get_var( \"SELECT config_value FROM tt_config WHERE config_key = 'rating_min'\" );\n"
            . "\$fine = RatingScale::currentMax();\n";
        $this->assertSame( [ 2, 3, 4 ], self::directReads( $planted ), 'each kind of direct read is found, and the owner\'s API is not' );
    }

    public function test_the_defaults_are_the_locked_scale_and_the_form_mapping_reads_the_same(): void {
        // A club with no scale rows reads the defaults (the config cache is
        // per club, so this does not disturb the current one).
        $none = static fn(): int => 987654;
        add_filter( 'tt_current_club_id', $none );
        try {
            $this->assertSame( 5.0, RatingScale::currentMin() );
            $this->assertSame( 9.0, RatingScale::currentMax(), 'one default: 9, not the pre-#1641 10' );
            $this->assertSame( 1.0, RatingScale::currentStep(), 'one default step: 1, not 0.5' );
        } finally {
            remove_filter( 'tt_current_club_id', $none );
        }

        QueryHelpers::set_config( 'rating_min', '5' );
        QueryHelpers::set_config( 'rating_max', '9' );
        QueryHelpers::set_config( 'rating_step', '1' );
        $scale = RatingScale::current();
        $this->assertTrue( $scale->fitsFormSteps() );
        foreach ( [ 1 => 5.0, 2 => 6.0, 3 => 7.0, 4 => 8.0, 5 => 9.0 ] as $step => $stored ) {
            $this->assertSame( $stored, $scale->toStored( $step ), "the #4976 mapping: form step {$step}" );
            $this->assertSame( $step, $scale->toFormStep( $stored ) );
        }
    }

    /**
     * The line numbers of direct reads in a PHP source: `get_config()` with
     * one of the three keys, or SQL naming one as a `config_key`.
     *
     * @return list<int>
     */
    private static function directReads( string $code ): array {
        $out = [];
        foreach ( preg_split( '/\R/', $code ) ?: [] as $i => $line ) {
            if ( preg_match( '/get_config\(\s*[\'"]rating_(min|max|step)[\'"]/', $line ) === 1
                || preg_match( '/config_key\s*=\s*[\'"]rating_(min|max|step)[\'"]/', $line ) === 1 ) {
                $out[] = $i + 1;
            }
        }
        return $out;
    }
}
