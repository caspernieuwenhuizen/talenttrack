<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Activities\Frontend\FrontendRatingsGridView;
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

        // Pin the rating scale. Every assertion here is defined relative to
        // it, and ConfigService memoises on a static singleton that outlives
        // the per-test transaction — so an ambient value, from the install
        // seed or from another test, silently changes what these tests mean.
        QueryHelpers::set_config( 'rating_min', '5' );
        QueryHelpers::set_config( 'rating_max', '10' );
        QueryHelpers::set_config( 'rating_step', '0.5' );

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

    // ---- category tiers (#2432) ------------------------------------------

    /** Insert a category, optionally under a parent, optionally deactivated. */
    private function makeCategory( string $label, int $order, ?int $parent_id = null, bool $active = true ): int {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_eval_categories', [
            'category_key'  => str_replace( ' ', '_', strtolower( $label ) ) . '_' . $this->activity_id . '_' . $order,
            'label'         => $label,
            'display_order' => $order,
            'is_active'     => $active ? 1 : 0,
            'parent_id'     => $parent_id,
        ] );
        return (int) $wpdb->insert_id;
    }

    /**
     * A main keeps its own rateable column AND heads the group holding its
     * subs — the same Basic/Detailed split the evaluation form offers.
     */
    public function test_main_category_keeps_its_own_column_and_heads_its_subs(): void {
        $main = $this->makeCategory( 'Technical', 10 );
        $sub  = $this->makeCategory( 'Short pass', 11, $main );

        $groups = RatingsGridQuery::forActivity( $this->activity_id )['groups'];
        $byId   = [];
        foreach ( $groups as $g ) $byId[ $g['id'] ] = $g;

        $this->assertArrayHasKey( $main, $byId );
        $this->assertSame( $main, $byId[ $main ]['own']['id'], 'the main is rateable in its own right' );
        $this->assertSame( [ $sub ], array_column( $byId[ $main ]['subs'], 'id' ) );
    }

    /**
     * The flat display_order sort does not keep a sub next to its parent —
     * interleaved columns are exactly what made the header unreadable.
     */
    public function test_subs_are_ordered_adjacent_to_their_own_parent(): void {
        $tech = $this->makeCategory( 'Technical', 10 );
        $ment = $this->makeCategory( 'Mentality', 11 );
        // Deliberately ordered so a flat sort would interleave them.
        $pass = $this->makeCategory( 'Short pass', 12, $tech );
        $focus = $this->makeCategory( 'Focus', 13, $ment );

        $order = array_column( RatingsGridQuery::forActivity( $this->activity_id )['categories'], 'id' );
        $this->assertSame(
            [ $tech, $pass, $ment, $focus ],
            array_values( array_filter( $order, static fn( $id ) => in_array( $id, [ $tech, $ment, $pass, $focus ], true ) ) ),
            'each main must be followed by its own children, not by another main'
        );
    }

    /** A main with no subs is one plain column — no tier, nothing to expand. */
    public function test_main_without_subs_has_no_sub_tier(): void {
        $main   = $this->makeCategory( 'Physical', 10 );
        $groups = RatingsGridQuery::forActivity( $this->activity_id )['groups'];

        foreach ( $groups as $g ) {
            if ( $g['id'] !== $main ) continue;
            $this->assertSame( [], $g['subs'] );
            $this->assertFalse( $g['expanded'] );
            return;
        }
        $this->fail( 'the main category should still have produced a group' );
    }

    /**
     * Subs collapse by default, but a coach reopening a rating they already
     * entered in detail must see it — not a collapsed grid hiding their own
     * scores.
     */
    public function test_group_auto_expands_when_a_sub_already_holds_a_score(): void {
        $main = $this->makeCategory( 'Technical', 10 );
        $sub  = $this->makeCategory( 'Short pass', 11, $main );

        $collapsed = RatingsGridQuery::forActivity( $this->activity_id )['groups'];
        foreach ( $collapsed as $g ) {
            if ( $g['id'] === $main ) $this->assertFalse( $g['expanded'], 'nothing rated yet means collapsed' );
        }

        EvaluationInserter::upsertForActivity( $this->player_id, $this->activity_id, [ $sub => 8.0 ] );

        $expanded = RatingsGridQuery::forActivity( $this->activity_id )['groups'];
        foreach ( $expanded as $g ) {
            if ( $g['id'] === $main ) {
                $this->assertTrue( $g['expanded'], 'a rated sub must not stay hidden' );
                return;
            }
        }
        $this->fail( 'the main category should still have produced a group' );
    }

    // ---- rendered header geometry (#2474) --------------------------------

    /** The grid as the coach receives it. */
    private function renderGrid(): string {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        $_GET['activity_id'] = $this->activity_id;

        ob_start();
        FrontendRatingsGridView::render( get_current_user_id(), true );
        $html = (string) ob_get_clean();

        unset( $_GET['activity_id'] );
        return $html;
    }

    /**
     * Columns claimed by the main header row, and columns supplied by each
     * body row. A collapsed cell carries `is-hidden`, which is `display:none`
     * — it leaves the table, so it counts for neither.
     *
     * @return array{head:int, body:list<int>}
     */
    private function columnGeometry( string $html ): array {
        $doc = new \DOMDocument();
        libxml_use_internal_errors( true );
        $doc->loadHTML( '<?xml encoding="utf-8" ?><body>' . $html . '</body>' );
        libxml_clear_errors();
        $xp = new \DOMXPath( $doc );

        $visible = static fn( \DOMElement $el ): bool =>
            ! str_contains( ' ' . $el->getAttribute( 'class' ) . ' ', ' is-hidden ' );

        $head = 0;
        foreach ( $xp->query( "//tr[contains(@class,'tt-rgrid-head-main')]/th" ) as $th ) {
            if ( ! $visible( $th ) ) continue;
            $head += max( 1, (int) $th->getAttribute( 'colspan' ) );
        }

        $body = [];
        foreach ( $xp->query( '//tbody/tr' ) as $tr ) {
            $n = 0;
            foreach ( $xp->query( 'th|td', $tr ) as $cell ) {
                if ( $visible( $cell ) ) $n++;
            }
            $body[] = $n;
        }

        return [ 'head' => $head, 'body' => $body ];
    }

    /**
     * The bug: a main header spanned its own column PLUS every sub, while a
     * collapsed sub is `display:none` and supplies no column at all. The
     * surplus columns are phantoms — the real cells pack to the left and
     * every later main's header is painted over emptiness (#2474).
     */
    public function test_collapsed_group_header_spans_only_the_visible_columns(): void {
        $tech = $this->makeCategory( 'Technical', 10 );
        $this->makeCategory( 'Short pass', 11, $tech );
        $this->makeCategory( 'First touch', 12, $tech );
        $ment = $this->makeCategory( 'Mentality', 20 );
        $this->makeCategory( 'Focus', 21, $ment );

        $geo = $this->columnGeometry( $this->renderGrid() );

        $this->assertNotSame( [], $geo['body'], 'the roster should have produced body rows' );
        foreach ( $geo['body'] as $n ) {
            $this->assertSame(
                $geo['head'],
                $n,
                'the header must claim exactly the columns a row supplies, or the groups slide off their own block'
            );
        }
    }

    /** The same has to hold once a group auto-expands around a stored score. */
    public function test_expanded_group_header_spans_only_the_visible_columns(): void {
        $tech = $this->makeCategory( 'Technical', 10 );
        $pass = $this->makeCategory( 'Short pass', 11, $tech );
        $this->makeCategory( 'First touch', 12, $tech );
        $ment = $this->makeCategory( 'Mentality', 20 );
        $this->makeCategory( 'Focus', 21, $ment );

        // Auto-expands Technical only; Mentality stays collapsed, so the two
        // states have to line up in one and the same header row.
        EvaluationInserter::upsertForActivity( $this->player_id, $this->activity_id, [ $pass => 8.0 ] );

        $geo = $this->columnGeometry( $this->renderGrid() );

        foreach ( $geo['body'] as $n ) {
            $this->assertSame( $geo['head'], $n, 'expanding one group must not unbalance the row' );
        }
    }

    /**
     * A main that is not rateable in its own right collapses to zero columns,
     * and a header cannot span zero. It gets a placeholder column instead, so
     * the label and the expand toggle still have somewhere to live.
     */
    public function test_collapsed_group_without_its_own_column_keeps_a_placeholder(): void {
        // Deactivating the main leaves its subs declared without it — the
        // same shape as an eval type that declares only sub-categories.
        $tech = $this->makeCategory( 'Technical', 10, null, false );
        $this->makeCategory( 'Short pass', 11, $tech );

        $html = $this->renderGrid();
        $geo  = $this->columnGeometry( $html );

        $this->assertStringContainsString(
            'data-tt-rgrid-slot-of="' . $tech . '"',
            $html,
            'the collapsed group needs a column to sit over'
        );
        foreach ( $geo['body'] as $n ) {
            $this->assertSame( $geo['head'], $n, 'the placeholder is what keeps the row balanced' );
        }
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

        // Assert the VALUE, not just that a row appeared. Counting rows is
        // what let the endpoint round a typed score onto another one without
        // any test noticing (#2431).
        $data = RatingsGridQuery::forActivity( $this->activity_id );
        $this->assertSame( 7.5, $data['values'][ $this->player_id ][ $this->cat_id ] );
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

        // #2431 — an out-of-scale score is a REFUSAL, not a skip. Only the
        // two unaddressable cells count as skipped; folding the refusal in
        // with them is what let the client report a dropped score as saved.
        $data = $res->get_data()['data'];
        $this->assertSame( 2, (int) $data['skipped'] );
        $this->assertSame( 1, (int) $data['rejected_count'] );
        $this->assertSame( 'out_of_range', $data['rejected'][0]['reason'] );
        $this->assertSame( $this->player_id, (int) $data['rejected'][0]['player_id'] );
        $this->assertSame( $this->cat_id, (int) $data['rejected'][0]['category_id'] );
    }

    /**
     * #2431 — a score that misses the scale's step used to be rounded onto
     * the nearest one and stored, so 7.3 became 7.5 without anybody saying
     * so. Silently changing a rating is the same class of bug as silently
     * dropping one: the coach's own judgement is the data.
     */
    public function test_off_step_rating_is_refused_not_silently_rounded(): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $res = $this->post( [
            [ 'player_id' => $this->player_id, 'category_id' => $this->cat_id, 'rating' => 7.3 ],
        ] );

        $this->assertSame( 200, $res->get_status() );
        $this->assertSame( 0, $this->ratingsRowCount(), '7.3 on a half-point scale must not become 7.5' );

        $data = $res->get_data()['data'];
        $this->assertSame( 0, (int) $data['saved'] );
        $this->assertSame( 1, (int) $data['rejected_count'] );
        $this->assertSame( 'off_step', $data['rejected'][0]['reason'] );
    }

    /**
     * Float noise must stay forgiving — the snap exists so a value that is
     * a hair off a step through binary representation still lands.
     */
    public function test_on_step_rating_still_saves(): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $res = $this->post( [
            [ 'player_id' => $this->player_id, 'category_id' => $this->cat_id, 'rating' => 7.5000001 ],
        ] );

        $this->assertSame( 200, $res->get_status() );
        $this->assertSame( 0, (int) $res->get_data()['data']['rejected_count'] );

        $data = RatingsGridQuery::forActivity( $this->activity_id );
        $this->assertSame( 7.5, $data['values'][ $this->player_id ][ $this->cat_id ] );
    }

    /**
     * One bad cell must not cost the coach the rest of the batch — the
     * valid scores still commit and the refusal is reported alongside them.
     */
    public function test_valid_cells_still_save_when_another_is_refused(): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_players', [
            'first_name' => 'Tess',
            'last_name'  => 'Visser',
            'team_id'    => $this->team_id,
            'club_id'    => 1,
            'status'     => 'active',
        ] );
        $other_player = (int) $wpdb->insert_id;

        $res = $this->post( [
            [ 'player_id' => $this->player_id, 'category_id' => $this->cat_id, 'rating' => 12 ],
            [ 'player_id' => $other_player,    'category_id' => $this->cat_id, 'rating' => 8 ],
        ] );

        $this->assertSame( 200, $res->get_status() );
        $data = $res->get_data()['data'];
        $this->assertSame( 1, (int) $data['saved'] );
        $this->assertSame( 1, (int) $data['rejected_count'] );

        $grid = RatingsGridQuery::forActivity( $this->activity_id );
        $this->assertSame( 8.0, $grid['values'][ $other_player ][ $this->cat_id ] );
        $this->assertArrayNotHasKey( $this->player_id, $grid['values'] );
    }
}
