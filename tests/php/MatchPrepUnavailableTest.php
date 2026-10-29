<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Activities\Services\PlayerAvailability;

/**
 * #4245 — match prep says who cannot play, the way planned attendance does.
 *
 * The prep never read the injury record, so an injured player started as
 * Present and could be picked with no warning. The answer is the neutral
 * `unavailable` set from `PlayerAvailability`, beside the coach's own
 * `availability`: never written into it, because a stored status reaches the
 * printout and the share link, and naming the cause would be a medical fact.
 */
final class MatchPrepUnavailableTest extends WP_UnitTestCase {

    private const MATCH_DATE = '2026-09-27';

    private int $club     = 0;
    private int $team     = 0;
    private int $activity = 0;

    /** @var array<string, int> */
    private array $players = [];

    public function set_up(): void {
        parent::set_up();
        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        global $wpdb;
        $p          = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();

        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $this->club, 'name' => 'JO7-1', 'football_form' => '6v6' ] );
        $this->team = (int) $wpdb->insert_id;

        $wpdb->insert( "{$p}tt_activities", [
            'club_id'             => $this->club,
            'team_id'             => $this->team,
            'title'               => 'Hedel - Den Bosch',
            'session_date'        => self::MATCH_DATE,
            'activity_type_key'   => 'match',
            'activity_status_key' => 'planned',
            'plan_state'          => 'scheduled',
        ] );
        $this->activity = (int) $wpdb->insert_id;

        foreach ( [ 'fit', 'injured', 'picked_anyway' ] as $key ) {
            $this->player( $key );
        }
        $this->injury( 'injured' );
        $this->injury( 'picked_anyway' );

        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_planned_attendance_and_the_prep_agree_for_an_injured_player(): void {
        $this->savePrep();

        $prep    = $this->prep();
        $planned = $this->plannedFlags();

        foreach ( $this->players as $key => $pid ) {
            $in_prep = isset( ( (array) $prep['unavailable'] )[ $pid ] );
            $this->assertSame(
                $planned[ $pid ] === PlayerAvailability::UNAVAILABLE,
                $in_prep,
                $key . ': planned attendance and GET match-prep must give one answer'
            );
        }
        $this->assertArrayHasKey( $this->players['injured'], (array) $prep['unavailable'] );
        $this->assertArrayNotHasKey( $this->players['fit'], (array) $prep['unavailable'] );
    }

    /** The coach's saved choice stays theirs; the flag reports, it does not decide. */
    public function test_a_saved_choice_is_never_overwritten(): void {
        $this->savePrep();

        $prep = $this->prep();
        $pid  = $this->players['picked_anyway'];

        $this->assertTrue( ( (array) $prep['unavailable'] )[ $pid ] ?? false );
        $this->assertSame( 'Present', $prep['availability'][ $pid ]['status'] );

        global $wpdb;
        $stored = $wpdb->get_var( $wpdb->prepare(
            "SELECT a.status FROM {$wpdb->prefix}tt_match_prep_availability a
               JOIN {$wpdb->prefix}tt_match_prep mp ON mp.id = a.match_prep_id
              WHERE mp.activity_id = %d AND a.player_id = %d",
            $this->activity,
            $pid
        ) );
        $this->assertSame( 'Present', $stored, 'reading the prep writes nothing into it' );
    }

    public function test_the_prep_carries_the_state_and_no_medical_detail(): void {
        $this->savePrep();

        $prep = $this->prep();
        foreach ( (array) $prep['unavailable'] as $flag ) {
            $this->assertTrue( $flag );
        }
        $json = (string) wp_json_encode( $prep );
        foreach ( [ 'ankle', 'Injured', 'injury', 'expected_return' ] as $leak ) {
            $this->assertStringNotContainsString( $leak, $json );
        }
    }

    // ---- fixtures --------------------------------------------------

    private function player( string $key ): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id'    => $this->club,
            'team_id'    => $this->team,
            'first_name' => 'Speler',
            'last_name'  => $key,
            'status'     => 'active',
        ] );
        $this->players[ $key ] = (int) $wpdb->insert_id;

        $wpdb->insert( "{$wpdb->prefix}tt_attendance", [
            'club_id'     => $this->club,
            'activity_id' => $this->activity,
            'player_id'   => $this->players[ $key ],
            'is_guest'    => 0,
            'status'      => 'Present',
            'record_type' => 'expected',
        ] );
    }

    private function injury( string $key ): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_player_injuries", [
            'club_id'         => $this->club,
            'player_id'       => $this->players[ $key ],
            'started_on'      => '2026-09-20',
            'expected_return' => null,
            'actual_return'   => null,
            'notes'           => 'ankle',
        ] );
    }

    /** Everyone Present, as a coach who has not noticed the injuries would save it. */
    private function savePrep(): void {
        $availability = [];
        foreach ( $this->players as $pid ) {
            $availability[ $pid ] = [ 'status' => 'Present', 'reason' => '' ];
        }
        $request = new WP_REST_Request( 'PUT', '/talenttrack/v1/match-prep/' . $this->activity );
        $request->set_header( 'content-type', 'application/json' );
        $request->set_body( (string) wp_json_encode( [ 'availability' => $availability ] ) );
        $this->assertSame( 200, rest_do_request( $request )->get_status() );
    }

    /** @return array<string, mixed> */
    private function prep(): array {
        $response = rest_do_request( new WP_REST_Request( 'GET', '/talenttrack/v1/match-prep/' . $this->activity ) );
        $this->assertSame( 200, $response->get_status() );
        $data = json_decode( (string) wp_json_encode( $response->get_data() ), true );
        return (array) ( $data['data'] ?? [] );
    }

    /** @return array<int, string> player id => availability flag */
    private function plannedFlags(): array {
        $response = rest_do_request( new WP_REST_Request( 'GET', '/talenttrack/v1/activities/' . $this->activity . '/planned-attendance' ) );
        $this->assertSame( 200, $response->get_status() );
        $out = [];
        foreach ( (array) ( $response->get_data()['data']['roster'] ?? [] ) as $row ) {
            $row = (array) $row;
            $out[ (int) $row['player_id'] ] = (string) $row['availability'];
        }
        return $out;
    }
}
