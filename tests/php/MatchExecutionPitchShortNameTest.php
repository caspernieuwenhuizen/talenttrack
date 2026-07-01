<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use ReflectionMethod;
use TT\Modules\MatchExecution\Frontend\FrontendMatchExecutionView;

/**
 * #2223 — the match-execution pitch labels players by first name + last
 * initial (e.g. "Daan P.") instead of surname. These pin the private
 * pitchShortName() formatter's contract:
 *
 *   - two-token name → "First I.";
 *   - single-token name → unchanged, no stray dot;
 *   - three-token name uses the FIRST token + the LAST token's initial;
 *   - empty input → empty string.
 */
final class MatchExecutionPitchShortNameTest extends WP_UnitTestCase {

    private function shortName( string $name ): string {
        $m = new ReflectionMethod( FrontendMatchExecutionView::class, 'pitchShortName' );
        $m->setAccessible( true );
        return (string) $m->invoke( null, $name );
    }

    public function test_two_token_name_is_first_plus_last_initial(): void {
        $this->assertSame( 'Daan P.', $this->shortName( 'Daan Portzgen' ) );
    }

    public function test_single_token_name_has_no_dot(): void {
        $this->assertSame( 'Senna', $this->shortName( 'Senna' ) );
    }

    public function test_three_token_name_uses_first_and_last_initial(): void {
        $this->assertSame( 'Jan V.', $this->shortName( 'Jan van Dijk' ) );
    }

    public function test_empty_name_returns_empty_string(): void {
        $this->assertSame( '', $this->shortName( '   ' ) );
    }
}
