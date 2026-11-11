<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Core\FeatureRegistry;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Analytics\Domain\FactScope;
use TT\Modules\Authorization\FunctionalRoleGrants;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\License\FeatureMap;
use TT\Modules\License\LicenseGate;
use TT\Modules\License\LicenseMode;
use TT\Modules\ReportStudio\Domain\ReportSubjectOptions;
use TT\Modules\ReportStudio\Domain\SharedReads;
use TT\Modules\ReportStudio\Repositories\ReportsRepository;

/**
 * #4964 — a group subject by position.
 *
 * A player is in a position group by their primary position only, read
 * through `PlayerPosition` and compared as a whole code: each player is in
 * one group, and a short code never matches inside a longer one. A coach
 * reading a head of development's group sees their own players in it, and a
 * block that doesn't work for a group is switched off with its reason and
 * refused on save.
 */
final class ReportStudioGroupByPositionTest extends WP_UnitTestCase {

    use CommitsAfterCascade;

    private int $team_a = 0;
    private int $team_b = 0;
    private int $coach = 0;
    private int $hod = 0;

    /** @var array<string,int> */
    private array $players = [];

    public function set_up(): void {
        parent::set_up();
        $this->markFixtureFloor();
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();

        global $wpdb, $wp_rest_server;
        $wpdb->hide_errors();
        $p    = $wpdb->prefix;
        $club = (int) CurrentClub::id();

        // A short code that sits inside a longer one, as a configured
        // position: `W` inside `LW` and `RW`.
        $wpdb->insert( "{$p}tt_lookups", [ 'club_id' => $club, 'lookup_type' => 'position', 'name' => 'W', 'sort_order' => 99 ] );

        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $club, 'name' => 'Posities O14', 'age_group' => 'O14' ] );
        $this->team_a = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $club, 'name' => 'Posities O15', 'age_group' => 'O15' ] );
        $this->team_b = (int) $wpdb->insert_id;

        $this->players = [
            'cb_first' => $this->player( $this->team_a, 'Cas', '["CB","RB"]' ),
            'rb_first' => $this->player( $this->team_a, 'Rik', '["RB","CB"]' ),
            'legacy'   => $this->player( $this->team_a, 'Lev', 'CB, LB' ),
            'lw'       => $this->player( $this->team_a, 'Lars', '["LW"]' ),
            'w'        => $this->player( $this->team_a, 'Wout', '["W"]' ),
            'none'     => $this->player( $this->team_a, 'Nol', '' ),
            'cb_b'     => $this->player( $this->team_b, 'Bas', '["CB"]' ),
        ];

        $this->coach = $this->coachOf( $this->team_a );
        $this->hod   = (int) self::factory()->user->create( [ 'role' => 'tt_head_dev' ] );
        $this->flush();

        FeatureRegistry::setEnabled( 'report_studio', true );
        LicenseMode::overrideForTests( true );
        LicenseGate::overrideTierForTests( FeatureMap::TIER_PRO );

        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );
        wp_set_current_user( $this->hod );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        LicenseMode::overrideForTests( null );
        LicenseGate::overrideTierForTests( null );
        wp_set_current_user( 0 );
        $this->cleanUpAndCommit();
        $this->flush();
        parent::tear_down();
    }

    public function test_a_player_is_in_the_group_of_their_primary_position_only(): void {
        $repo = new ReportsRepository();
        $cb   = $repo->cohortPlayers( [ 'team_ids' => [ $this->team_a, $this->team_b ], 'age_group' => '', 'position' => 'CB' ] );
        $this->assertEqualsCanonicalizing( [ $this->players['cb_first'], $this->players['legacy'], $this->players['cb_b'] ], $cb, 'CB first, also in a legacy list' );
        $this->assertNotContains( $this->players['rb_first'], $cb, 'a player who lists CB second is not a centre-back here' );

        $rb = $repo->cohortPlayers( [ 'team_ids' => [ $this->team_a ], 'age_group' => '', 'position' => 'RB' ] );
        $this->assertSame( [ $this->players['rb_first'] ], $rb, 'they are in the group of their primary position' );

        $this->assertSame( [ $this->players['cb_b'] ], $repo->cohortPlayers( [ 'team_ids' => [ $this->team_a, $this->team_b ], 'age_group' => 'O15', 'position' => 'cb' ] ), 'with the age group, and in any case' );
        $this->assertCount( 6, $repo->cohortPlayers( [ 'team_ids' => [ $this->team_a ], 'age_group' => '', 'position' => '' ] ), 'no position is every player' );
    }

    public function test_a_short_code_never_matches_inside_a_longer_one(): void {
        $repo = new ReportsRepository();
        $this->assertSame( [ $this->players['w'] ], $repo->cohortPlayers( [ 'team_ids' => [ $this->team_a ], 'age_group' => '', 'position' => 'W' ] ) );
        $this->assertSame( [ $this->players['lw'] ], $repo->cohortPlayers( [ 'team_ids' => [ $this->team_a ], 'age_group' => '', 'position' => 'LW' ] ) );
    }

    public function test_the_positions_offered_are_the_academys_codes_with_their_names(): void {
        $positions = ReportSubjectOptions::positions();
        $this->assertArrayHasKey( 'CB', $positions );
        $this->assertArrayHasKey( 'W', $positions );
        $this->assertNotSame( '', $positions['CB'] );
    }

    public function test_a_position_the_academy_does_not_have_is_refused(): void {
        $res = $this->send( 'POST', 'reports', [ 'scope' => 'group', 'binding' => 'on_open', 'sharing' => 'role', 'cohort' => [ 'team_ids' => [ $this->team_a ], 'position' => 'ZZ' ] ] );
        $this->assertSame( 400, $res->get_status() );
        $this->assertSame( 'cohort', (string) ( $this->details( $res )['field'] ?? '' ) );

        $ok = $this->send( 'POST', 'reports', [ 'scope' => 'group', 'binding' => 'on_open', 'sharing' => 'role', 'cohort' => [ 'team_ids' => [ $this->team_a ], 'position' => 'cb' ] ] );
        $this->assertSame( 201, $ok->get_status(), (string) wp_json_encode( $ok->get_data() ) );
        $this->assertSame( 'CB', $ok->get_data()['data']['subject']['cohort']['position'] ?? null, 'stored as the code' );
    }

    public function test_a_coach_reading_the_group_sees_their_own_players_in_it_only(): void {
        $id = $this->send( 'POST', 'reports', [
            'scope' => 'group', 'binding' => 'on_open', 'sharing' => 'role', 'name' => 'Centrale verdedigers',
            'cohort' => [ 'team_ids' => [ $this->team_a, $this->team_b ], 'position' => 'CB' ],
        ] )->get_data()['data']['id'];
        $this->send( 'PATCH', "reports/{$id}", [ 'definition' => [ 'blocks' => [ [ 'key' => 'attendance_per_week' ] ] ] ] );
        $this->assertSame( 201, $this->send( 'POST', "reports/{$id}/versions", [ 'note' => 'Published', 'persona_keys' => [ 'head_coach' ] ] )->get_status() );

        $all = $this->send( 'GET', "reports/{$id}/data", [], [ 'from' => '2020-03-01', 'to' => '2020-03-31', 'source' => 'published' ] );
        $this->assertEqualsCanonicalizing( [ $this->players['cb_first'], $this->players['legacy'], $this->players['cb_b'] ], $all->get_data()['data']['context']['player_ids'] );

        wp_set_current_user( $this->coach );
        $mine = $this->send( 'GET', "reports/{$id}/data", [], [ 'from' => '2020-03-01', 'to' => '2020-03-31' ] );
        $this->assertSame( 200, $mine->get_status() );
        $this->assertEqualsCanonicalizing( [ $this->players['cb_first'], $this->players['legacy'] ], $mine->get_data()['data']['context']['player_ids'], 'the coach sees the centre-backs of their own team' );
    }

    public function test_a_block_without_group_support_is_switched_off_and_refused(): void {
        $list        = $this->send( 'GET', 'report-blocks', [], [ 'scope' => 'group' ] )->get_data()['data'];
        $offered     = array_column( $list['blocks'], 'key' );
        $unavailable = array_column( $list['unavailable'], 'reason', 'key' );
        $this->assertContains( 'attendance_per_week', $offered, 'the block that works for a group' );
        $this->assertArrayHasKey( 'goals_assists', $unavailable );
        $this->assertSame( 'This block does not work for a group of players.', $unavailable['goals_assists'] );

        $id  = $this->send( 'POST', 'reports', [ 'scope' => 'group', 'binding' => 'on_open', 'sharing' => 'role', 'cohort' => [ 'team_ids' => [ $this->team_a ] ] ] )->get_data()['data']['id'];
        $res = $this->send( 'PATCH', "reports/{$id}", [ 'definition' => [ 'blocks' => [ [ 'key' => 'attendance_per_week' ], [ 'key' => 'goals_assists' ] ] ] ] );
        $this->assertSame( 400, $res->get_status() );
        $this->assertSame( 'block_scope', (string) ( $res->get_data()['errors'][0]['code'] ?? '' ) );
        $this->assertSame( 'This block does not work for a group of players.', (string) ( $res->get_data()['errors'][0]['message'] ?? '' ) );
        $this->assertSame( 1, (int) ( $this->details( $res )['block'] ?? -1 ), 'naming the block' );
    }

    /** The first error's details, which the envelope carries as an object. @return array<string,mixed> */
    private function details( WP_REST_Response $res ): array {
        return (array) ( $res->get_data()['errors'][0]['details'] ?? [] );
    }

    /**
     * @param array<string,mixed> $body
     * @param array<string,mixed> $query
     */
    private function send( string $method, string $route, array $body = [], array $query = [] ): WP_REST_Response {
        $req = new WP_REST_Request( $method, '/talenttrack/v1/' . $route );
        if ( $query !== [] ) $req->set_query_params( $query );
        if ( $method !== 'GET' ) {
            $req->set_header( 'Content-Type', 'application/json' );
            $req->set_body( (string) wp_json_encode( (object) $body ) );
        }
        $this->flush();
        return rest_do_request( $req );
    }

    private function player( int $team, string $first, string $positions ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id' => CurrentClub::id(), 'team_id' => $team, 'first_name' => $first, 'last_name' => 'Positie', 'status' => 'active',
            'preferred_positions' => $positions,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function coachOf( int $team ): int {
        global $wpdb;
        $user = (int) self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $wpdb->insert( "{$wpdb->prefix}tt_people", [
            'club_id' => CurrentClub::id(), 'first_name' => 'Head', 'last_name' => 'Coach', 'role_type' => 'head_coach', 'wp_user_id' => $user, 'status' => 'active',
        ] );
        $person = (int) $wpdb->insert_id;
        $wpdb->insert( "{$wpdb->prefix}tt_team_people", [ 'team_id' => $team, 'person_id' => $person, 'role_in_team' => 'head_coach', 'is_head_coach' => 1 ] );
        $wpdb->insert( "{$wpdb->prefix}tt_user_role_scopes", [ 'club_id' => CurrentClub::id(), 'person_id' => $person, 'role_id' => 1, 'scope_type' => 'team', 'scope_id' => $team ] );
        return $user;
    }

    private function flush(): void {
        MatrixRepository::clearCache();
        FunctionalRoleGrants::clearCache();
        AuthorizationService::flushCache();
        FactScope::flush();
        SharedReads::flush();
        wp_cache_flush();
    }
}
