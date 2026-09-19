<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\MatchPrep\Services\MatchLengthResolver;

/**
 * #3682 — a match stores its length twice, and the two used to be
 * strangers.
 *
 * `tt_activities.match_length_minutes` is what the coach sets on the
 * fixture (#1726); `tt_match_prep.half_length_minutes` is what match
 * execution, the minutes queries and the match analysis all read. A coach
 * who set an U11 match to 60 minutes still got a prep planned at 2 x 35,
 * so the player's recorded minutes came out 10 too high per match.
 *
 * The rule these cases pin down: a **new** prep is seeded from the
 * activity's length, and from then on the two never sync — the prep says
 * so instead, through `half_length_mismatch`.
 */
final class MatchPrepHalfLengthTest extends WP_UnitTestCase {

    private int $team = 0;
    private int $mapped_team = 0;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        MatrixRepository::clearCache();
        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        global $wpdb;
        $p = $wpdb->prefix;

        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => 1, 'name' => 'O13-1', 'age_group' => 'O13' ] );
        $this->team = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => 1, 'name' => 'O11-1', 'age_group' => 'O11' ] );
        $this->mapped_team = (int) $wpdb->insert_id;

        // The per-age-category map covers O11 only, so the O13 team falls
        // through to the global 35 and both branches are exercised.
        // Written on every set_up: the config service caches per process,
        // while the row itself rolls back with the test's transaction.
        QueryHelpers::set_config(
            MatchLengthResolver::CONFIG_KEY,
            (string) wp_json_encode( [ 'O11' => 25 ] )
        );

        $coach = self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $wpdb->insert( "{$p}tt_people", [
            'club_id' => 1, 'first_name' => 'Prep', 'last_name' => 'Coach',
            'role_type' => 'head_coach', 'wp_user_id' => $coach, 'status' => 'active',
        ] );
        $person = (int) $wpdb->insert_id;
        foreach ( [ $this->team, $this->mapped_team ] as $scope ) {
            $wpdb->insert( "{$p}tt_user_role_scopes", [
                'person_id' => $person, 'role_id' => 1, 'scope_type' => 'team', 'scope_id' => $scope,
            ] );
        }
        wp_set_current_user( $coach );
    }

    public function tear_down(): void {
        // The config service caches per process while the row rolls back
        // with the transaction, so the map has to be unset explicitly or it
        // follows this class into the next one.
        QueryHelpers::set_config( MatchLengthResolver::CONFIG_KEY, '' );
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    /**
     * The bug as the coach hit it: the fixture says 60, so the prep starts
     * at 2 x 30 rather than the 2 x 35 fallback.
     */
    public function test_a_new_prep_starts_from_the_activity_match_length(): void {
        $match = $this->match( $this->team, 60 );

        [ $put, $status ] = $this->send( 'PUT', $match );
        $this->assertSame( 200, $status );
        $this->assertSame( 30, $put['data']['half_length_minutes'] );
        $this->assertSame( 60, $put['data']['activity_match_length_minutes'] );
        $this->assertFalse( $put['data']['half_length_mismatch'] );

        [ $got ] = $this->send( 'GET', $match );
        $this->assertSame( 30, $got['data']['half_length_minutes'], 'the read agrees with the write' );
    }

    /**
     * An odd length rounds the half up, so nothing is quietly lost. 25
     * minutes is a real one: the youngest age groups play it.
     */
    public function test_an_odd_match_length_rounds_the_half_up(): void {
        $match = $this->match( $this->team, 25 );

        [ $put ] = $this->send( 'PUT', $match );
        $this->assertSame( 13, $put['data']['half_length_minutes'] );
        $this->assertFalse( $put['data']['half_length_mismatch'], '13 is the half of 25 this system means' );
    }

    /**
     * No length on the fixture: the age-category map still decides, then
     * the global fallback. The activity field is `null` rather than 0 —
     * "nobody said" is not "zero minutes".
     */
    public function test_without_a_match_length_the_age_group_map_then_the_fallback_decides(): void {
        $mapped = $this->match( $this->mapped_team, null );
        [ $put ] = $this->send( 'PUT', $mapped );
        $this->assertSame( 25, $put['data']['half_length_minutes'], 'O11 is configured at 25' );
        $this->assertNull( $put['data']['activity_match_length_minutes'] );
        $this->assertFalse( $put['data']['half_length_mismatch'] );

        $unmapped = $this->match( $this->team, null );
        [ $put ] = $this->send( 'PUT', $unmapped );
        $this->assertSame(
            MatchLengthResolver::FALLBACK_HALF_MINUTES,
            $put['data']['half_length_minutes'],
            'O13 is not in the map, so the global fallback stands'
        );
        $this->assertNull( $put['data']['activity_match_length_minutes'] );
        $this->assertFalse( $put['data']['half_length_mismatch'] );
    }

    /**
     * The deliberate half of the decision: once a prep exists, changing
     * the fixture's length does not rewrite it. Every minutes surface
     * reads the prep, so a silent rewrite would move a player's recorded
     * minutes behind the coach's back. The disagreement is reported.
     */
    public function test_a_later_activity_length_does_not_rewrite_the_prep_but_is_flagged(): void {
        $match = $this->match( $this->team, null );

        [ $put ] = $this->send( 'PUT', $match );
        $this->assertSame( 35, $put['data']['half_length_minutes'] );

        $this->setMatchLength( $match, 60 );

        [ $got, $status ] = $this->send( 'GET', $match );
        $this->assertSame( 200, $status );
        $this->assertSame( 35, $got['data']['half_length_minutes'], 'the prep is left alone' );
        $this->assertSame( 60, $got['data']['activity_match_length_minutes'] );
        $this->assertTrue( $got['data']['half_length_mismatch'] );
    }

    /** Answering the flag clears it, in the same response. */
    public function test_matching_the_activity_clears_the_flag(): void {
        $match = $this->match( $this->team, null );
        $this->send( 'PUT', $match );
        $this->setMatchLength( $match, 60 );

        [ $put ] = $this->send( 'PUT', $match, [ 'half_length_minutes' => 30 ] );
        $this->assertSame( 30, $put['data']['half_length_minutes'] );
        $this->assertFalse( $put['data']['half_length_mismatch'] );
    }

    /**
     * Clearing the box lands on the fixture's length rather than the bare
     * 35 — the same resolution a fresh prep gets.
     */
    public function test_a_blank_half_length_resolves_from_the_activity(): void {
        $match = $this->match( $this->team, 60 );
        $this->send( 'PUT', $match, [ 'half_length_minutes' => 70 ] );

        [ $put ] = $this->send( 'PUT', $match, [ 'half_length_minutes' => 0 ] );
        $this->assertSame( 30, $put['data']['half_length_minutes'] );
        $this->assertFalse( $put['data']['half_length_mismatch'] );
    }

    /** An activity in another club never leaks its length into this one. */
    public function test_the_activity_length_read_is_club_scoped(): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_activities", [
            'club_id'              => 2,
            'team_id'              => $this->team,
            'title'                => 'Ander club',
            'session_date'         => '2026-09-26',
            'activity_type_key'    => 'match',
            'match_length_minutes' => 60,
        ] );
        $other_club_activity = (int) $wpdb->insert_id;

        $this->assertSame( 0, ( new MatchLengthResolver() )->activityMatchLength( $other_club_activity ) );
    }

    private function match( int $team_id, ?int $match_length ): int {
        global $wpdb;
        $row = [
            'club_id'           => 1,
            'team_id'           => $team_id,
            'title'             => 'Hedel - Den Bosch',
            'session_date'      => '2026-09-26',
            'activity_type_key' => 'match',
        ];
        if ( $match_length !== null ) {
            $row['match_length_minutes'] = $match_length;
        }
        $wpdb->insert( "{$wpdb->prefix}tt_activities", $row );
        return (int) $wpdb->insert_id;
    }

    private function setMatchLength( int $activity_id, int $minutes ): void {
        global $wpdb;
        $wpdb->update(
            "{$wpdb->prefix}tt_activities",
            [ 'match_length_minutes' => $minutes ],
            [ 'id' => $activity_id ]
        );
    }

    /**
     * @param array<string,mixed> $body
     * @return array{0:array<string,mixed>,1:int}
     */
    private function send( string $method, int $activity_id, array $body = [] ): array {
        $request = new WP_REST_Request( $method, '/talenttrack/v1/match-prep/' . $activity_id );
        if ( $body ) {
            $request->set_header( 'content-type', 'application/json' );
            $request->set_body( (string) wp_json_encode( $body ) );
        }
        $response = rest_do_request( $request );
        $data     = json_decode( (string) wp_json_encode( $response->get_data() ), true );
        return [ is_array( $data ) ? $data : [], (int) $response->get_status() ];
    }
}
