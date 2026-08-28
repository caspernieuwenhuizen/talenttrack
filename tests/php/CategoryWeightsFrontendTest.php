<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Evaluations\CategoryWeightsRepository;
use TT\Modules\Evaluations\Frontend\FrontendCategoryWeightsView;

/**
 * #2977 — the frontend category-weights surface.
 *
 * The acceptance that matters is *"the wp-admin page and the frontend
 * surface produce identical results — same validation, same reset
 * behaviour, same normalisation"*. The way that is guaranteed is that both
 * call `CategoryWeightsRepository` and neither re-implements it, so these
 * tests exercise the frontend's REST path against the repository's
 * behaviour rather than asserting the two screens' markup.
 *
 * The reset case carries the most weight. Resetting deletes the rows rather
 * than storing 25/25/25/25, which is what keeps "equal because nobody
 * configured it" distinguishable from "equal because somebody chose it" —
 * a distinction the UI shows as a status badge, and one that a helpful
 * future refactor storing the equal values explicitly would silently erase.
 */
final class CategoryWeightsFrontendTest extends WP_UnitTestCase {

    /** @var string */
    private $p;

    /** @var int */
    private $age_group;

    /** @var list<int> */
    private $mains = [];

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->p = $wpdb->prefix;

        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $this->age_group = $this->insertLookup( 'age_group', 'U-test-' . wp_rand( 1000, 9999 ) );
        $this->mains     = [ $this->insertMain( 'ZZ Technical' ), $this->insertMain( 'ZZ Tactical' ) ];
    }

    // ── validation parity ──────────────────────────────────────────────

    public function test_a_set_that_does_not_sum_to_100_is_refused(): void {
        $response = $this->put( [ $this->mains[0] => 60, $this->mains[1] => 30 ] );

        $this->assertSame( 400, $response->get_status() );
        $this->assertSame( [], $this->stored() );
    }

    public function test_a_set_summing_to_100_is_stored(): void {
        $response = $this->put( [ $this->mains[0] => 60, $this->mains[1] => 40 ] );

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame(
            [ $this->mains[0] => 60, $this->mains[1] => 40 ],
            $this->stored()
        );
    }

    public function test_the_refusal_names_the_total_it_got(): void {
        // "Must sum to 100" without saying what it currently is makes the
        // reader add four numbers by hand.
        $data = $this->put( [ $this->mains[0] => 10, $this->mains[1] => 10 ] )->get_data();

        $message = wp_json_encode( $data );
        $this->assertStringContainsString( '20', (string) $message );
    }

    // ── reset ──────────────────────────────────────────────────────────

    public function test_an_empty_weights_object_resets_to_the_equal_fallback(): void {
        $this->put( [ $this->mains[0] => 60, $this->mains[1] => 40 ] );
        $this->assertNotSame( [], $this->stored() );

        $response = $this->put( [] );

        $this->assertSame( 200, $response->get_status() );
        // Deleted, not stored as 50/50 — the distinction the status badge
        // reads.
        $this->assertSame( [], $this->stored() );
    }

    // ── read ───────────────────────────────────────────────────────────

    public function test_an_unconfigured_age_group_is_returned_as_equal_and_flagged(): void {
        $items = $this->get();
        $row   = $this->rowFor( $items, $this->age_group );

        $this->assertNotNull( $row );
        $this->assertFalse( $row['configured'] );

        // Present with real numbers rather than absent: a consumer has to be
        // able to render the effective weighting without knowing the
        // fallback rule.
        $weights = array_column( $row['weights'], 'weight', 'category_id' );
        $this->assertSame( 100, array_sum( $weights ) );
    }

    public function test_a_configured_age_group_reports_its_stored_weights(): void {
        $this->put( [ $this->mains[0] => 70, $this->mains[1] => 30 ] );

        $row = $this->rowFor( $this->get(), $this->age_group );

        $this->assertNotNull( $row );
        $this->assertTrue( $row['configured'] );

        $weights = array_column( $row['weights'], 'weight', 'category_id' );
        $this->assertSame( 70, $weights[ $this->mains[0] ] ?? null );
        $this->assertSame( 30, $weights[ $this->mains[1] ] ?? null );
    }

    // ── permissions ────────────────────────────────────────────────────

    public function test_a_subscriber_cannot_write_weights(): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

        $request = new \WP_REST_Request( 'PUT', '/talenttrack/v1/evaluations/category-weights' );
        $request->set_param( 'age_group_id', $this->age_group );
        $request->set_param( 'weights', [ $this->mains[0] => 60, $this->mains[1] => 40 ] );

        $this->assertSame( 403, rest_get_server()->dispatch( $request )->get_status() );
        $this->assertSame( [], $this->stored() );
    }

    // ── helpers ────────────────────────────────────────────────────────

    /** @param array<int,int> $weights */
    private function put( array $weights ): \WP_REST_Response {
        $request = new \WP_REST_Request( 'PUT', '/talenttrack/v1/evaluations/category-weights' );
        $request->set_param( 'age_group_id', $this->age_group );
        $request->set_param( 'weights', $weights );
        return rest_get_server()->dispatch( $request );
    }

    /** @return array<int,array<string,mixed>> */
    private function get(): array {
        $request  = new \WP_REST_Request( 'GET', '/talenttrack/v1/evaluations/category-weights' );
        $response = rest_get_server()->dispatch( $request );
        $data     = (array) $response->get_data();
        $payload  = (array) ( $data['data'] ?? $data );
        return (array) ( $payload['items'] ?? [] );
    }

    /**
     * @param array<int,array<string,mixed>> $items
     * @return array<string,mixed>|null
     */
    private function rowFor( array $items, int $age_group_id ): ?array {
        foreach ( $items as $item ) {
            if ( (int) ( $item['age_group_id'] ?? 0 ) === $age_group_id ) return (array) $item;
        }
        return null;
    }

    /** @return array<int,int> */
    private function stored(): array {
        return ( new CategoryWeightsRepository() )->getForAgeGroup( $this->age_group );
    }

    private function insertLookup( string $type, string $name ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_lookups", [
            'club_id'     => 1,
            'lookup_type' => $type,
            'name'        => $name,
            'sort_order'  => 900,
        ] );
        return (int) $wpdb->insert_id;
    }

    /**
     * A main category is one with no parent. `category_key` is NOT NULL and
     * UNIQUE, so it is generated per row rather than fixed — two tests in the
     * same run would otherwise collide on it.
     */
    private function insertMain( string $label ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_eval_categories", [
            'category_key'  => 'zz_' . sanitize_key( $label ) . '_' . wp_rand( 10000, 99999 ),
            'label'         => $label,
            'parent_id'     => null,
            'display_order' => 900,
            'is_active'     => 1,
        ] );
        return (int) $wpdb->insert_id;
    }
}
