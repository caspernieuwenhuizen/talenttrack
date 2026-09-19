<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Evaluations\EvalRatingsRepository;
use TT\Infrastructure\Stats\PlayerStatsService;
use TT\Modules\Stats\Admin\PlayerCardView;

/**
 * #3702 — "Top performers" never finished loading. Every player card worked
 * out its per-category averages by looping over the player's whole
 * evaluation history and running one or two queries per main category, per
 * evaluation. Twelve cards over a few hundred evaluations each is tens of
 * thousands of roundtrips in one render.
 *
 * Two things are pinned here, and they have to hold together: the batched
 * lookup must give byte-identical answers to the per-evaluation one it
 * replaces, and the cost of a card must not grow with the history behind it.
 */
final class PodiumCardQueryCountTest extends WP_UnitTestCase {

    private int $player_id = 0;
    private int $main_a = 0;
    private int $main_b = 0;
    private int $sub_a1 = 0;
    private int $sub_a2 = 0;
    private int $sub_a_retired = 0;

    /** @var int[] */
    private array $eval_ids = [];

    public function set_up(): void {
        parent::set_up();

        $this->player_id = $this->player( 'Podium' );

        $this->main_a = $this->category( 'ZZ Batch main A', null, true );
        $this->main_b = $this->category( 'ZZ Batch main B', null, true );
        $this->sub_a1 = $this->category( 'ZZ Batch sub A1', $this->main_a, true );
        $this->sub_a2 = $this->category( 'ZZ Batch sub A2', $this->main_a, true );
        // A retired sub-category. effectiveMainRating()'s AVG does not filter
        // on is_active, so the batch must include it too — otherwise
        // switching a sub-category off would silently restate a player's
        // historical ratings.
        $this->sub_a_retired = $this->category( 'ZZ Batch sub A3', $this->main_a, false );
    }

    // Fixtures

    private function player( string $last ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id'    => 1,
            'first_name' => 'Card',
            'last_name'  => $last,
            'status'     => 'active',
            'wp_user_id' => null,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function category( string $label, ?int $parent, bool $active ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_eval_categories", [
            'club_id'       => 1,
            'category_key'  => 'zz_' . sanitize_key( $label ) . '_' . wp_rand( 10000, 99999 ),
            'label'         => $label,
            'parent_id'     => $parent,
            'display_order' => 900,
            'is_active'     => $active ? 1 : 0,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function rate( int $eval_id, int $category_id, float $rating ): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_eval_ratings", [
            'club_id'       => 1,
            'evaluation_id' => $eval_id,
            'category_id'   => $category_id,
            'rating'        => $rating,
        ] );
    }

    /**
     * Seed $n evaluations, cycling through the three storage modes the
     * rollup has to tell apart: a direct main rating, sub-category ratings
     * only, and nothing at all.
     */
    private function seedEvaluations( int $n ): void {
        global $wpdb;
        $start = count( $this->eval_ids );
        for ( $i = $start; $i < $start + $n; $i++ ) {
            $wpdb->insert( "{$wpdb->prefix}tt_evaluations", [
                'club_id'   => 1,
                'player_id' => $this->player_id,
                'coach_id'  => 1,
                'eval_date' => gmdate( 'Y-m-d', (int) strtotime( '2090-01-01 +' . $i . ' days' ) ),
                'notes'     => '',
            ] );
            $eid = (int) $wpdb->insert_id;
            $this->eval_ids[] = $eid;

            $mode = $i % 3;
            if ( $mode === 0 ) {
                $this->rate( $eid, $this->main_a, 7.0 );
            } elseif ( $mode === 1 ) {
                $this->rate( $eid, $this->sub_a1, 6.0 );
                $this->rate( $eid, $this->sub_a2, 7.0 );
                $this->rate( $eid, $this->sub_a_retired, 8.0 );
            }
            // mode 2 leaves main A unrated on purpose.

            // Main B is always a direct rating, so the breakdown has one
            // category with a known, hand-checkable mean.
            $this->rate( $eid, $this->main_b, 5.0 + ( $i % 4 ) );
        }
    }

    private function renderCardQueryCount(): int {
        global $wpdb;
        $before = $wpdb->num_queries;
        ob_start();
        PlayerCardView::renderCard( $this->player_id, 'md', true, 'gold' );
        ob_end_clean();
        return $wpdb->num_queries - $before;
    }

    // The answers must not change

    public function test_batched_lookup_matches_the_per_evaluation_one(): void {
        $this->seedEvaluations( 200 );
        $repo = new EvalRatingsRepository();

        $batched = $repo->effectiveMainRatingsForEvaluations( $this->eval_ids );
        $this->assertCount( 200, $batched, 'Every requested evaluation comes back.' );

        foreach ( $this->eval_ids as $eid ) {
            $this->assertSame(
                $repo->effectiveMainRatingsFor( $eid ),
                $batched[ $eid ],
                "Batched rollup differs from the per-evaluation one for evaluation {$eid}."
            );
        }
    }

    public function test_the_three_storage_modes_are_told_apart(): void {
        $this->seedEvaluations( 3 );
        $batched = ( new EvalRatingsRepository() )->effectiveMainRatingsForEvaluations( $this->eval_ids );

        $direct = $batched[ $this->eval_ids[0] ][ $this->main_a ];
        $this->assertSame( 'direct', $direct['source'] );
        $this->assertSame( 7.0, $direct['value'] );
        $this->assertSame( 0, $direct['sub_count'] );

        // (6 + 7 + 8) / 3 — the retired sub-category counts, as it does in
        // effectiveMainRating()'s AVG.
        $computed = $batched[ $this->eval_ids[1] ][ $this->main_a ];
        $this->assertSame( 'computed', $computed['source'] );
        $this->assertSame( 7.0, $computed['value'] );
        $this->assertSame( 3, $computed['sub_count'] );

        $none = $batched[ $this->eval_ids[2] ][ $this->main_a ];
        $this->assertSame( 'none', $none['source'] );
        $this->assertNull( $none['value'] );
        $this->assertSame( 0, $none['sub_count'] );
    }

    public function test_an_evaluation_with_no_ratings_still_gets_a_row_per_main(): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_evaluations", [
            'club_id' => 1, 'player_id' => $this->player_id, 'coach_id' => 1,
            'eval_date' => '2090-05-05', 'notes' => '',
        ] );
        $eid = (int) $wpdb->insert_id;

        $batched = ( new EvalRatingsRepository() )->effectiveMainRatingsForEvaluations( [ $eid ] );
        $this->assertArrayHasKey( $this->main_a, $batched[ $eid ] );
        $this->assertSame( 'none', $batched[ $eid ][ $this->main_a ]['source'] );
    }

    public function test_the_breakdown_still_averages_the_history(): void {
        $this->seedEvaluations( 12 );
        $breakdown = ( new PlayerStatsService() )->getMainCategoryBreakdown( $this->player_id );

        // Main B carries 5, 6, 7, 8 repeating over twelve evaluations.
        $this->assertSame( 12, $breakdown[ $this->main_b ]['alltime_count'] );
        $this->assertSame( 6.5, $breakdown[ $this->main_b ]['alltime'] );

        // Main A is rated on two evaluations in three: four direct 7.0 and
        // four sub-rollups that also come to 7.0.
        $this->assertSame( 8, $breakdown[ $this->main_a ]['alltime_count'] );
        $this->assertSame( 7.0, $breakdown[ $this->main_a ]['alltime'] );
    }

    // The cost must not grow with the history

    public function test_the_batched_lookup_is_a_fixed_number_of_queries(): void {
        global $wpdb;
        $this->seedEvaluations( 200 );
        $repo = new EvalRatingsRepository();

        $before = $wpdb->num_queries;
        $repo->effectiveMainRatingsForEvaluations( $this->eval_ids );
        $for_200 = $wpdb->num_queries - $before;

        $before = $wpdb->num_queries;
        $repo->effectiveMainRatingsForEvaluations( array_slice( $this->eval_ids, 0, 10 ) );
        $for_10 = $wpdb->num_queries - $before;

        $this->assertSame( $for_10, $for_200, 'Query cost must not depend on the number of evaluations.' );
        $this->assertLessThanOrEqual( 3, $for_200, 'Main categories, the category tree, and the rating rows.' );
    }

    public function test_a_player_card_costs_the_same_at_ten_and_two_hundred_evaluations(): void {
        $this->seedEvaluations( 10 );

        // Warm anything WordPress caches per request (the player row, the
        // team lookup, the photo attachment) so the two measurements below
        // compare the same work.
        $this->renderCardQueryCount();
        $for_10 = $this->renderCardQueryCount();

        $this->seedEvaluations( 190 );
        $for_200 = $this->renderCardQueryCount();

        $this->assertSame( 200, count( $this->eval_ids ) );
        $this->assertLessThanOrEqual(
            $for_10,
            $for_200,
            "A card cost {$for_10} queries over 10 evaluations and {$for_200} over 200 — the per-evaluation query is back."
        );
        $this->assertLessThanOrEqual(
            20,
            $for_200,
            "A player card should cost a small constant number of queries, not {$for_200}."
        );
    }
}
