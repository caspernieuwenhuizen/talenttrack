<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_UnitTestCase;
use TT\Infrastructure\Evaluations\PlayerEvaluationsReader;
use TT\Infrastructure\REST\PlayerEvaluationsRestController;

/**
 * #3478 — "My evaluations" shipped every evaluation a player ever had, each
 * with its full breakdown hidden in the page: 2.5 MB for one child.
 *
 * Decided: current season by default, earlier seasons on request, breakdown
 * fetched when a row is opened. What is pinned here is the cut, the
 * ownership check on the breakdown, and that the player-facing shape never
 * carries the staff-only notes.
 */
final class PlayerEvaluationsReaderTest extends WP_UnitTestCase {

    private int $player_id = 0;
    private int $other_player_id = 0;
    private int $in_season = 0;
    private int $last_season = 0;
    private int $main_cat = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $p = $wpdb->prefix;

        $wpdb->insert( "{$p}tt_seasons", [
            'club_id'    => 1,
            'name'       => '2090/2091',
            'start_date' => '2090-08-01',
            'end_date'   => '2091-06-30',
            'is_current' => 1,
        ] );

        $this->player_id       = $this->player( 'Reader' );
        $this->other_player_id = $this->player( 'Stranger' );

        $this->in_season   = $this->evaluation( $this->player_id, '2090-09-10', 'staff eyes only' );
        $this->last_season = $this->evaluation( $this->player_id, '2089-11-02', '' );

        $this->main_cat = $this->category( 'Technical ' . wp_rand(), null );
        $sub            = $this->category( 'First touch ' . wp_rand(), $this->main_cat );
        $wpdb->insert( "{$p}tt_eval_ratings", [
            'club_id'       => 1,
            'evaluation_id' => $this->in_season,
            'category_id'   => $sub,
            'rating'        => 7.0,
        ] );
    }

    private function player( string $last ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id' => 1, 'first_name' => 'Eval', 'last_name' => $last, 'status' => 'active', 'wp_user_id' => null,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function evaluation( int $player_id, string $date, string $notes ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_evaluations", [
            'club_id'   => 1,
            'player_id' => $player_id,
            'coach_id'  => 1,
            'eval_date' => $date,
            'notes'     => $notes,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function category( string $label, ?int $parent ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_eval_categories", [
            'club_id'       => 1,
            'category_key'  => 'zz_' . sanitize_key( $label ) . '_' . wp_rand( 10000, 99999 ),
            'label'         => $label,
            'parent_id'     => $parent,
            'display_order' => 900,
            'is_active'     => 1,
        ] );
        return (int) $wpdb->insert_id;
    }

    public function test_the_default_cut_is_the_current_season(): void {
        $ids = array_map( static fn( $r ) => (int) $r->id, ( new PlayerEvaluationsReader() )->listForPlayer( $this->player_id ) );

        $this->assertContains( $this->in_season, $ids );
        $this->assertNotContains( $this->last_season, $ids, 'Earlier seasons are loaded on request, not by default.' );
    }

    public function test_earlier_seasons_are_one_request_away_and_counted(): void {
        $reader = new PlayerEvaluationsReader();

        $this->assertSame( 1, $reader->countOutside( $this->player_id ) );
        $all = array_map( static fn( $r ) => (int) $r->id, $reader->listForPlayer( $this->player_id, PlayerEvaluationsReader::SCOPE_ALL ) );
        $this->assertContains( $this->last_season, $all, 'A player must still reach their whole history.' );
    }

    /** The player-facing shape must never carry the staff-only notes. */
    public function test_rows_do_not_carry_staff_notes(): void {
        foreach ( ( new PlayerEvaluationsReader() )->listForPlayer( $this->player_id, PlayerEvaluationsReader::SCOPE_ALL ) as $row ) {
            $this->assertObjectNotHasProperty( 'notes', $row );
        }
    }

    public function test_the_breakdown_is_read_on_demand(): void {
        $reader = new PlayerEvaluationsReader();

        $this->assertTrue( $reader->hasDetail( $this->in_season ) );
        $groups = $reader->detail( $this->player_id, $this->in_season );
        $this->assertIsArray( $groups );
        $this->assertCount( 1, $groups );
        $this->assertSame( 7.0, $groups[0]['subs'][0]['rating'] );
    }

    /** A caller authorised for one player cannot read another's breakdown by id. */
    public function test_the_breakdown_is_refused_for_a_different_player(): void {
        $this->assertNull( ( new PlayerEvaluationsReader() )->detail( $this->other_player_id, $this->in_season ) );
    }

    public function test_a_stranger_is_refused_by_the_rest_route(): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

        $request = new WP_REST_Request( 'GET', '/talenttrack/v1/players/' . $this->player_id . '/evaluations' );
        $request->set_url_params( [ 'id' => $this->player_id ] );

        $this->assertFalse( PlayerEvaluationsRestController::can_read( $request ) );
    }
}
