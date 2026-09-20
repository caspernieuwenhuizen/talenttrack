<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;

/**
 * The PR-time i18n gate: every new string has a translation in the PR's
 * fragment.
 *
 * Two halves, tested separately because they fail differently:
 *
 * - **extraction** — which strings a PHP file actually declares. A regex
 *   over `__( '…' )` misses a msgid written as a concatenation across
 *   lines, and cannot tell `_x()`'s context argument from its domain. Both
 *   shapes are everywhere in this codebase, and a gate that silently skips
 *   them is worse than no gate: it reports OK on the strings most likely to
 *   ship untranslated.
 * - **the verdict** — which of those strings this PR introduced, and
 *   whether a fragment covers them. That half needs a repository, so it
 *   gets a throwaway one.
 */
final class I18nFragmentGateTest extends WP_UnitTestCase {

    private string $repo = '';

    private function root(): string {
        return dirname( __DIR__, 2 );
    }

    public function set_up(): void {
        parent::set_up();
        require_once $this->root() . '/tools/lib/gettext-calls.php';
    }

    public function tear_down(): void {
        if ( $this->repo !== '' && is_dir( $this->repo ) ) {
            $this->remove_tree( $this->repo );
            $this->repo = '';
        }
        parent::tear_down();
    }

    private function remove_tree( string $dir ): void {
        foreach ( (array) scandir( $dir ) as $entry ) {
            if ( $entry === '.' || $entry === '..' ) {
                continue;
            }
            $path = $dir . '/' . $entry;
            if ( is_dir( $path ) ) {
                $this->remove_tree( $path );
            } else {
                @chmod( $path, 0666 );
                @unlink( $path );
            }
        }
        @rmdir( $dir );
    }

    // ── Extraction ─────────────────────────────────────────────────────

    /** @return array<string, array{msgctxt:string, msgid:string, line:int, function:string}> */
    private function extract( string $php ): array {
        $out = [];
        foreach ( tt_gettext_calls( "<?php\n" . $php ) as $call ) {
            $out[ $call['msgctxt'] . "\x04" . $call['msgid'] ] = $call;
        }

        return $out;
    }

    public function test_plain_and_escaping_helpers_are_found(): void {
        $found = $this->extract( <<<'PHP'
        $a = __( 'Squad', 'talenttrack' );
        $b = esc_html__( 'Minutes', 'talenttrack' );
        $c = esc_attr_e( 'Close', 'talenttrack' );
        PHP );

        $this->assertArrayHasKey( "\x04Squad", $found );
        $this->assertArrayHasKey( "\x04Minutes", $found );
        $this->assertArrayHasKey( "\x04Close", $found );
    }

    /**
     * A long msgid written as a concatenation of literals across lines is
     * one string. A regex takes the first fragment and calls it the msgid,
     * which then matches no catalogue entry at all.
     */
    public function test_a_concatenated_msgid_is_joined(): void {
        $found = $this->extract( <<<'PHP'
        $a = esc_html__(
            'Scheduled sends are skipped while the academy is closed, '
            . 'and resume on the next working day.',
            'talenttrack'
        );
        PHP );

        $this->assertArrayHasKey(
            "\x04Scheduled sends are skipped while the academy is closed, and resume on the next working day.",
            $found
        );
    }

    /** `_x()`'s context is part of the key; the domain is not. */
    public function test_a_context_is_carried_into_the_key(): void {
        $found = $this->extract( "\$a = _x( 'Pass', 'football action', 'talenttrack' );" );

        $this->assertArrayHasKey( "football action\x04Pass", $found );
        $this->assertArrayNotHasKey( "\x04Pass", $found );
    }

    /** A plural's entry is keyed on the singular; the domain sits at 3. */
    public function test_a_plural_is_keyed_on_its_singular(): void {
        $found = $this->extract( <<<'PHP'
        $a = _n( '%d player', '%d players', $count, 'talenttrack' );
        $b = _nx( '%d goal', '%d goals', $count, 'match total', 'talenttrack' );
        PHP );

        $this->assertArrayHasKey( "\x04%d player", $found );
        $this->assertArrayHasKey( "match total\x04%d goal", $found );
    }

    /** Another plugin's domain is another plugin's catalogue. */
    public function test_a_foreign_domain_is_skipped(): void {
        $found = $this->extract( "\$a = __( 'Settings', 'some-other-plugin' );" );

        $this->assertSame( [], $found );
    }

    /**
     * A msgid built at runtime cannot be checked against a catalogue, so
     * it is skipped rather than guessed at — a gate that invents a msgid
     * fails PRs for a string that does not exist.
     */
    public function test_a_dynamic_msgid_is_skipped(): void {
        $found = $this->extract( <<<'PHP'
        $a = __( $label, 'talenttrack' );
        $b = __( self::LABEL, 'talenttrack' );
        $c = __( 'Prefix ' . $name, 'talenttrack' );
        PHP );

        $this->assertSame( [], $found );
    }

    /** The key is compared in the escaped form a .po file stores. */
    public function test_newlines_and_quotes_are_escaped_the_po_way(): void {
        $found = $this->extract( "\$a = __( \"Line one\\nLine \\\"two\\\"\", 'talenttrack' );" );

        $this->assertArrayHasKey( "\x04Line one\\nLine \\\"two\\\"", $found );
    }

    /** A method called `__` is not the gettext function. */
    public function test_a_method_call_is_not_a_gettext_call(): void {
        $found = $this->extract( "\$a = \$translator->__( 'Squad', 'talenttrack' );" );

        $this->assertSame( [], $found );
    }

    // ── The verdict, in a throwaway repository ─────────────────────────

    private function git( string ...$args ): string {
        $cmd = 'git -C ' . escapeshellarg( $this->repo );
        foreach ( $args as $arg ) {
            $cmd .= ' ' . escapeshellarg( $arg );
        }
        $out = [];
        exec( $cmd . ' 2>&1', $out );

        return implode( "\n", $out );
    }

    /** A base commit with one view and a catalogue, plus a head commit. */
    private function scenario( string $head_php, array $fragments ): void {
        $this->repo = rtrim( sys_get_temp_dir(), '/\\' ) . '/tt-gate-' . uniqid();
        mkdir( $this->repo . '/src/Demo', 0777, true );
        mkdir( $this->repo . '/languages/pending', 0777, true );

        copy(
            $this->root() . '/tests/fixtures/po/fragments/catalogue.po',
            $this->repo . '/languages/talenttrack-nl_NL.po'
        );
        file_put_contents(
            $this->repo . '/src/Demo/DemoView.php',
            "<?php\nclass DemoView {\n    public function render() {\n        return '';\n    }\n}\n"
        );

        $this->git( 'init', '--quiet', '-b', 'main' );
        $this->git( 'config', 'user.email', 'gate@example.com' );
        $this->git( 'config', 'user.name', 'Gate' );
        $this->git( 'add', '-A' );
        $this->git( 'commit', '--quiet', '-m', 'base' );
        $this->git( 'branch', 'base' );

        file_put_contents( $this->repo . '/src/Demo/DemoView.php', "<?php\n" . $head_php );
        foreach ( $fragments as $name => $body ) {
            file_put_contents( $this->repo . '/languages/pending/' . $name, $body );
        }
        $this->git( 'add', '-A' );
        $this->git( 'commit', '--quiet', '-m', 'head' );
    }

    /** @return array{status:int, output:string} */
    private function gate(): array {
        $cmd = sprintf(
            '%s %s --root=%s --base=base',
            escapeshellarg( PHP_BINARY ),
            escapeshellarg( $this->root() . '/tools/check-i18n-fragment.php' ),
            escapeshellarg( $this->repo )
        );

        $out    = [];
        $status = 0;
        exec( $cmd . ' 2>&1', $out, $status );

        return [ 'status' => $status, 'output' => implode( "\n", $out ) ];
    }

    private function skip_without_git(): void {
        $out    = [];
        $status = 0;
        exec( 'git --version 2>&1', $out, $status );
        if ( $status !== 0 ) {
            $this->markTestSkipped( 'git is not available.' );
        }
    }

    public function test_an_untranslated_new_string_fails_and_is_named(): void {
        $this->skip_without_git();
        $this->scenario( "echo esc_html__( 'Export as PDF', 'talenttrack' );\n", [] );

        $result = $this->gate();

        $this->assertSame( 1, $result['status'], $result['output'] );
        $this->assertStringContainsString( 'Export as PDF', $result['output'] );
        $this->assertStringContainsString( 'src/Demo/DemoView.php:2', $result['output'] );
    }

    public function test_a_string_covered_by_the_fragment_passes(): void {
        $this->skip_without_git();
        $this->scenario(
            "echo esc_html__( 'Export as PDF', 'talenttrack' );\n",
            [
                '3864-demo.po' => "#: src/Demo/DemoView.php:2\nmsgid \"Export as PDF\"\nmsgstr \"Exporteren als pdf\"\n",
            ]
        );

        $result = $this->gate();

        $this->assertSame( 0, $result['status'], $result['output'] );
    }

    /** An entry in the fragment with an empty msgstr is not a translation. */
    public function test_an_empty_msgstr_in_the_fragment_does_not_satisfy_the_gate(): void {
        $this->skip_without_git();
        $this->scenario(
            "echo esc_html__( 'Export as PDF', 'talenttrack' );\n",
            [
                '3864-demo.po' => "#: src/Demo/DemoView.php:2\nmsgid \"Export as PDF\"\nmsgstr \"\"\n",
            ]
        );

        $result = $this->gate();

        $this->assertSame( 1, $result['status'], $result['output'] );
        $this->assertStringContainsString( 'Export as PDF', $result['output'] );
    }

    /**
     * A string that already ships translated is not this PR's problem, even
     * on a line the PR added — moving a call between files is not drift.
     */
    public function test_a_string_already_in_the_catalogue_passes(): void {
        $this->skip_without_git();
        $this->scenario( "echo esc_html__( 'Squad', 'talenttrack' );\n", [] );

        $result = $this->gate();

        $this->assertSame( 0, $result['status'], $result['output'] );
    }

    /**
     * Untranslated on `main` before this PR touched the file: a note, not a
     * failure. Failing here teaches people to reach for the override label,
     * which is how a gate stops meaning anything.
     */
    public function test_drift_inherited_from_the_base_is_a_note_not_a_failure(): void {
        $this->skip_without_git();
        // The long entry is in the fixture catalogue with an empty msgstr,
        // and the call writes it as a concatenation — so this also pins that
        // a wrapped catalogue entry matches an unwrapped source literal.
        $this->scenario(
            "echo esc_html__(\n"
            . "    'Scheduled sends are skipped while the academy is closed, '\n"
            . "    . 'and resume on the next working day.',\n"
            . "    'talenttrack'\n"
            . ");\n",
            []
        );

        $result = $this->gate();

        $this->assertSame( 0, $result['status'], $result['output'] );
        $this->assertStringContainsString( 'untranslated on base already', $result['output'] );
    }

    /** A PR that adds no strings says so, and says nothing else. */
    public function test_a_php_change_with_no_new_strings_passes(): void {
        $this->skip_without_git();
        $this->scenario( "echo 42;\n", [] );

        $result = $this->gate();

        $this->assertSame( 0, $result['status'], $result['output'] );
        $this->assertStringContainsString( 'no new translatable strings', $result['output'] );
    }

    // ── The duplicate check covers fragments ───────────────────────────

    /** A fragment that carries the same msgid twice fails its own PR. */
    public function test_check_po_duplicates_reads_a_fragment(): void {
        $cmd = sprintf(
            '%s %s --file=%s --base=',
            escapeshellarg( PHP_BINARY ),
            escapeshellarg( $this->root() . '/tools/check-po-duplicates.php' ),
            escapeshellarg( 'tests/fixtures/po/fragments/3903-duplicate.po' )
        );

        $out    = [];
        $status = 0;
        exec( $cmd . ' 2>&1', $out, $status );
        $output = implode( "\n", $out );

        $this->assertSame( 1, $status, $output );
        $this->assertStringContainsString( 'Back to squad', $output );
    }
}
