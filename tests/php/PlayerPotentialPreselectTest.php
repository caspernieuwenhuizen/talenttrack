<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Players\Repositories\PlayerPotentialRepository;

/**
 * #2876 — the Set potential popover must open on the player's current band.
 *
 * It read `$player->potential_band`, a column `tt_players` does not have.
 * Reading a missing property off a wpdb row is silent, so the ternary took
 * its else branch for every player on every install and the control opened
 * blank forever. Nothing failed, which is why it survived: the only way to
 * notice was to know the band was set and see the popover disagree.
 *
 * That silence is the reason these tests exist. They assert against the
 * repository that actually owns the value, so a future edit that goes back
 * to reading a column off the player row fails here instead of shipping.
 */
final class PlayerPotentialPreselectTest extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->ensureCapabilities();

        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        parent::tear_down();
    }

    private function makePlayer(): int {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_players', [
            'club_id'    => (int) CurrentClub::id(),
            'first_name' => 'Potential',
            'last_name'  => 'Player',
            'status'     => 'active',
        ] );
        return (int) $wpdb->insert_id;
    }

    /** The value the popover pre-selects comes from here, not from tt_players. */
    public function test_latest_band_is_readable_for_preselection(): void {
        $player_id = $this->makePlayer();
        $repo      = new PlayerPotentialRepository();

        $this->assertNull(
            $repo->latestFor( $player_id ),
            'a player who never had a band set has nothing to pre-select'
        );

        $repo->create( [ 'player_id' => $player_id, 'potential_band' => 'first_team' ] );
        $latest = $repo->latestFor( $player_id );

        $this->assertNotNull( $latest );
        $this->assertSame( 'first_team', (string) $latest->potential_band );
        $this->assertNotEmpty( $latest->set_at, 'the popover shows when the band was set' );
    }

    /** The newest row wins — a revision, not the first judgement, is current. */
    public function test_the_most_recent_band_is_the_current_one(): void {
        $player_id = $this->makePlayer();
        $repo      = new PlayerPotentialRepository();

        $repo->create( [ 'player_id' => $player_id, 'potential_band' => 'recreational' ] );
        $repo->create( [ 'player_id' => $player_id, 'potential_band' => 'first_team' ] );

        $this->assertSame( 'first_team', (string) $repo->latestFor( $player_id )->potential_band );
    }

    /**
     * `tt_players` has no `potential_band` column. Pinning this stops the
     * original bug being reintroduced by someone "simplifying" the read back
     * onto the player row — which would be silent all over again.
     */
    public function test_players_table_has_no_potential_band_column(): void {
        global $wpdb;
        $cols = $wpdb->get_col( "SHOW COLUMNS FROM {$wpdb->prefix}tt_players" );
        $this->assertNotContains(
            'potential_band',
            (array) $cols,
            'potential is history in tt_player_potential, never a column on the player'
        );
    }

    /**
     * A bare re-statement of the standing band records nothing; the same
     * band WITH notes is a real act and still appends.
     */
    public function test_resubmitting_the_same_band_does_not_append_history(): void {
        $uid = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $uid );

        $player_id = $this->makePlayer();
        $repo      = new PlayerPotentialRepository();
        $repo->create( [ 'player_id' => $player_id, 'potential_band' => 'first_team' ] );

        $this->assertCount( 1, $repo->historyFor( $player_id ) );

        $same = new WP_REST_Request( 'POST', '/talenttrack/v1/players/' . $player_id . '/potential' );
        $same->set_header( 'Content-Type', 'application/json' );
        $same->set_body( wp_json_encode( [ 'potential_band' => 'first_team' ] ) );
        $res = rest_do_request( $same );

        $this->assertSame( 200, $res->get_status(), 're-stating the current band is not an error' );
        $this->assertTrue( (bool) ( $res->get_data()['data']['unchanged'] ?? false ) );
        $this->assertCount( 1, $repo->historyFor( $player_id ), 'no duplicate row' );

        $with_notes = new WP_REST_Request( 'POST', '/talenttrack/v1/players/' . $player_id . '/potential' );
        $with_notes->set_header( 'Content-Type', 'application/json' );
        $with_notes->set_body( wp_json_encode( [
            'potential_band' => 'first_team',
            'notes'          => 'Still first team, but the last six weeks have been flat.',
        ] ) );
        $res2 = rest_do_request( $with_notes );

        $this->assertSame( 200, $res2->get_status() );
        $this->assertFalse( (bool) ( $res2->get_data()['data']['unchanged'] ?? true ) );
        $this->assertCount(
            2,
            $repo->historyFor( $player_id ),
            're-affirming a band with notes is a real entry'
        );
    }
}
