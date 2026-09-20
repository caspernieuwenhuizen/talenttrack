<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Domain\Vocabularies\Enums\MatchExecutionState;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\MatchExecution\Repositories\MatchExecutionRepository;
use TT\Modules\MatchExecution\Services\PitchLayoutService;
use TT\Modules\MatchPrep\Repositories\MatchPrepRepository;

/**
 * #3554 — the live pitch shows the line-up as it stands, not as it was at
 * kickoff. Each substitution puts the player coming on in the slot of the
 * player going off; an undone substitution no longer counts; the REST
 * `pitch-lineup` answer carries that line-up plus everyone on the pitch, so
 * the screen can redraw after a sub without a reload.
 */
final class MatchExecutionLiveLineupTest extends WP_UnitTestCase {

    private const ACTIVITY_ID = 8354;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->ensureCapabilities();

        global $wpdb;
        $wpdb->hide_errors();

        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        parent::tear_down();
    }

    private static function sub( int $off, int $on ): object {
        return (object) [ 'player_off_id' => $off, 'player_on_id' => $on ];
    }

    public function test_a_sub_takes_the_outgoing_players_slot(): void {
        $slots = PitchLayoutService::applySubstitutions(
            [ 1 => 10, 2 => 11, 3 => 12 ],
            [ self::sub( 11, 20 ) ]
        );
        $this->assertSame( [ 1 => 10, 2 => 20, 3 => 12 ], $slots );
    }

    public function test_subs_apply_in_order_so_a_sub_can_come_off_again(): void {
        $slots = PitchLayoutService::applySubstitutions(
            [ 1 => 10, 2 => 11 ],
            [ self::sub( 11, 20 ), self::sub( 20, 21 ) ]
        );
        $this->assertSame( [ 1 => 10, 2 => 21 ], $slots );
    }

    public function test_a_sub_for_a_player_without_a_slot_changes_nothing(): void {
        $slots = PitchLayoutService::applySubstitutions( [ 1 => 10 ], [ self::sub( 99, 20 ) ] );
        $this->assertSame( [ 1 => 10 ], $slots );
    }

    // ---- #3849: the pitch is drawn for the half the match has reached ----

    private static function subIn( int $half, int $off, int $on ): object {
        return (object) [ 'half' => $half, 'player_off_id' => $off, 'player_on_id' => $on ];
    }

    public function test_the_second_half_line_up_takes_the_pitch(): void {
        $slots = PitchLayoutService::pitchAtHalf(
            [ 1 => 10, 2 => 11, 3 => 12 ],
            [ 1 => 10, 2 => 20, 3 => 12 ],
            [ self::subIn( 2, 12, 21 ) ],
            2
        );
        $this->assertSame( [ 1 => 10, 2 => 20, 3 => 21 ], $slots );
    }

    /** The half-2 line-up already accounts for the first half's swaps. */
    public function test_a_first_half_sub_is_not_applied_on_top_of_the_half_two_line_up(): void {
        $slots = PitchLayoutService::pitchAtHalf(
            [ 1 => 10, 2 => 11 ],
            [ 1 => 10, 2 => 20 ],
            [ self::subIn( 1, 11, 30 ) ],
            2
        );
        $this->assertSame( [ 1 => 10, 2 => 20 ], $slots );
    }

    public function test_without_a_half_two_line_up_the_first_one_plays_on(): void {
        $slots = PitchLayoutService::pitchAtHalf(
            [ 1 => 10, 2 => 11 ],
            [],
            [ self::subIn( 1, 11, 20 ), self::subIn( 2, 20, 21 ) ],
            2
        );
        $this->assertSame( [ 1 => 10, 2 => 21 ], $slots );
    }

    public function test_the_first_half_never_sees_a_second_half_sub(): void {
        $slots = PitchLayoutService::pitchAtHalf(
            [ 1 => 10, 2 => 11 ],
            [ 1 => 10, 2 => 20 ],
            [ self::subIn( 2, 20, 21 ) ],
            1
        );
        $this->assertSame( [ 1 => 10, 2 => 11 ], $slots );
    }

    public function test_pitch_lineup_returns_the_current_lineup_and_ignores_an_undone_sub(): void {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_activities', [
            'club_id'           => 1,
            'id'                => self::ACTIVITY_ID,
            'team_id'           => 1,
            'title'             => 'Lineup match',
            'session_date'      => current_time( 'Y-m-d' ),
            'activity_type_key' => 'match',
        ] );
        $prep_repo = new MatchPrepRepository();
        $prep_id   = $prep_repo->ensureForActivity( self::ACTIVITY_ID, 35 );
        $prep_repo->replaceLineupForHalf( $prep_id, 1, [ 1 => 1, 2 => 2, 3 => 3 ] );

        $repo    = new MatchExecutionRepository();
        $exec_id = $repo->ensureForActivity( self::ACTIVITY_ID, $prep_id );
        $repo->update( $exec_id, [ 'state' => MatchExecutionState::FIRST_HALF ] );
        $repo->logSubstitution( $exec_id, 'aaaaaaaa-0000-4000-8000-000000000001', 1, 10, 2, 7 );
        $repo->logSubstitution( $exec_id, 'aaaaaaaa-0000-4000-8000-000000000002', 1, 12, 3, 8 );
        $repo->reverseSubstitution( 'aaaaaaaa-0000-4000-8000-000000000002' );

        $res = rest_do_request( new WP_REST_Request( 'GET', '/talenttrack/v1/match-execution/' . self::ACTIVITY_ID . '/pitch-lineup' ) );
        $this->assertSame( 200, $res->get_status() );
        $data = $res->get_data()['data'] ?? [];

        $by_player = [];
        foreach ( (array) ( $data['slots'] ?? [] ) as $slot ) {
            if ( (int) $slot['player_id'] > 0 ) $by_player[ (int) $slot['player_id'] ] = (int) $slot['slot'];
        }
        $this->assertArrayHasKey( 7, $by_player, 'the player who came on is on the pitch' );
        $this->assertArrayNotHasKey( 2, $by_player, 'the player who came off is not' );
        $this->assertArrayHasKey( 3, $by_player, 'an undone sub does not count' );
        $this->assertArrayNotHasKey( 8, $by_player );

        $on_pitch = array_map( 'intval', (array) ( $data['on_pitch'] ?? [] ) );
        sort( $on_pitch );
        $this->assertSame( [ 1, 3, 7 ], $on_pitch );
    }
}
