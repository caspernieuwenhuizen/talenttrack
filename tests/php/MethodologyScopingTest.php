<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\Methodology\MethodologyScope;
use TT\Modules\Methodology\Repositories\PrinciplesRepository;

/**
 * #2319 (epic #2316) — repository scoping by the active methodology set.
 *
 * A principle authored in set A must not surface when set B is active,
 * and a create must stamp the active set. This is the guarantee that
 * makes two selectable methodologies actually isolate their content.
 */
final class MethodologyScopingTest extends WP_UnitTestCase {

    private int $defaultSetId = 0;
    private int $secondSetId  = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        MethodologyScope::reset();

        $this->defaultSetId = (int) $wpdb->get_var(
            "SELECT id FROM {$wpdb->prefix}tt_methodologies WHERE club_id = 1 AND is_default = 1 LIMIT 1"
        );
        $wpdb->insert( $wpdb->prefix . 'tt_methodologies', [
            'club_id' => 1, 'uuid' => wp_generate_uuid4(), 'slug' => 'scope-test-b',
            'name_json' => wp_json_encode( [ 'nl' => 'Set B', 'en' => 'Set B' ] ),
            'is_default' => 0, 'is_shipped' => 0,
        ] );
        $this->secondSetId = (int) $wpdb->insert_id;

        $this->seedPrinciple( 'ZZ-DEF', $this->defaultSetId );
        $this->seedPrinciple( 'ZZ-SEC', $this->secondSetId );
    }

    public function tear_down(): void {
        MethodologyScope::reset();
        parent::tear_down();
    }

    public function test_active_set_lists_only_its_own_principles(): void {
        MethodologyScope::set( $this->defaultSetId );
        $codes = $this->codes( ( new PrinciplesRepository() )->listFiltered() );
        $this->assertContains( 'ZZ-DEF', $codes );
        $this->assertNotContains( 'ZZ-SEC', $codes, 'set B content must not leak into set A' );
    }

    public function test_switching_scope_switches_content(): void {
        MethodologyScope::set( $this->secondSetId );
        $codes = $this->codes( ( new PrinciplesRepository() )->listFiltered() );
        $this->assertContains( 'ZZ-SEC', $codes );
        $this->assertNotContains( 'ZZ-DEF', $codes );
    }

    public function test_explicit_filter_overrides_ambient_scope(): void {
        MethodologyScope::set( $this->defaultSetId );
        $codes = $this->codes(
            ( new PrinciplesRepository() )->listFiltered( [ 'methodology_id' => $this->secondSetId ] )
        );
        $this->assertContains( 'ZZ-SEC', $codes );
        $this->assertNotContains( 'ZZ-DEF', $codes );
    }

    public function test_create_stamps_the_active_set(): void {
        MethodologyScope::set( $this->secondSetId );
        $repo = new PrinciplesRepository();
        $id   = $repo->create( [
            'code' => 'ZZ-NEW', 'team_function_key' => 'aanvallen', 'team_task_key' => 'opbouwen',
            'title_json' => [ 'nl' => 'Nieuw', 'en' => 'New' ],
        ] );
        $this->assertGreaterThan( 0, $id );
        $row = $repo->find( $id );
        $this->assertSame( $this->secondSetId, (int) $row->methodology_id );
    }

    private function seedPrinciple( string $code, int $set_id ): void {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_principles', [
            'club_id'           => 1,
            'code'              => $code,
            'team_function_key' => 'aanvallen',
            'team_task_key'     => 'opbouwen',
            'methodology_id'    => $set_id,
            'is_shipped'        => 0,
        ] );
    }

    /** @param object[] $rows @return string[] */
    private function codes( array $rows ): array {
        return array_map( static fn( $r ) => (string) $r->code, $rows );
    }
}
