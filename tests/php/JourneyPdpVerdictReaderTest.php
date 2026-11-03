<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Journey\PlayerEventsRepository;
use TT\Infrastructure\Players\PlayerParentVisibilityRepository;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Analytics\Reports\TeamMonthlyComposers\ChangesComposer;
use TT\Modules\Analytics\Reports\TeamMonthlyComposers\TeamReportContext;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Authorization\PersonaResolver;

/**
 * #4775 — a PDP verdict entry on a player's journey follows the PDP verdict
 * right. Two readers of the same squad: the assistant coach reads the
 * journey (`player_timeline`) and not the verdict (`pdp_verdict`); the head
 * coach reads both. Real personas: account, `tt_people`, team and scope
 * rows, no capability added to either user.
 */
final class JourneyPdpVerdictReaderTest extends WP_UnitTestCase {

    private const VERDICT  = 'PDP verdict: promote';
    private const POSITION = 'Moved to left back.';

    private int $team      = 0;
    private int $player    = 0;
    private int $assistant = 0;
    private int $head      = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb, $wp_rest_server;
        $p    = $wpdb->prefix;
        $club = (int) CurrentClub::id();

        $roles = new RolesService();
        $roles->installRoles();
        $roles->ensureCapabilities();
        $this->flush();

        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $club, 'name' => 'Verdict U14', 'age_group' => 'U14' ] );
        $this->team = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_players", [
            'club_id' => $club, 'team_id' => $this->team,
            'first_name' => 'Verdict', 'last_name' => 'Playerx', 'status' => 'active',
        ] );
        $this->player = (int) $wpdb->insert_id;

        $events = [
            [ 'pdp_verdict_recorded', '2026-06-20 09:00:00', self::VERDICT, '{"pdp_file_id":1,"decision":"promote"}' ],
            [ 'position_changed', '2026-06-10 09:00:00', self::POSITION, '{}' ],
        ];
        foreach ( $events as $i => [ $type, $when, $summary, $payload ] ) {
            $wpdb->insert( "{$p}tt_player_events", [
                'club_id'            => $club,
                'uuid'               => wp_generate_uuid4(),
                'player_id'          => $this->player,
                'event_type'         => $type,
                'event_date'         => $when,
                'summary'            => $summary,
                'payload'            => $payload,
                'visibility'         => 'public',
                'source_module'      => 'tests',
                'source_entity_type' => 'test_event',
                'source_entity_id'   => $i + 1,
            ] );
            $this->assertGreaterThan( 0, (int) $wpdb->insert_id, 'fixture: the journey entry was written' );
        }

        $this->assistant = $this->coach( false );
        $this->head      = $this->coach( true );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        $this->flush();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_the_readers_hold_what_the_test_relies_on(): void {
        foreach ( [ $this->assistant, $this->head ] as $uid ) {
            $this->assertTrue( AuthorizationService::canReadPlayerSection( $uid, $this->player, 'player_timeline' ) );
        }
        $this->assertFalse( AuthorizationService::canReadPlayerSection( $this->assistant, $this->player, 'pdp_verdict' ) );
        $this->assertTrue( AuthorizationService::canReadPlayerSection( $this->head, $this->player, 'pdp_verdict' ) );
    }

    public function test_the_timeline_leaves_the_verdict_out_for_a_reader_without_the_verdict_right(): void {
        wp_set_current_user( $this->assistant );
        $summaries = $this->summaries( "/talenttrack/v1/players/{$this->player}/timeline" );

        $this->assertContains( self::POSITION, $summaries, 'the rest of the journey stays' );
        $this->assertNotContains( self::VERDICT, $summaries );
    }

    public function test_the_timeline_keeps_the_verdict_for_a_reader_with_both_rights(): void {
        wp_set_current_user( $this->head );
        $summaries = $this->summaries( "/talenttrack/v1/players/{$this->player}/timeline" );

        $this->assertContains( self::POSITION, $summaries );
        $this->assertContains( self::VERDICT, $summaries );
    }

    public function test_the_transitions_route_follows_the_same_rule(): void {
        wp_set_current_user( $this->assistant );
        $this->assertNotContains( self::VERDICT, $this->summaries( "/talenttrack/v1/players/{$this->player}/transitions" ) );
    }

    public function test_the_monthly_report_changes_follow_the_same_rule(): void {
        $this->assertNotContains( self::VERDICT, $this->changes( $this->assistant ) );
        $this->assertContains( self::POSITION, $this->changes( $this->assistant ) );
        $this->assertContains( self::VERDICT, $this->changes( $this->head ) );
        $this->assertNotContains( self::VERDICT, $this->changes( 0 ), 'an unattended render holds no right' );
    }

    /**
     * A linked parent holds `pdp_verdict` at player scope, so the child's
     * own PDP switch decides, as on `GET players/{id}/pdp`: a child who keeps
     * the PDP from their parents but shares the journey shows no verdict.
     */
    public function test_a_parent_sees_the_verdict_only_while_the_child_shares_the_pdp(): void {
        global $wpdb;
        $parent = self::factory()->user->create( [ 'role' => 'tt_parent' ] );
        $wpdb->insert( "{$wpdb->prefix}tt_player_parents", [
            'club_id'        => (int) CurrentClub::id(),
            'player_id'      => $this->player,
            'parent_user_id' => $parent,
        ] );
        $this->flush();
        $this->assertTrue( AuthorizationService::canReadPlayerSection( $parent, $this->player, 'pdp_verdict' ), 'precondition: the parent holds the verdict right' );

        wp_set_current_user( $parent );
        $this->assertContains( self::VERDICT, $this->summaries( "/talenttrack/v1/players/{$this->player}/timeline" ), 'shared: the verdict shows' );

        ( new PlayerParentVisibilityRepository() )->setVisibility( $this->player, 'pdp', false );
        $this->flush();
        $this->assertTrue( AuthorizationService::parentCanViewSection( $parent, $this->player, 'journey' ), 'precondition: the journey stays shared' );

        $summaries = $this->summaries( "/talenttrack/v1/players/{$this->player}/timeline" );
        $this->assertContains( self::POSITION, $summaries, 'the rest of the journey stays' );
        $this->assertNotContains( self::VERDICT, $summaries, 'the child keeps the PDP from the parent' );
        $this->assertNotContains( self::VERDICT, $this->summaries( "/talenttrack/v1/players/{$this->player}/transitions" ) );
    }

    /**
     * The cohort path reads across players, so the rule is asked per row:
     * a reader of the cohort without the verdict right gets no verdict row,
     * an academy admin does. The observer is the reader here because the
     * route asks the settings view right, which that seat holds.
     */
    public function test_the_cohort_route_follows_the_same_rule(): void {
        global $wpdb;
        $observer = self::factory()->user->create( [ 'role' => 'tt_readonly_observer' ] );
        $wpdb->insert( "{$wpdb->prefix}tt_people", [
            'club_id' => (int) CurrentClub::id(), 'first_name' => 'Verdict', 'last_name' => 'Observerx',
            'role_type' => 'other', 'wp_user_id' => $observer, 'status' => 'active',
        ] );
        $admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
        // The cohort surface ships switched off.
        \TT\Core\FeatureRegistry::setEnabled( 'cohort_transitions', true );
        $this->flush();
        $this->assertFalse( AuthorizationService::canReadPlayerSection( $observer, $this->player, 'pdp_verdict' ), 'precondition' );

        $route = '/talenttrack/v1/journey/cohort-transitions';
        $query = [ 'event_type' => 'pdp_verdict_recorded', 'from' => '2026-06-01 00:00:00', 'to' => '2026-06-30 23:59:59' ];

        $this->assertTrue( \TT\Core\FeatureRegistry::isEnabled( 'cohort_transitions' ), 'precondition: the cohort surface is on' );
        $this->assertTrue( user_can( $observer, 'tt_view_settings' ), 'precondition: the observer passes the route' );

        wp_set_current_user( $admin );
        $this->assertContains( self::VERDICT, array_column( $this->cohortRows( $route, $query ), 'summary' ), 'the academy admin gets the verdict row' );

        wp_set_current_user( $observer );
        $this->assertNotContains( self::VERDICT, array_column( $this->cohortRows( $route, $query ), 'summary' ), 'no verdict row for a reader without the verdict right' );

        \TT\Core\FeatureRegistry::setEnabled( 'cohort_transitions', false );
    }

    /**
     * @param array<string,string> $query
     * @return list<array<string,mixed>> the rows; fails when the route refuses the reader.
     */
    private function cohortRows( string $route, array $query ): array {
        $request = new WP_REST_Request( 'GET', $route );
        foreach ( $query as $k => $v ) $request->set_param( $k, $v );
        $response = rest_do_request( $request );
        $data     = (array) $response->get_data();
        $this->assertSame( 200, (int) $response->get_status(), 'precondition: the route answers this reader: ' . wp_json_encode( $data ) );
        return array_values( (array) ( $data['data']['rows'] ?? [] ) );
    }

    public function test_the_filter_passes_every_other_entry_unchanged(): void {
        $rows = [
            (object) [ 'event_type' => 'pdp_verdict_recorded', 'player_id' => $this->player ],
            (object) [ 'event_type' => 'position_changed', 'player_id' => $this->player ],
            (object) [ 'event_type' => 'goal_set', 'player_id' => $this->player ],
        ];

        $this->assertSame( [ $rows[1], $rows[2] ], PlayerEventsRepository::readableBy( $this->assistant, $rows ) );
        $this->assertSame( $rows, PlayerEventsRepository::readableBy( $this->head, $rows ) );
    }

    /** @return list<string> */
    private function summaries( string $route ): array {
        $response = rest_do_request( new WP_REST_Request( 'GET', $route ) );
        $this->assertSame( 200, (int) $response->get_status(), $route );
        $data   = (array) $response->get_data();
        $events = (array) ( $data['data']['events'] ?? [] );
        return array_values( array_map( static fn ( $e ): string => (string) ( ( (array) $e )['summary'] ?? '' ), $events ) );
    }

    /** @return list<string> */
    private function changes( int $viewer ): array {
        $out = ChangesComposer::compose( new TeamReportContext( $this->team, '2026-06-01', '2026-06-30', $viewer ) );
        return array_values( array_column( (array) $out['events'], 'summary' ) );
    }

    private function coach( bool $is_head_coach ): int {
        global $wpdb;
        $p   = $wpdb->prefix;
        $uid = self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $wpdb->insert( "{$p}tt_people", [
            'club_id'    => (int) CurrentClub::id(),
            'first_name' => $is_head_coach ? 'Hoofd' : 'Assistent',
            'last_name'  => 'Verdictx',
            'role_type'  => $is_head_coach ? 'head_coach' : 'assistant_coach',
            'wp_user_id' => $uid,
            'status'     => 'active',
        ] );
        $person = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_team_people", [
            'club_id'       => (int) CurrentClub::id(),
            'team_id'       => $this->team,
            'person_id'     => $person,
            'role_in_team'  => $is_head_coach ? 'head_coach' : 'assistant_coach',
            'is_head_coach' => $is_head_coach ? 1 : 0,
        ] );
        $wpdb->insert( "{$p}tt_user_role_scopes", [
            'person_id' => $person, 'role_id' => 1, 'scope_type' => 'team', 'scope_id' => $this->team,
        ] );
        $this->flush();
        $this->assertContains( $is_head_coach ? 'head_coach' : 'assistant_coach', PersonaResolver::personasFor( $uid ) );
        return $uid;
    }

    private function flush(): void {
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
    }
}
