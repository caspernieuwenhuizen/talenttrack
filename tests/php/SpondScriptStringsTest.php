<?php
namespace TT\Tests\Php;

use PHPUnit\Framework\TestCase;

/**
 * #3247 — every `i18n.<key>` the Spond front-end script reads exists in
 * the PHP bag that localises it.
 *
 * This is the failure the shared `SpondScriptData` was extracted to end.
 * The two views that enqueue `frontend-spond.js` each carried their own
 * copy of the string bag, and the copies had drifted: the group-picker
 * strings existed on the per-team screen and not on the club-wide one,
 * so the same control fell back to hard-coded English on one of the two.
 *
 * Nothing fails loudly when a key is missing — `i18n.foo || 'English'` is
 * the pattern throughout the file — so the drift is invisible until a
 * Dutch operator reads an English sentence. A plain `TestCase`: this
 * greps two files and needs no WordPress.
 */
final class SpondScriptStringsTest extends TestCase {

    private const JS  = 'assets/js/frontend-spond.js';
    private const PHP = 'src/Modules/Spond/SpondScriptData.php';

    private function root(): string {
        return dirname( __DIR__, 2 );
    }

    /** @return list<string> */
    private function keysUsedInJs(): array {
        $js = (string) file_get_contents( $this->root() . '/' . self::JS );
        preg_match_all( '/\bi18n\.([a-z0-9_]+)/i', $js, $m );
        return array_values( array_unique( $m[1] ?? [] ) );
    }

    /** @return list<string> */
    private function keysProvidedInPhp(): array {
        $php = (string) file_get_contents( $this->root() . '/' . self::PHP );
        preg_match_all( "/'([a-z0-9_]+)'\s*=>\s*_/i", $php, $m );
        return array_values( array_unique( $m[1] ?? [] ) );
    }

    public function test_the_script_reads_at_least_the_preview_strings(): void {
        // Guards the regex itself: a rename that broke the match would
        // otherwise make every assertion below pass on an empty set.
        $used = $this->keysUsedInJs();

        $this->assertContains( 'preview_counts', $used );
        $this->assertContains( 'test_ok', $used );
    }

    public function test_every_key_the_script_reads_is_localised(): void {
        $provided = $this->keysProvidedInPhp();
        $missing  = array_values( array_diff( $this->keysUsedInJs(), $provided ) );

        $this->assertSame(
            [],
            $missing,
            'frontend-spond.js reads i18n keys that SpondScriptData does not provide: '
                . implode( ', ', $missing )
        );
    }
}
