<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Stats\PlayerStatsService;
use TT\Shared\Frontend\FrontendComparisonView;

/**
 * #3659 — the comparison read four headline numbers and a category
 * breakdown out of `PlayerStatsService` using keys the service never
 * returned: `recent`, `count`, and `$row->main_label` / `$row->avg` on
 * rows that are arrays. "Most recent" and "Evaluations" printed "—" and
 * the category grid printed its empty state, for every player, always.
 *
 * What is pinned here is the contract between the service and the two
 * comparison surfaces: the key names, and that a player with rated
 * evaluations gets numbers rather than dashes.
 */
final class PlayerComparisonHeadlineKeysTest extends WP_UnitTestCase {

    private int $player_id = 0;
    private int $main_cat  = 0;
    private string $main_label = '';

    public function set_up(): void {
        parent::set_up();

        $this->player_id  = $this->player( 'Compared' );
        $this->main_label = 'Technical ' . wp_rand( 10000, 99999 );
        $this->main_cat   = $this->category( $this->main_label, null );
        $sub              = $this->category( 'First touch ' . wp_rand( 10000, 99999 ), $this->main_cat );

        // Two rated evaluations: the newest carries 7.4, so "Most recent"
        // is 7.4 and both averages land on 6.7.
        $this->rating( $this->evaluation( $this->player_id, '2090-09-01' ), $sub, 6.0 );
        $this->rating( $this->evaluation( $this->player_id, '2090-10-01' ), $sub, 7.4 );
    }

    /** The service's key names — the thing the views got wrong. */
    public function test_the_service_returns_latest_and_eval_count(): void {
        $h = ( new PlayerStatsService() )->getHeadlineNumbers( $this->player_id );

        $this->assertArrayHasKey( 'latest', $h, '"Most recent" reads `latest`, never `recent`.' );
        $this->assertArrayHasKey( 'eval_count', $h, '"Evaluations" reads `eval_count`, never `count`.' );
        $this->assertSame( 7.4, $h['latest'] );
        $this->assertSame( 2, $h['eval_count'] );
        $this->assertSame( 6.7, $h['alltime'] );
    }

    /** The breakdown rows are arrays keyed by main id, not objects. */
    public function test_the_breakdown_row_is_an_array_with_label_and_alltime(): void {
        $rows = ( new PlayerStatsService() )->getMainCategoryBreakdown( $this->player_id );

        $this->assertArrayHasKey( $this->main_cat, $rows );
        $row = $rows[ $this->main_cat ];
        $this->assertIsArray( $row, 'Property access on these rows is what emptied the grid.' );
        $this->assertSame( $this->main_label, $row['label'] );
        $this->assertSame( 6.7, $row['alltime'] );
    }

    public function test_the_comparison_fills_every_headline_row(): void {
        $html = $this->renderComparison();

        $this->assertHeadline( $html, 'Most recent', '7.4' );
        $this->assertHeadline( $html, 'Rolling (last 5)', '6.7' );
        $this->assertHeadline( $html, 'All-time', '6.7' );
        $this->assertHeadline( $html, 'Evaluations', '2' );
    }

    public function test_the_category_grid_carries_the_rated_category(): void {
        $html = $this->renderComparison();

        $this->assertStringNotContainsString(
            'No category data yet for these filters',
            $html,
            'The empty state belongs to a window with no rating in it, not to every comparison.'
        );
        $this->assertHeadline( $html, $this->main_label, '6.70' );
    }

    /** A player with no rated evaluation still gets the honest empty state. */
    public function test_an_unrated_player_still_gets_the_empty_state(): void {
        $bare = $this->player( 'Unrated' );
        $html = $this->renderComparison( $bare );

        $this->assertStringContainsString( 'No category data yet for these filters', $html );
    }

    // helpers

    private function assertHeadline( string $html, string $label, string $value ): void {
        $this->assertMatchesRegularExpression(
            '/tt-fcompare-label">' . preg_quote( $label, '/' ) . '<\/div>\s*'
            . '<div class="tt-fcompare-cell tt-fcompare-num">' . preg_quote( $value, '/' ) . '</',
            $html,
            "The \"{$label}\" row should read {$value}, not a dash."
        );
    }

    private function renderComparison( int $player_id = 0 ): string {
        wp_set_current_user( (int) self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $had  = $_GET;
        $_GET = [ 'tt_view' => 'compare', 'p1' => (string) ( $player_id ?: $this->player_id ) ];

        ob_start();
        FrontendComparisonView::render();
        $html = (string) ob_get_clean();

        $_GET = $had;
        return $html;
    }

    private function player( string $last ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id' => 1, 'first_name' => 'Compare', 'last_name' => $last, 'status' => 'active',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function evaluation( int $player_id, string $date ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_evaluations", [
            'club_id'   => 1,
            'player_id' => $player_id,
            'coach_id'  => 1,
            'eval_date' => $date,
            'notes'     => '',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function category( string $label, ?int $parent ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_eval_categories", [
            'club_id'       => 1,
            'category_key'  => 'zz_' . sanitize_key( $label ),
            'label'         => $label,
            'parent_id'     => $parent,
            'display_order' => 900,
            'is_active'     => 1,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function rating( int $eval_id, int $category_id, float $rating ): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_eval_ratings", [
            'club_id'       => 1,
            'evaluation_id' => $eval_id,
            'category_id'   => $category_id,
            'rating'        => $rating,
        ] );
    }
}
