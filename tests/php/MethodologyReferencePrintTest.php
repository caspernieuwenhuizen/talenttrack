<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\Methodology\MethodologyScope;

// NB: the router lives in the `...\Print` namespace; `Print` is a PHP
// reserved word so it cannot be pulled in via `use` — reference it by
// its fully-qualified name inline instead.

/**
 * #2376 — the printable methodology reference card must follow the
 * ACTIVE methodology set, not merge every shipped set onto one card.
 *
 * Bootstrap seeds two sets: jo14-1-hedel (codes AO/AS/VS/VV) and
 * jo13-1-hedel (codes VD/AV). Flipping MethodologyScope must flip the
 * printed principles — and JO13's VD/AV codes must render (they were
 * dropped by the old code-prefix bucketing).
 */
final class MethodologyReferencePrintTest extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
        MethodologyScope::reset();
    }

    public function tear_down(): void {
        MethodologyScope::reset();
        parent::tear_down();
    }

    private function setId( string $slug ): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}tt_methodologies WHERE slug = %s LIMIT 1",
            $slug
        ) );
    }

    public function test_principles_page_follows_active_methodology(): void {
        $jo14 = $this->setId( 'jo14-1-hedel' );
        $jo13 = $this->setId( 'jo13-1-hedel' );
        $this->assertGreaterThan( 0, $jo14 );
        $this->assertGreaterThan( 0, $jo13 );

        MethodologyScope::set( $jo14 );
        $html14 = \TT\Modules\Methodology\Print\MethodologyReferencePrintRouter::renderHtml( [ 'principles' ] );
        $this->assertStringContainsString( 'AO-01', $html14, 'JO14 card shows JO14 codes' );
        $this->assertStringNotContainsString( 'VD-01', $html14, 'JO14 card must not show JO13 codes' );
        $this->assertStringNotContainsString( 'AV-01', $html14 );

        MethodologyScope::set( $jo13 );
        $html13 = \TT\Modules\Methodology\Print\MethodologyReferencePrintRouter::renderHtml( [ 'principles' ] );
        $this->assertStringContainsString( 'VD-01', $html13, 'JO13 defending code must render (not dropped)' );
        $this->assertStringContainsString( 'AV-01', $html13, 'JO13 attacking code must render (not dropped)' );
        $this->assertStringNotContainsString( 'AO-01', $html13, 'JO13 card must not show JO14 codes' );
    }
}
