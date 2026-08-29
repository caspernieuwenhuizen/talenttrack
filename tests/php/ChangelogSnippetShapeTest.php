<?php
namespace TT\Tests\Php;

use PHPUnit\Framework\TestCase;

/**
 * #3043 — the snippet gate reads the file rather than only counting it.
 *
 * The heading-less case has its own test because that is the one that
 * shipped: four entries titled "Bump: minor" / "Bump: patch" were written
 * into `CHANGES.md` on the first run of v4.108.0, and the version came out
 * a patch bump from a batch containing two minors.
 *
 * A plain `TestCase` — the checker is a pure function over a string and
 * needs no WordPress.
 */
final class ChangelogSnippetShapeTest extends TestCase {

    public static function setUpBeforeClass(): void {
        require_once dirname( __DIR__, 2 ) . '/tools/check-changelog-snippets.php';
    }

    /** @return list<string> */
    private function errorsFor( string $raw ): array {
        return tt_changelog_snippet_problems( $raw )['errors'];
    }

    /** @return list<string> */
    private function warningsFor( string $raw ): array {
        return tt_changelog_snippet_problems( $raw )['warnings'];
    }

    public function test_a_well_formed_snippet_passes(): void {
        $this->assertSame( [], $this->errorsFor(
            "# Weekly planner shows the ISO week (#1730)\n\nBump: patch\n\nThe badge now shows the week number.\n"
        ) );
    }

    public function test_a_bump_marker_on_the_first_line_fails(): void {
        $errors = $this->errorsFor( "Bump: minor\n\nThe thing changed.\n" );

        $this->assertNotEmpty( $errors );
        $this->assertStringContainsString( 'Bump:', $errors[0] );
    }

    public function test_a_heading_less_snippet_fails(): void {
        // Two snippets in the v4.108.0 batch looked exactly like this, and
        // their first prose line became the entry title, wrapped mid-sentence.
        $errors = $this->errorsFor(
            "Page headers on a phone now show two buttons and put the rest behind\nthe menu.\n"
        );

        $this->assertNotEmpty( $errors );
        $this->assertStringContainsString( 'heading', $errors[0] );
    }

    public function test_a_snippet_with_no_body_fails(): void {
        $this->assertNotEmpty( $this->errorsFor( "# A title (#1)\n\nBump: patch\n" ) );
    }

    public function test_two_bump_markers_fail(): void {
        $this->assertNotEmpty( $this->errorsFor(
            "# A title (#1)\n\nBump: patch\n\nProse.\n\nBump: minor\n"
        ) );
    }

    public function test_an_unknown_bump_value_fails(): void {
        $this->assertNotEmpty( $this->errorsFor(
            "# A title (#1)\n\nBump: huge\n\nProse.\n"
        ) );
    }

    public function test_an_empty_file_fails(): void {
        $this->assertNotEmpty( $this->errorsFor( "\n\n" ) );
    }

    public function test_a_missing_issue_number_warns_but_does_not_fail(): void {
        $raw = "# A title with no issue\n\nBump: patch\n\nProse.\n";

        $this->assertSame( [], $this->errorsFor( $raw ) );
        $this->assertNotEmpty( $this->warningsFor( $raw ) );
    }

    public function test_every_snippet_in_the_repo_is_well_formed(): void {
        $dir = dirname( __DIR__, 2 ) . '/changelog.d';
        $checked = 0;

        foreach ( glob( $dir . '/*.md' ) ?: [] as $path ) {
            if ( basename( $path ) === 'README.md' ) continue;
            $checked++;
            $this->assertSame(
                [],
                $this->errorsFor( (string) file_get_contents( $path ) ),
                basename( $path ) . ' is malformed'
            );
        }

        // A release consumes every snippet, so an empty folder is a normal
        // state — assert something either way rather than being marked risky.
        $this->assertGreaterThanOrEqual( 0, $checked );
    }
}
