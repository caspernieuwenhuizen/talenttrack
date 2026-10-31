<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;

/**
 * #4509 — the hard-coded user-facing string gate.
 *
 * The rules are judged line by line, so they are tested directly; the
 * diff-only verdict needs a repository, so it gets a throwaway one with a
 * base commit carrying old violations.
 */
final class HardcodedStringsGateTest extends WP_UnitTestCase {

    private string $repo = '';

    private function root(): string {
        return dirname( __DIR__, 2 );
    }

    public function set_up(): void {
        parent::set_up();
        require_once $this->root() . '/tools/lib/hardcoded-strings.php';
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
            if ( $entry === '.' || $entry === '..' ) continue;
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

    // JS

    public function test_a_literal_written_to_text_content_fails(): void {
        $this->assertNotEmpty( tt_hs_js_line( "el.textContent = 'Save changes';" ) );
    }

    public function test_text_from_the_i18n_object_passes(): void {
        $this->assertSame( [], tt_hs_js_line( 'el.textContent = TT.i18n.save;' ) );
        $this->assertSame( [], tt_hs_js_line( "el.textContent = __( 'Save', 'talenttrack' );" ) );
    }

    public function test_symbol_only_literals_pass(): void {
        $this->assertSame( [], tt_hs_js_line( "cell.textContent = '—';" ) );
        $this->assertSame( [], tt_hs_js_line( "sep.textContent = ' · ';" ) );
    }

    public function test_placeholder_title_aria_and_set_attribute_are_covered(): void {
        $this->assertNotEmpty( tt_hs_js_line( "input.placeholder = 'Search players';" ) );
        $this->assertNotEmpty( tt_hs_js_line( "btn.title = 'Close';" ) );
        $this->assertNotEmpty( tt_hs_js_line( "btn.setAttribute('aria-label', 'Close dialog');" ) );
        $this->assertSame( [], tt_hs_js_line( "btn.setAttribute('aria-label', cfg.i18n.close);" ) );
        $this->assertSame( [], tt_hs_js_line( "if (el.title === 'x') {}" ), 'a comparison is not an assignment' );
    }

    public function test_the_js_marker_exempts_a_line(): void {
        $this->assertSame( [], tt_hs_js_line( "el.title = 'U13'; // tt-i18n-ok" ) );
    }

    // PHP

    public function test_an_echoed_english_literal_fails(): void {
        $this->assertNotEmpty( tt_hs_php_line( "echo 'No players found';" ) );
        $this->assertNotEmpty( tt_hs_php_line( "echo '<p>No players found</p>';" ) );
        $this->assertNotEmpty( tt_hs_php_line( "printf( 'Showing %d players', \$n );" ) );
        $this->assertNotEmpty( tt_hs_php_line( "\$x = esc_html( 'Squad' );" ) );
    }

    public function test_markup_only_and_translated_output_passes(): void {
        $this->assertSame( [], tt_hs_php_line( "echo '<div class=\"tt-card\">';" ) );
        $this->assertSame( [], tt_hs_php_line( "echo '</div>' . esc_html__( 'Squad', 'talenttrack' );" ) );
        $this->assertSame( [], tt_hs_php_line( "echo esc_html__( 'Squad', 'talenttrack' );" ) );
        $this->assertSame( [], tt_hs_php_line( "esc_html_e( 'Squad', 'talenttrack' );" ) );
        $this->assertSame( [], tt_hs_php_line( "printf( '<a href=\"%s\">', esc_url( \$u ) );" ) );
        $this->assertSame( [], tt_hs_php_line( "\$s = sprintf( 'tt_%s', \$k );" ), 'sprintf is not output' );
    }

    public function test_attribute_values_paths_keys_and_comments_are_not_copy(): void {
        $this->assertSame( [], tt_hs_php_line( "echo 'hidden';" ), 'an attribute value' );
        $this->assertSame( [], tt_hs_php_line( "data-rest-path=\"<?php echo esc_attr( 'players/' . \$id ); ?>\"" ), 'a REST path' );
        $this->assertSame( [], tt_hs_php_line( "\$id = esc_attr( 'tt-injury-' . \$n );" ), 'a class prefix' );
        $this->assertSame( [], tt_hs_php_line( "if ( isset( \$_GET['print'] ) && \$_GET['x'] ) {}" ), 'an array key named print' );
        $this->assertSame( [], tt_hs_php_line( "    // the client used to print \"No player attendance\"" ), 'a comment' );
        $this->assertSame( [], tt_hs_php_line( "     * casting it would print \"Array\" plus a notice." ), 'a docblock line' );
        $this->assertSame( [], tt_hs_php_line( "echo \"\\n<style id=\\\"tt-x\\\">\";" ), 'an escaped newline is not the letter n' );
    }

    public function test_the_php_marker_exempts_a_line(): void {
        $this->assertSame( [], tt_hs_php_line( "echo 'U13'; /* tt-i18n-ok */" ) );
    }

    // Dutch msgids

    public function test_a_dutch_msgid_is_flagged(): void {
        $hits = tt_hs_file_violations( 'src/Demo.php', "<?php\n\$a = __( 'Je doel is bijgewerkt', 'talenttrack' );\n" );
        $this->assertArrayHasKey( 2, $hits );
        $this->assertStringContainsString( 'Msgids are English', $hits[2][0] );
    }

    public function test_english_msgids_are_not_flagged(): void {
        foreach ( [
            'Your goal is updated',
            'Time online is observed activity only — it is a lower bound, not exact time in the app.',
            'ISO 639-1 short code (nl, en, fr, de)',
        ] as $msgid ) {
            $this->assertFalse( tt_hs_is_dutch( $msgid ), $msgid );
        }
        $this->assertTrue( tt_hs_is_dutch( '{alert_count} dingen vragen je aandacht' ) );
        $this->assertTrue( tt_hs_is_dutch( 'Hoe gaat het met je doel, {recipient_first_name}?' ) );
    }

    public function test_scope_skips_vendor_and_minified_scripts(): void {
        $this->assertTrue( tt_hs_in_scope( 'assets/js/custom-widgets-builder.js' ) );
        $this->assertTrue( tt_hs_in_scope( 'src/Shared/Frontend/FrontendGoalsManageView.php' ) );
        $this->assertFalse( tt_hs_in_scope( 'assets/js/vendor/jspdf.umd.min.js' ) );
        $this->assertFalse( tt_hs_in_scope( 'assets/js/app.min.js' ) );
        $this->assertFalse( tt_hs_in_scope( 'tools/check-docs.php' ) );
    }

    // The diff-only verdict

    private function git( string ...$args ): void {
        $cmd = 'git -C ' . escapeshellarg( $this->repo );
        foreach ( $args as $arg ) {
            $cmd .= ' ' . escapeshellarg( $arg );
        }
        exec( $cmd . ' 2>&1' );
    }

    /** A base commit whose files already carry violations, then a head commit. */
    private function scenario( string $head_php, string $head_js ): void {
        $this->repo = rtrim( sys_get_temp_dir(), '/\\' ) . '/tt-hs-gate-' . uniqid();
        mkdir( $this->repo . '/src', 0777, true );
        mkdir( $this->repo . '/assets/js', 0777, true );

        file_put_contents( $this->repo . '/src/Demo.php', "<?php\necho 'Old English';\n" );
        file_put_contents( $this->repo . '/assets/js/demo.js', "el.textContent = 'Old English';\n" );

        $this->git( 'init', '--quiet', '-b', 'main' );
        $this->git( 'config', 'user.email', 'gate@example.com' );
        $this->git( 'config', 'user.name', 'Gate' );
        $this->git( 'add', '-A' );
        $this->git( 'commit', '--quiet', '-m', 'base' );
        $this->git( 'branch', 'base' );

        file_put_contents( $this->repo . '/src/Demo.php', "<?php\necho 'Old English';\n" . $head_php );
        file_put_contents( $this->repo . '/assets/js/demo.js', "el.textContent = 'Old English';\n" . $head_js );
        $this->git( 'add', '-A' );
        $this->git( 'commit', '--quiet', '-m', 'head' );
    }

    /** @return array{status:int, output:string} */
    private function gate(): array {
        $cmd = sprintf(
            '%s %s --root=%s --base=base',
            escapeshellarg( PHP_BINARY ),
            escapeshellarg( $this->root() . '/tools/check-hardcoded-strings.php' ),
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

    public function test_old_violations_in_a_touched_file_do_not_fail(): void {
        $this->skip_without_git();
        $this->scenario( "echo esc_html__( 'Squad', 'talenttrack' );\n", "el.textContent = TT.i18n.save;\n" );

        $result = $this->gate();

        $this->assertSame( 0, $result['status'], $result['output'] );
    }

    public function test_an_added_js_literal_fails_and_is_named(): void {
        $this->skip_without_git();
        $this->scenario( '', "btn.textContent = 'Save changes';\n" );

        $result = $this->gate();

        $this->assertSame( 1, $result['status'], $result['output'] );
        $this->assertStringContainsString( 'assets/js/demo.js:2', $result['output'] );
        $this->assertStringNotContainsString( 'demo.js:1', $result['output'], 'the base line is grandfathered' );
    }

    public function test_an_added_dutch_msgid_fails_with_the_english_msgid_message(): void {
        $this->skip_without_git();
        $this->scenario( "\$a = __( 'Je doel is bijgewerkt', 'talenttrack' );\n", '' );

        $result = $this->gate();

        $this->assertSame( 1, $result['status'], $result['output'] );
        $this->assertStringContainsString( 'Msgids are English', $result['output'] );
    }
}
