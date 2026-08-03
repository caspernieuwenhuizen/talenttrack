<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\Methodology\MethodologyScope;
use TT\Modules\Methodology\Repositories\PrinciplesRepository;

/**
 * #2321 (epic #2316) — the JO13-1 Hedel methodology seed.
 *
 * The bootstrap runs every plugin migration once, so migration 0202 has
 * already created the shipped "jo13-1-hedel" set and seeded its content.
 * This suite locks that contract: the set exists, and its principles are
 * reachable — and isolated — when it is the active scope.
 */
final class MethodologyJo13SeedTest extends WP_UnitTestCase {

    private int $jo13SetId    = 0;
    private int $defaultSetId = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        MethodologyScope::reset();

        $this->jo13SetId = (int) $wpdb->get_var(
            "SELECT id FROM {$wpdb->prefix}tt_methodologies WHERE club_id = 1 AND slug = 'jo13-1-hedel' LIMIT 1"
        );
        $this->defaultSetId = (int) $wpdb->get_var(
            "SELECT id FROM {$wpdb->prefix}tt_methodologies WHERE club_id = 1 AND is_default = 1 LIMIT 1"
        );
    }

    public function tear_down(): void {
        MethodologyScope::reset();
        parent::tear_down();
    }

    public function test_jo13_set_is_shipped_and_not_default(): void {
        global $wpdb;
        $this->assertGreaterThan( 0, $this->jo13SetId, 'jo13-1-hedel set must exist for club 1' );

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT is_shipped, is_default FROM {$wpdb->prefix}tt_methodologies WHERE id = %d",
            $this->jo13SetId
        ) );
        $this->assertSame( 1, (int) $row->is_shipped );
        $this->assertSame( 0, (int) $row->is_default );
    }

    public function test_jo13_principles_reachable_when_scoped(): void {
        MethodologyScope::set( $this->jo13SetId );
        $codes = $this->codes( ( new PrinciplesRepository() )->listFiltered() );

        $this->assertContains( 'VD-01', $codes, 'JO13 defending principle must be listed under its own scope' );
        $this->assertContains( 'AV-01', $codes );
        $this->assertContains( 'OA-01', $codes );
    }

    public function test_default_only_principle_not_in_jo13_scope(): void {
        // AO-01 belongs to the shipped default set (migration 0018), stamped
        // with the default methodology_id by 0200 — it must not leak into the
        // JO13 scope.
        MethodologyScope::set( $this->jo13SetId );
        $codes = $this->codes( ( new PrinciplesRepository() )->listFiltered() );

        $this->assertNotContains( 'AO-01', $codes, 'default-set principle must not appear under the JO13 scope' );
    }

    /** @param object[] $rows @return string[] */
    private function codes( array $rows ): array {
        return array_map( static fn( $r ) => (string) $r->code, $rows );
    }
}
