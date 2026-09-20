<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;

/**
 * The per-PR translation fragments and their consolidation.
 *
 * Two of these assertions exist because the thing they pin went wrong for
 * real during the 2026-09-20 drain, on a repair script that keyed entries
 * by concatenating quoted literals verbatim:
 *
 * - a `msgid` wrapped across continuation lines keyed differently from the
 *   same string on one line, so four entries already present on `main`
 *   were reported missing, and appending them produced three duplicate
 *   msgids that `msgfmt` refuses;
 * - `_n()` plurals are always written `msgid ""` with the text below, so
 *   anything matching `msgid "..."` on a single line misses exactly the
 *   entries that are hardest to get right.
 *
 * The tool is a standalone CLI script, so it is exercised the way the
 * release step runs it: against a sandbox tree, by exit status.
 */
final class TranslationFragmentTest extends WP_UnitTestCase {

    private string $sandbox = '';

    private function root(): string {
        return dirname( __DIR__, 2 );
    }

    private function fixtures(): string {
        return $this->root() . '/tests/fixtures/po/fragments';
    }

    public function tear_down(): void {
        if ( $this->sandbox !== '' && is_dir( $this->sandbox ) ) {
            foreach ( (array) glob( $this->sandbox . '/languages/pending/*' ) as $file ) {
                @unlink( (string) $file );
            }
            foreach ( (array) glob( $this->sandbox . '/languages/*' ) as $file ) {
                @unlink( (string) $file );
            }
            @rmdir( $this->sandbox . '/languages/pending' );
            @rmdir( $this->sandbox . '/languages' );
            @rmdir( $this->sandbox );
            $this->sandbox = '';
        }
        parent::tear_down();
    }

    /**
     * A throwaway repo root holding one catalogue and the named fragments.
     *
     * @param list<string> $fragments fixture file names
     */
    private function sandbox( array $fragments, string $catalogue = 'catalogue.po' ): string {
        $this->sandbox = rtrim( sys_get_temp_dir(), '/\\' ) . '/tt-fragment-' . uniqid();
        mkdir( $this->sandbox . '/languages/pending', 0777, true );

        copy(
            $this->fixtures() . '/' . $catalogue,
            $this->sandbox . '/languages/talenttrack-nl_NL.po'
        );
        foreach ( $fragments as $name ) {
            copy( $this->fixtures() . '/' . $name, $this->sandbox . '/languages/pending/' . $name );
        }

        return $this->sandbox;
    }

    /**
     * @param list<string> $args
     * @return array{status:int, output:string}
     */
    private function consolidate( string $sandbox, array $args = [] ): array {
        $cmd = sprintf(
            '%s %s --root=%s',
            escapeshellarg( PHP_BINARY ),
            escapeshellarg( $this->root() . '/tools/consolidate-translations.php' ),
            escapeshellarg( $sandbox )
        );
        foreach ( $args as $arg ) {
            $cmd .= ' ' . escapeshellarg( $arg );
        }

        $out    = [];
        $status = 0;
        exec( $cmd . ' 2>&1', $out, $status );

        return [ 'status' => $status, 'output' => implode( "\n", $out ) ];
    }

    private function catalogue( string $sandbox ): string {
        return (string) file_get_contents( $sandbox . '/languages/talenttrack-nl_NL.po' );
    }

    // ── The two cases the issue calls out ──────────────────────────────

    /**
     * A fragment carrying a msgid on one line, where the catalogue has the
     * same string wrapped across continuation lines, is ONE entry.
     *
     * The failure this pins is not a crash: it is the tool appending a
     * second copy, git reporting no conflict, and `msgfmt` refusing the
     * catalogue at release time.
     */
    public function test_a_wrapped_msgid_matches_its_unwrapped_copy(): void {
        $sandbox = $this->sandbox( [ '3901-wrapped.po' ] );
        $result  = $this->consolidate( $sandbox );

        $this->assertSame( 0, $result['status'], "Consolidation must succeed. Output:\n" . $result['output'] );

        $catalogue = $this->catalogue( $sandbox );

        $this->assertSame(
            1,
            substr_count( $catalogue, 'Scheduled sends are skipped while the academy is closed' ),
            'The wrapped entry must be recognised and translated in place, never appended a second time.'
        );
        $this->assertStringContainsString(
            'Geplande verzendingen worden overgeslagen',
            $catalogue,
            'The fragment fills in the msgstr of the entry the catalogue already carries untranslated.'
        );
        $this->assertStringContainsString(
            'msgid "Back to squad"',
            $catalogue,
            'The genuinely new entry is appended.'
        );
        // Its original wrapping is untouched: the release diff should read
        // as "this string got translated", not as a reflow.
        $this->assertStringContainsString( "msgid \"\"\n\"Scheduled sends are skipped", $catalogue );
    }

    /** An `_n()` plural block survives consolidation intact. */
    public function test_a_plural_block_consolidates_intact(): void {
        $sandbox = $this->sandbox( [ '3902-plural.po' ] );
        $result  = $this->consolidate( $sandbox );

        $this->assertSame( 0, $result['status'], "Consolidation must succeed. Output:\n" . $result['output'] );

        $catalogue = $this->catalogue( $sandbox );

        $this->assertStringContainsString( 'msgid "%d player in this age group"', $catalogue );
        $this->assertStringContainsString( 'msgid_plural "%d players in this age group"', $catalogue );
        $this->assertStringContainsString( 'msgstr[0] "%d speler in deze leeftijdsgroep"', $catalogue );
        $this->assertStringContainsString( 'msgstr[1] "%d spelers in deze leeftijdsgroep"', $catalogue );

        // The wrapped entry in the same fragment keeps its continuation
        // lines rather than collapsing into one long line.
        $this->assertStringContainsString(
            "\"Geen spelers voldoen aan de huidige filters. Wis een filter om de lijst te \"\n"
            . '"verbreden, of voeg een speler toe."',
            $catalogue
        );
    }

    // ── Two branches, two fragments, no conflict ───────────────────────

    public function test_two_fragments_fold_in_together_and_are_consumed(): void {
        $sandbox = $this->sandbox( [ '3901-wrapped.po', '3902-plural.po' ] );
        $result  = $this->consolidate( $sandbox );

        $this->assertSame( 0, $result['status'], $result['output'] );
        $this->assertSame(
            [],
            glob( $sandbox . '/languages/pending/*.po' ) ?: [],
            'Consumed fragments are deleted, so the folder is empty after a release.'
        );

        $catalogue = $this->catalogue( $sandbox );
        $this->assertStringContainsString( 'msgid "Back to squad"', $catalogue );
        $this->assertStringContainsString( 'msgid "%d player in this age group"', $catalogue );
    }

    /** Every entry is separated by a blank line — glue is what blinds block readers. */
    public function test_entries_stay_blank_line_separated(): void {
        $sandbox = $this->sandbox( [ '3901-wrapped.po', '3902-plural.po' ] );
        $this->consolidate( $sandbox );

        $catalogue = $this->catalogue( $sandbox );
        $lines     = preg_split( '/\R/', $catalogue ) ?: [];

        $glued = [];
        $after_msgstr = false;
        foreach ( $lines as $i => $raw ) {
            $line = trim( $raw );
            if ( $line === '' ) { $after_msgstr = false; continue; }
            $starts = strncmp( $line, 'msgid ', 6 ) === 0
                || strncmp( $line, 'msgctxt ', 8 ) === 0
                || strncmp( $line, '#:', 2 ) === 0;
            if ( $starts && $after_msgstr ) { $glued[] = $i + 1; }
            if ( strncmp( $line, 'msgstr', 6 ) === 0 ) { $after_msgstr = true; }
            elseif ( $starts ) { $after_msgstr = false; }
        }

        $this->assertSame( [], $glued, 'No entry may be glued onto the one before it.' );
    }

    /** The obsolete `#~` block stays at the foot; new entries go above it. */
    public function test_new_entries_land_above_the_obsolete_block(): void {
        $sandbox = $this->sandbox( [ '3901-wrapped.po' ] );
        $this->consolidate( $sandbox );

        $catalogue = $this->catalogue( $sandbox );
        $this->assertLessThan(
            strpos( $catalogue, '#~ msgid "Retired label"' ),
            strpos( $catalogue, 'msgid "Back to squad"' ),
            'An entry written below the `#~` block reads as obsolete to every tool in the chain.'
        );
    }

    // ── The refusals ───────────────────────────────────────────────────

    public function test_a_duplicate_msgid_inside_one_fragment_is_refused(): void {
        $sandbox = $this->sandbox( [ '3903-duplicate.po' ] );
        $result  = $this->consolidate( $sandbox );

        $this->assertSame( 1, $result['status'] );
        $this->assertStringContainsString( 'appears twice in the same fragment', $result['output'] );
        $this->assertStringContainsString( 'Back to squad', $result['output'] );
        $this->assertStringNotContainsString(
            'Back to squad',
            $this->catalogue( $sandbox ),
            'A refusal writes nothing at all.'
        );
    }

    public function test_a_collision_with_an_obsolete_entry_is_refused(): void {
        $sandbox = $this->sandbox( [ '3904-obsolete-collision.po' ] );
        $result  = $this->consolidate( $sandbox );

        $this->assertSame( 1, $result['status'] );
        $this->assertStringContainsString( 'obsolete', $result['output'] );
        $this->assertStringContainsString( 'Retired label', $result['output'] );
    }

    public function test_an_empty_msgstr_in_a_fragment_is_refused(): void {
        $sandbox = $this->sandbox( [ '3905-untranslated.po' ] );
        $result  = $this->consolidate( $sandbox );

        $this->assertSame( 1, $result['status'] );
        $this->assertStringContainsString( 'empty msgstr', $result['output'] );
    }

    public function test_a_misnamed_fragment_is_refused(): void {
        $sandbox = $this->sandbox( [] );
        copy(
            $this->fixtures() . '/3901-wrapped.po',
            $sandbox . '/languages/pending/nl_NL-additions.po'
        );

        $result = $this->consolidate( $sandbox );

        $this->assertSame( 1, $result['status'] );
        $this->assertStringContainsString( '<issue>-<slug>.po', $result['output'] );
    }

    // ── --check and --dry-run write nothing ────────────────────────────

    public function test_check_validates_without_writing_or_consuming(): void {
        $sandbox = $this->sandbox( [ '3901-wrapped.po' ] );
        $before  = $this->catalogue( $sandbox );

        $result = $this->consolidate( $sandbox, [ '--check' ] );

        $this->assertSame( 0, $result['status'], $result['output'] );
        $this->assertSame( $before, $this->catalogue( $sandbox ), '--check never writes.' );
        $this->assertFileExists( $sandbox . '/languages/pending/3901-wrapped.po' );
    }

    public function test_check_fails_on_a_bad_fragment(): void {
        $sandbox = $this->sandbox( [ '3903-duplicate.po' ] );

        $result = $this->consolidate( $sandbox, [ '--check' ] );

        $this->assertSame( 1, $result['status'] );
        $this->assertFileExists(
            $sandbox . '/languages/pending/3903-duplicate.po',
            'A failed check leaves the fragment in place for the author to fix.'
        );
    }

    /** An empty folder is the normal state between releases, not an error. */
    public function test_an_empty_pending_folder_is_not_a_failure(): void {
        $sandbox = $this->sandbox( [] );
        $result  = $this->consolidate( $sandbox );

        $this->assertSame( 0, $result['status'], $result['output'] );
    }

    /** Whatever is sitting in languages/pending/ on this branch must be valid. */
    public function test_the_committed_fragments_validate(): void {
        $cmd = sprintf(
            '%s %s --check',
            escapeshellarg( PHP_BINARY ),
            escapeshellarg( $this->root() . '/tools/consolidate-translations.php' )
        );

        $out    = [];
        $status = 0;
        exec( $cmd . ' 2>&1', $out, $status );

        $this->assertSame(
            0,
            $status,
            "languages/pending/ must hold only valid fragments. Output:\n" . implode( "\n", $out )
        );
    }
}
