<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;

/**
 * #3236 — no wizard step may post a WordPress public query var.
 *
 * `WP::parse_request()` reads the public query vars from `$_POST` before
 * `$_GET`. A step whose field is called `name` therefore turns its own
 * submission into a singular post lookup, and the step 404s instead of
 * advancing. `Holidays` and `Measurements` both did.
 *
 * There is a CI lint for this (`wizard-form-lint.yml`, scan A), but it
 * only looks inside a hardcoded list of roots — which is how these two
 * went unscanned for as long as they did. This test asks the question of
 * **every** `*Step.php` in the tree, so a new wizard in a new directory is
 * covered whether or not somebody remembers the workflow.
 */
final class WizardReservedFieldNameTest extends WP_UnitTestCase {

    /**
     * The public query vars WP reads from `$_POST`, from
     * `wp-includes/class-wp.php`. Kept in step with the workflow's own
     * list — if one changes, both should.
     *
     * @var list<string>
     */
    private const RESERVED = [
        'm', 'p', 'posts', 'w', 'cat', 'withcomments', 'withoutcomments',
        's', 'search', 'exact', 'sentence', 'calendar', 'page', 'paged',
        'more', 'tb', 'pb', 'author', 'order', 'orderby', 'name', 'feed',
        'tag', 'taxonomy', 'term', 'cpage', 'attachment', 'attachment_id',
        'year', 'monthnum', 'day', 'hour', 'minute', 'second',
        'comments_popup', 'custom',
    ];

    /** @return list<string> */
    private function stepFiles(): array {
        $root = dirname( __DIR__, 2 ) . '/src';
        $out  = [];

        $it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root ) );
        foreach ( $it as $file ) {
            if ( ! $file instanceof \SplFileInfo || ! $file->isFile() ) continue;
            if ( substr( $file->getFilename(), -8 ) !== 'Step.php' ) continue;
            $out[] = $file->getPathname();
        }

        sort( $out );
        return $out;
    }

    public function test_no_wizard_step_posts_a_reserved_query_var(): void {
        $files = $this->stepFiles();
        $this->assertNotEmpty( $files, 'no *Step.php found — the scan is looking in the wrong place' );

        $alternation = implode( '|', array_map( 'preg_quote', self::RESERVED ) );
        // Fields only. A <button name="…"> does not populate a query var
        // the same way, and the wizard's own action buttons all use
        // `tt_wizard_action`.
        $pattern = '/<(?:input|select|textarea)[^>]*\sname=["\'](' . $alternation . ')["\']/i';

        $offenders = [];
        foreach ( $files as $path ) {
            $source = (string) file_get_contents( $path );
            if ( preg_match_all( $pattern, $source, $m ) ) {
                $rel = str_replace( dirname( __DIR__, 2 ) . '/', '', $path );
                foreach ( $m[1] as $field ) {
                    $offenders[] = "{$rel} — name=\"{$field}\"";
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "A wizard step posts a WordPress public query var, which can 404 the step:\n  "
            . implode( "\n  ", $offenders )
            . "\nRename the FORM FIELD (the state key can stay). See #3236."
        );
    }

    /**
     * The two that were broken, pinned by name so a revert is loud rather
     * than a silent return of the 404.
     */
    public function test_the_two_steps_that_were_broken_use_their_new_field_names(): void {
        $root = dirname( __DIR__, 2 );

        $holiday = (string) file_get_contents( $root . '/src/Modules/Holidays/Wizards/HolidayDetailsStep.php' );
        $this->assertStringContainsString( 'name="holiday_name"', $holiday );
        $this->assertStringContainsString( "\$post['holiday_name']", $holiday );

        $measurement = (string) file_get_contents( $root . '/src/Modules/Measurements/Wizards/MeasurementDetailsStep.php' );
        $this->assertStringContainsString( 'name="definition_name"', $measurement );
        $this->assertStringContainsString( "\$post['definition_name']", $measurement );
    }
}
