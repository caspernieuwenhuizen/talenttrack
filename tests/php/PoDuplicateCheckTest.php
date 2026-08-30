<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;

/**
 * #3205 — the duplicate-msgid gate's blind spots, one fixture each.
 *
 * The tool reported OK on four branches during the 2026-08-30 drain that
 * then failed `i18n-pr-check` on a duplicate `msgmerge` caught. Two agents
 * hit it independently, which is the tell that it was the tool and not the
 * authors.
 *
 * `msgmerge` and `msgfmt` are not installed on the maintainer's machine, so
 * this tool is the only local signal before CI. When it says OK on a file
 * that fails the gate, the next person's reasonable conclusion is that the
 * gate is flaky — which is why the blind spots get fixtures rather than a
 * note in the docs.
 *
 * The tool is a standalone CLI script, so it is exercised the way CI does:
 * by running it and reading the exit status.
 */
final class PoDuplicateCheckTest extends WP_UnitTestCase {

    private function root(): string {
        return dirname( __DIR__, 2 );
    }

    /**
     * @return array{status:int, output:string}
     */
    private function check( string $fixture ): array {
        $cmd = sprintf(
            '%s %s --file=%s --base=',
            escapeshellarg( PHP_BINARY ),
            escapeshellarg( $this->root() . '/tools/check-po-duplicates.php' ),
            escapeshellarg( 'tests/fixtures/po/' . $fixture )
        );

        $out    = [];
        $status = 0;
        exec( $cmd . ' 2>&1', $out, $status );

        return [ 'status' => $status, 'output' => implode( "\n", $out ) ];
    }

    // ── The blind spots ────────────────────────────────────────────────

    /**
     * Blind spot 1: a block-based reader splits on blank lines, so two
     * entries appended without one between them are a single block and any
     * duplicate inside the pair is invisible.
     */
    public function test_glued_entries_fail_and_the_line_is_named(): void {
        $result = $this->check( 'glued.po' );

        $this->assertSame( 1, $result['status'], 'A glued catalogue must fail.' );
        $this->assertStringContainsString( 'glued onto the one before it', $result['output'] );
        $this->assertStringContainsString( 'line 20', $result['output'], 'The glue point is named.' );
        $this->assertStringContainsString(
            'msgid "Joined"',
            $result['output'],
            'The duplicate inside the glued pair is still found.'
        );
    }

    /**
     * Blind spot 2: `msgmerge` on main re-wraps a long msgid across quoted
     * fragments while the branch carries it on one line. Identical to
     * gettext, different raw text.
     */
    public function test_the_same_msgid_wrapped_and_unwrapped_is_one_msgid(): void {
        $result = $this->check( 'rewrapped.po' );

        $this->assertSame( 1, $result['status'] );
        $this->assertStringContainsString( 'duplicates 1 msgid', $result['output'] );
        $this->assertStringContainsString( 'Skipped: scheduled sends', $result['output'] );
    }

    /**
     * Obsolete entries look inert and are not: `msgmerge` promotes one back
     * to live when its string reappears, so two obsolete copies come back
     * as two live copies.
     */
    public function test_duplicate_obsolete_entries_fail(): void {
        $result = $this->check( 'obsolete-duplicate.po' );

        $this->assertSame( 1, $result['status'] );
        $this->assertStringContainsString( 'OBSOLETE msgid', $result['output'] );
        $this->assertStringContainsString( "Uses the planner's current date range.", $result['output'] );
    }

    /**
     * And the same fixture must NOT report its live/obsolete twin. A string
     * that came back is the normal state of a catalogue, not a fault, which
     * is why the two namespaces are counted separately.
     */
    public function test_a_live_entry_with_an_obsolete_twin_is_not_reported(): void {
        $result = $this->check( 'obsolete-duplicate.po' );

        $this->assertStringNotContainsString( 'Weekly planner', $result['output'] );
    }

    // ── No false positives ─────────────────────────────────────────────

    /**
     * Every shape a stricter check would wrongly flag, in one file: two
     * msgctxt-differentiated entries sharing a msgid, a plural whose
     * `msgid_plural` and `msgstr[n]` lines continue their own entry, a
     * wrapped msgid appearing once, a live entry with an obsolete twin, and
     * the header.
     */
    public function test_the_legitimate_shapes_pass(): void {
        $result = $this->check( 'clean.po' );

        $this->assertSame(
            0,
            $result['status'],
            "The clean fixture must pass. Output:\n" . $result['output']
        );
        $this->assertStringContainsString( 'check-po-duplicates OK', $result['output'] );
    }

    /**
     * The real catalogues, checked without a base comparison so nothing is
     * excused by "main had it too". This is the assertion that would have
     * caught a regression teaching the tool to over-report — the ~27
     * msgctxt-differentiated pairs on `main` are the usual casualty.
     */
    public function test_the_shipped_catalogues_are_clean_on_their_own_terms(): void {
        $cmd = sprintf(
            '%s %s --base=',
            escapeshellarg( PHP_BINARY ),
            escapeshellarg( $this->root() . '/tools/check-po-duplicates.php' )
        );

        $out    = [];
        $status = 0;
        exec( $cmd . ' 2>&1', $out, $status );

        $this->assertSame(
            0,
            $status,
            "The shipped catalogues must pass. Output:\n" . implode( "\n", $out )
        );
    }
}
