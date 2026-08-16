<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Activities\Reports\RatingsGridQuery;
use TT\Modules\Wizards\Evaluation\EvaluationInserter;

/**
 * #2414 (epic #2381) — the ratings grid: one activity, players ×
 * evaluation categories, one directly-typed score per cell.
 *
 * The properties worth pinning are the ones that make the grid safe to
 * re-save (which is what a grid is for): a blank cell writes nothing and
 * clears nothing, saving twice must not duplicate the player's evaluation,
 * and the bulk endpoint must refuse anything outside the roster, the
 * activity's categories or the configured scale.
 */
final class RatingsGridTest extends WP_UnitTestCase {

    private int $team_id = 0;
    private int $activity_id = 0;
    private int $player_id = 0;
    private int $cat_id = 0;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->ensureCapabilities();

        global $wpdb, $wp_rest_server;

        $wpdb->insert( $wpdb->prefix . 'tt_teams', [ 'name' => 'Grid FC', 'club_id' => 1 ] );
        $this->team_id = (int) $wpdb->insert_id;

        $wpdb->insert( $wpdb->prefix . 'tt_activities', [
            'title'             => 'Friendly',
            'session_date'      => '2026-08-10',
            'team_id'           => $this->team_id,
            'club_id'           => 1,
            'activity_type_key' => 'training',
        ] );
        $this->activity_id = (int) $wpdb->insert_id;

        $wpdb->insert( $wpdb->prefix . 'tt_players', [
            'first_name' => 'Sven',
            'last_name'  => 'Jansen',
            'team_id'    => $this->team_id,
            'club_id'    => 1,
            'status'     => 'active',
        ] );
        $this->player_id = (int) $wpdb->insert_id;

        $wpdb->insert( $wpdb->prefix . 'tt_eval_categories', [
            'category_key'  => 'passing_' . $this->activity_id,
            'label'         => 'Passing',
            'display_order' => 1,
            'is_active'     => 1,
        ] );
        $this->cat_id = (int) $wpdb->insert_id;

        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    private function post( array $changes ): \WP_REST_Response {
        $req = new WP_REST_Request( 'POST', '/talenttrack/v1/activities/' . $this->activity_id . '/ratings/bulk' );
        $req->set_body_params( [ 'changes' => $changes ] );
        return rest_get_server()->dispatch( $req );
    }

    private function ratingsRowCount(): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_eval_ratings r
               INNER JOIN {$wpdb->prefix}tt_evaluations e ON e.id = r.evaluation_id
              WHERE e.activity_id = %d",
            $this->activity_id
        ) );
    }

    private function evaluationRowCount(): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_evaluations WHERE activity_id = %d",
            $this->activity_id
        ) );
    }

    // ---- read model ------------------------------------------------------

    public function test_grid_rows_are_the_active_roster(): void {
        $data = RatingsGridQuery::forActivity( $this->activity_id );

        $this->assertNotNull( $data['activity'] );
        $ids = array_map( static fn( $p ) => (int) $p->id, $data['players'] );
        $this->assertContains( $this->player_id, $ids );
    }

    public function test_unrated_cells_are_absent_not_zero(): void {
        $data = RatingsGridQuery::forActivity( $this->activity_id );
        $this->assertSame( [], $data['values'], 'nothing rated yet means no values at all' );
    }

    // ---- writer ----------------------------------------------------------

    public function test_upsert_creates_one_evaluation_then_reuses_it(): void {
        EvaluationInserter::upsertForActivity( $this->player_id, $this->activity_id, [ $this->cat_id => 7.5 ] );
        $this->assertSame( 1, $this->evaluationRowCount() );
        $this->assertSame( 1, $this->ratingsRowCount() );

        // Re-saving the same cell must UPDATE, not append.
        EvaluationInserter::upsertForActivity( $this->player_id, $this->activity_id, [ $this->cat_id => 8.0 ] );
        $this->assertSame( 1, $this->evaluationRowCount(), 'a second save must not duplicate the evaluation' );
        $this->assertSame( 1, $this->ratingsRowCount(), 'a second save must not duplicate the rating row' );

        $data = RatingsGridQuery::forActivity( $this->activity_id );
        $this->assertSame( 8.0, $data['values'][ $this->player_id ][ $this->cat_id ] );
    }

    public function test_blank_rating_writes_nothing_and_clears_nothing(): void {
        EvaluationInserter::upsertForActivity( $this->player_id, $this->activity_id, [ $this->cat_id => 7.0 ] );
        EvaluationInserter::upsertForActivity( $this->player_id, $this->activity_id, [ $this->cat_id => null ] );

        $data = RatingsGridQuery::forActivity( $this->activity_id );
        $this->assertSame(
            7.0,
            $data['values'][ $this->player_id ][ $this->cat_id ],
            'a blank cell must not erase a score somebody already recorded'
        );
    }

    // ---- endpoint --------------------------------------------------------

    public function test_endpoint_denies_a_user_without_the_capability(): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
        $this->assertContains( $this->post( [] )->get_status(), [ 401, 403 ] );
    }

    public function test_endpoint_saves_a_valid_cell(): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $res = $this->post( [
            [ 'player_id' => $this->player_id, 'category_id' => $this->cat_id, 'rating' => 7.5 ],
        ] );

        $this->assertSame( 200, $res->get_status() );
        $this->assertSame( 1, $this->ratingsRowCount() );
    }

    public function test_endpoint_skips_out_of_scale_and_off_roster_values(): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $res = $this->post( [
            // Above rating_max (default 10).
            [ 'player_id' => $this->player_id, 'category_id' => $this->cat_id, 'rating' => 99 ],
            // Not on this team's roster.
            [ 'player_id' => 999999, 'category_id' => $this->cat_id, 'rating' => 7 ],
            // Not a category of this activity.
            [ 'player_id' => $this->player_id, 'category_id' => 999999, 'rating' => 7 ],
        ] );

        $this->assertSame( 200, $res->get_status() );
        $this->assertSame( 0, $this->ratingsRowCount(), 'nothing invalid may reach the database' );
        $this->assertSame( 3, (int) $res->get_data()['data']['skipped'] );
    }
}
