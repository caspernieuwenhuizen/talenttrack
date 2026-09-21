<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Analytics\Reports\PlayerReport;
use TT\Modules\Analytics\Reports\PlayerReportComposition;
use TT\Modules\Analytics\Reports\PlayerReportLayout;
use TT\Modules\Analytics\Reports\RatingsBlockOptions;

/**
 * #3989 — the player report's evaluations by main category, or with the
 * subcategories under them.
 *
 * Pinned: main categories only by default, exactly as before; with `sub`,
 * each subcategory sits under its own parent in tree order, and a main
 * category rated only through its subcategories still gets a row;
 * `has_subcategories` is false when only main categories were rated, and a
 * stored `sub` then renders as main; the option is normalised forgivingly and
 * refused strictly; the print estimate counts the extra rows; and the packet
 * carries the parent id end to end.
 *
 * The caller for the end-to-end cases is a `tt_club_admin` (the
 * `academy_admin` persona); a WordPress administrator would not resolve to it.
 */
final class PlayerReportRatingsDetailTest extends WP_UnitTestCase {

    private const FROM = '2020-03-01';
    private const TO   = '2020-03-31';

    // ---- ratings() on plain packet rows ---------------------------------

    /**
     * Two evaluations, newest first. Main 10 (Technical) with subs 11 and 12;
     * main 20 (Tactical) rated only through its sub 21.
     *
     * @return list<array<string,mixed>>
     */
    private function evaluations(): array {
        return [
            [
                'id' => 2, 'eval_date' => '2020-03-20', 'rating' => 7.0,
                'categories' => [
                    [ 'category_id' => 10, 'label' => 'Technical', 'is_main' => true, 'parent_id' => null, 'rating' => 8.0 ],
                    [ 'category_id' => 12, 'label' => 'Passing', 'is_main' => false, 'parent_id' => 10, 'rating' => 6.0 ],
                    [ 'category_id' => 11, 'label' => 'First touch', 'is_main' => false, 'parent_id' => 10, 'rating' => 9.0 ],
                    [ 'category_id' => 21, 'label' => 'Positioning', 'is_main' => false, 'parent_id' => 20, 'rating' => 5.0 ],
                ],
            ],
            [
                'id' => 1, 'eval_date' => '2020-03-05', 'rating' => 6.0,
                'categories' => [
                    [ 'category_id' => 10, 'label' => 'Technical', 'is_main' => true, 'parent_id' => null, 'rating' => 6.0 ],
                    [ 'category_id' => 11, 'label' => 'First touch', 'is_main' => false, 'parent_id' => 10, 'rating' => 7.0 ],
                ],
            ],
        ];
    }

    /** @return array<int,array{label:string, order:int}> */
    private function tree(): array {
        return [
            10 => [ 'label' => 'Technical', 'order' => 0 ],
            11 => [ 'label' => 'First touch', 'order' => 1 ],
            12 => [ 'label' => 'Passing', 'order' => 2 ],
            20 => [ 'label' => 'Tactical', 'order' => 3 ],
            21 => [ 'label' => 'Positioning', 'order' => 4 ],
        ];
    }

    public function test_the_default_is_main_categories_only(): void {
        $r = PlayerReport::ratings( $this->evaluations() );

        $this->assertSame( [ 10 ], array_column( $r['categories'], 'category_id' ), 'only the rated main category, as before' );
        $this->assertArrayNotHasKey( 'subcategories', $r['categories'][0] );
        $this->assertSame( 8.0, $r['categories'][0]['latest'] );
        $this->assertSame( 7.0, $r['categories'][0]['average'] );
        $this->assertTrue( $r['has_subcategories'], 'said even when not shown, so the panel can offer the choice' );
        $this->assertSame( RatingsBlockOptions::MAIN, $r['detail'] );
    }

    public function test_subcategories_group_under_their_own_parent_in_tree_order(): void {
        $r = PlayerReport::ratings( $this->evaluations(), RatingsBlockOptions::SUB, $this->tree() );

        $this->assertSame( RatingsBlockOptions::SUB, $r['detail'] );
        $this->assertSame( [ 10, 20 ], array_column( $r['categories'], 'category_id' ) );

        $technical = $r['categories'][0];
        $this->assertSame( [ 11, 12 ], array_column( $technical['subcategories'], 'category_id' ), 'tree order, not the order they were rated in' );
        $this->assertSame( 9.0, $technical['subcategories'][0]['latest'], 'the newest evaluation is the latest' );
        $this->assertSame( 8.0, $technical['subcategories'][0]['average'] );
        $this->assertSame( 2, $technical['subcategories'][0]['count'] );

        // Tactical was rated only through Positioning: it still gets a row,
        // with no score of its own, so Positioning has somewhere to sit.
        $tactical = $r['categories'][1];
        $this->assertSame( 'Tactical', $tactical['label'] );
        $this->assertNull( $tactical['latest'] );
        $this->assertSame( [ 21 ], array_column( $tactical['subcategories'], 'category_id' ) );
    }

    public function test_only_main_categories_rated_means_nothing_to_detail(): void {
        $evals = [ [
            'id' => 1, 'eval_date' => '2020-03-05', 'rating' => 6.0,
            'categories' => [ [ 'category_id' => 10, 'label' => 'Technical', 'is_main' => true, 'parent_id' => null, 'rating' => 6.0 ] ],
        ] ];

        $r = PlayerReport::ratings( $evals, RatingsBlockOptions::SUB, $this->tree() );

        $this->assertFalse( $r['has_subcategories'] );
        $this->assertSame( RatingsBlockOptions::MAIN, $r['detail'], 'a stored sub renders as main' );
        $this->assertArrayNotHasKey( 'subcategories', $r['categories'][0] );
    }

    public function test_the_print_estimate_counts_subcategory_rows(): void {
        $main = [ 'data' => [ 'ratings' => PlayerReport::ratings( $this->evaluations(), RatingsBlockOptions::MAIN, $this->tree() ) ] ];
        $sub  = [ 'data' => [ 'ratings' => PlayerReport::ratings( $this->evaluations(), RatingsBlockOptions::SUB, $this->tree() ) ] ];

        $this->assertGreaterThan(
            PlayerReportLayout::fit( $main, PlayerReportLayout::PACK )['fill'][0],
            PlayerReportLayout::fit( $sub, PlayerReportLayout::PACK )['fill'][0]
        );
    }

    // ---- the option -----------------------------------------------------

    public function test_the_option_is_forgiving_on_screen_and_strict_on_rest(): void {
        $this->assertSame( [ 'detail' => 'sub' ], RatingsBlockOptions::normalise( [ 'detail' => 'SUB' ] ) );
        $this->assertSame( [], RatingsBlockOptions::normalise( [ 'detail' => 'main' ] ), 'the default is recorded as absence' );
        $this->assertSame( [], RatingsBlockOptions::normalise( [ 'detail' => 'everything' ] ) );

        $this->assertSame(
            [ 'ratings' => [ 'detail' => 'sub' ] ],
            PlayerReportComposition::normaliseOptions( '{"ratings":{"detail":"sub","detial":"x"},"nonsense":{"a":1}}', [ 'ratings' ] )
        );
        $this->assertSame( [], PlayerReportComposition::normaliseOptions( [ 'ratings' => [ 'detail' => 'sub' ] ], [ 'attendance' ] ), 'options for a section not selected are dropped' );

        $this->assertSame( [ 'ratings.detial' ], PlayerReportComposition::unknownOptions( '{"ratings":{"detial":"sub"}}' ) );
        $this->assertSame( [ 'attendance.detail' ], PlayerReportComposition::unknownOptions( [ 'attendance' => [ 'detail' => 'sub' ] ] ) );
        $this->assertSame( [], PlayerReportComposition::unknownOptions( '{"ratings":{"detail":"sub"}}' ) );
        $this->assertSame( [], PlayerReportComposition::unknownOptions( null ) );
    }

    // ---- end to end -----------------------------------------------------

    public function test_the_report_and_its_route_carry_subcategories_from_the_packet(): void {
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        $admin = (int) self::factory()->user->create( [ 'role' => 'tt_club_admin' ] );
        wp_set_current_user( $admin );
        do_action( 'rest_api_init' );

        global $wpdb;
        $p    = $wpdb->prefix;
        $club = (int) CurrentClub::id();

        $wpdb->insert( "{$p}tt_players", [ 'club_id' => $club, 'first_name' => 'Detail', 'last_name' => 'Player', 'status' => 'active', 'wp_user_id' => null ] );
        $player = (int) $wpdb->insert_id;

        $main = $this->category( $club, 'Zz Technical', null );
        $sub  = $this->category( $club, 'Zz First touch', $main );

        $wpdb->insert( "{$p}tt_evaluations", [ 'club_id' => $club, 'player_id' => $player, 'coach_id' => $admin, 'eval_date' => '2020-03-10', 'notes' => '' ] );
        $eval = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_eval_ratings", [ 'club_id' => $club, 'evaluation_id' => $eval, 'category_id' => $main, 'rating' => 7.0 ] );
        $wpdb->insert( "{$p}tt_eval_ratings", [ 'club_id' => $club, 'evaluation_id' => $eval, 'category_id' => $sub, 'rating' => 8.0 ] );

        $plain = ( new PlayerReport() )->forPlayer( $player, self::FROM, self::TO, [ 'ratings' ], $admin );
        $this->assertNotNull( $plain );
        $this->assertTrue( $plain['data']['ratings']['has_subcategories'] );
        $this->assertArrayNotHasKey( 'subcategories', $plain['data']['ratings']['categories'][0] );

        $detailed = ( new PlayerReport() )->forPlayer( $player, self::FROM, self::TO, [ 'ratings' ], $admin, null, [ 'ratings' => [ 'detail' => 'sub' ] ] );
        $this->assertNotNull( $detailed );
        $cats = $detailed['data']['ratings']['categories'];
        $this->assertSame( $main, $cats[0]['category_id'] );
        $this->assertSame( [ $sub ], array_column( $cats[0]['subcategories'], 'category_id' ) );
        $this->assertSame( 8.0, $cats[0]['subcategories'][0]['latest'] );

        $window = [ 'from' => self::FROM, 'to' => self::TO, 'blocks' => 'ratings' ];

        $ok = $this->route( $player, $window + [ 'options' => '{"ratings":{"detail":"sub"}}' ] );
        $this->assertSame( 200, $ok->get_status() );
        $data = $ok->get_data()['data'];
        $this->assertSame( 'sub', $data['data']['ratings']['detail'] );

        $refused = $this->route( $player, $window + [ 'options' => '{"ratings":{"detial":"sub"}}' ] );
        $this->assertSame( 400, $refused->get_status(), 'an unknown option key is refused, not ignored' );

        wp_set_current_user( 0 );
    }

    private function category( int $club, string $label, ?int $parent ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_eval_categories", [
            'club_id'       => $club,
            'category_key'  => 'zz_' . sanitize_key( $label ) . '_' . wp_rand( 10000, 99999 ),
            'label'         => $label,
            'parent_id'     => $parent,
            'display_order' => 900,
            'is_active'     => 1,
        ] );
        return (int) $wpdb->insert_id;
    }

    /** @param array<string,string> $params */
    private function route( int $player_id, array $params ): \WP_REST_Response {
        $request = new WP_REST_Request( 'GET', '/talenttrack/v1/players/' . $player_id . '/report' );
        foreach ( $params as $key => $value ) {
            $request->set_param( $key, $value );
        }
        return rest_get_server()->dispatch( $request );
    }
}
