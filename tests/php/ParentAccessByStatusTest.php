<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Players\ParentChildResolver;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Activities\ActivityAccess;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Authorization\MatrixGate;
use TT\Modules\Comms\Domain\Recipient;
use TT\Modules\Comms\Recipient\RecipientResolver;
use TT\Modules\Threads\Adapters\GoalThreadAdapter;

/**
 * #4089 — what a guardian reaches depends on the child's status.
 *
 *   - active    → everything, as before;
 *   - trial     → the trial's schedule and attendance, and nothing else;
 *   - graduated → the reads that were open before, no writes, no messages;
 *   - released  → nothing, as before.
 *
 * One parent is linked to four children, one per status, so every refusal
 * sits beside a grant on a sibling: a test that only ever asserts a refusal
 * cannot tell "narrowed correctly" from "refused everything".
 *
 * The trial half is the privacy half, and it is asserted route by route:
 * every per-player read the REST API offers answers the trialist's guardian
 * with an error, while the schedule answers them with the trial's trainings.
 */
final class ParentAccessByStatusTest extends WP_UnitTestCase {

    private int $club      = 0;
    private int $team      = 0;
    private int $parent    = 0;
    private int $active    = 0;
    private int $trial     = 0;
    private int $graduated = 0;
    private int $released  = 0;
    private int $activity  = 0;

    public function set_up(): void {
        parent::set_up();
        $roles = new RolesService();
        $roles->installRoles();
        $roles->ensureCapabilities();

        global $wp_rest_server, $wpdb;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        // The seed rows these assertions lean on, written here so the test
        // does not depend on whether the suite seeded the matrix.
        $matrix = new MatrixRepository();
        $matrix->setRow( 'parent', 'evaluations', MatrixGate::READ, MatrixGate::SCOPE_PLAYER, '' );
        $matrix->setRow( 'parent', 'activities', MatrixGate::READ, MatrixGate::SCOPE_PLAYER, '' );
        $matrix->setRow( 'parent', 'attendance', MatrixGate::READ, MatrixGate::SCOPE_PLAYER, '' );
        $matrix->setRow( 'parent', 'thread_messages', MatrixGate::CHANGE, MatrixGate::SCOPE_PLAYER, '' );
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();

        $this->club = (int) CurrentClub::id();
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => $this->club, 'name' => 'Status JO12-1' ] );
        $this->team = (int) $wpdb->insert_id;

        $this->parent    = self::factory()->user->create( [ 'role' => 'tt_parent' ] );
        $this->active    = $this->child( 'Active', 'active' );
        $this->trial     = $this->child( 'Trial', 'trial' );
        $this->graduated = $this->child( 'Graduated', 'graduated' );
        $this->released  = $this->child( 'Released', 'released' );

        $wpdb->insert( "{$wpdb->prefix}tt_activities", [
            'club_id'             => $this->club,
            'team_id'             => $this->team,
            'title'               => 'Trial training',
            'session_date'        => gmdate( 'Y-m-d', strtotime( '+2 days' ) ),
            'activity_type_key'   => 'training',
            'activity_status_key' => 'planned',
        ] );
        $this->activity = (int) $wpdb->insert_id;
        $this->assertGreaterThan( 0, $this->activity, 'fixture: the activity was written' );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        parent::tear_down();
    }

    // ── The rule itself ─────────────────────────────────────────────────

    public function test_each_status_maps_to_one_access_level(): void {
        $this->assertSame( ParentChildResolver::ACCESS_FULL, ParentChildResolver::accessForStatus( 'active' ) );
        $this->assertSame( ParentChildResolver::ACCESS_TRIAL, ParentChildResolver::accessForStatus( 'trial' ) );
        $this->assertSame( ParentChildResolver::ACCESS_READ_ONLY, ParentChildResolver::accessForStatus( 'graduated' ) );
        $this->assertSame( ParentChildResolver::ACCESS_NONE, ParentChildResolver::accessForStatus( 'released' ) );
        $this->assertSame( ParentChildResolver::ACCESS_NONE, ParentChildResolver::accessForStatus( 'inactive' ) );
        $this->assertSame( ParentChildResolver::ACCESS_NONE, ParentChildResolver::accessForStatus( 'something-new' ) );
    }

    public function test_the_trial_level_reads_the_schedule_and_attendance_only(): void {
        $trial = ParentChildResolver::ACCESS_TRIAL;

        foreach ( [ 'activities', 'my_activities', 'attendance' ] as $entity ) {
            $this->assertTrue( ParentChildResolver::accessAllowsEntity( $trial, $entity, 'read' ), "trial reads {$entity}" );
            $this->assertFalse( ParentChildResolver::accessAllowsEntity( $trial, $entity, 'change' ), "trial writes no {$entity}" );
        }
        foreach ( [ 'players', 'my_card', 'evaluations', 'my_evaluations', 'goals', 'my_goals', 'pdp_file', 'pdp_conversations',
                    'player_status', 'player_potential', 'measurements', 'player_injuries', 'player_suspensions',
                    'player_timeline', 'my_journey', 'media', 'training_exposure', 'player_tournaments', 'thread_messages',
                    'team', 'my_team' ] as $entity ) {
            $this->assertFalse( ParentChildResolver::accessAllowsEntity( $trial, $entity, 'read' ), "trial reads no {$entity}" );
        }
        foreach ( [ 'evaluations', 'goals', 'journey', 'measurements', 'minutes', 'pdp', 'tournaments', 'training', 'activities' ] as $section ) {
            $this->assertFalse( ParentChildResolver::accessAllowsSection( $trial, $section ), "trial sees no {$section} section" );
            $this->assertTrue( ParentChildResolver::accessAllowsSection( ParentChildResolver::ACCESS_FULL, $section ), "full sees {$section}" );
        }
        $this->assertTrue( ParentChildResolver::accessAllowsViewSlug( $trial, 'my-activities' ) );
        foreach ( [ 'overview', 'my-development', 'my-evaluations', 'my-goals', 'my-pdp', 'my-journey', 'my-team', 'measurements', 'my-tasks' ] as $slug ) {
            $this->assertFalse( ParentChildResolver::accessAllowsViewSlug( $trial, $slug ), "trial opens no {$slug}" );
        }
    }

    public function test_the_read_only_level_reads_and_never_writes(): void {
        $ro = ParentChildResolver::ACCESS_READ_ONLY;
        $this->assertTrue( ParentChildResolver::accessAllowsEntity( $ro, 'evaluations', 'read' ) );
        $this->assertFalse( ParentChildResolver::accessAllowsEntity( $ro, 'thread_messages', 'change' ) );
        $this->assertFalse( ParentChildResolver::accessAllowsEntity( $ro, 'invitations', 'create_delete' ) );
        $this->assertTrue( ParentChildResolver::accessMeets( $ro, ParentChildResolver::NEED_RECORD ) );
        $this->assertFalse( ParentChildResolver::accessMeets( $ro, ParentChildResolver::NEED_FULL ) );
        $this->assertTrue( ParentChildResolver::accessAllowsEntity( ParentChildResolver::ACCESS_FULL, 'thread_messages', 'change' ) );
        $this->assertFalse( ParentChildResolver::accessAllowsEntity( ParentChildResolver::ACCESS_NONE, 'activities', 'read' ) );
    }

    // ── The resolver against the database ──────────────────────────────

    public function test_the_parent_is_shown_three_children_and_reads_two_records(): void {
        $shown = array_map( static fn( $c ): int => (int) $c->id, ParentChildResolver::children( $this->parent ) );
        sort( $shown );
        $expected = [ $this->active, $this->trial, $this->graduated ];
        sort( $expected );
        $this->assertSame( $expected, $shown, 'active, trial and graduated are listed; released is not' );

        $this->assertSame( $this->sorted( [ $this->active, $this->graduated ] ), $this->sorted( ParentChildResolver::childIds( $this->parent ) ) );
        $this->assertSame( $expected, $this->sorted( ParentChildResolver::childIds( $this->parent, ParentChildResolver::NEED_SCHEDULE ) ) );
        $this->assertSame( [ $this->active ], ParentChildResolver::childIds( $this->parent, ParentChildResolver::NEED_FULL ) );

        $this->assertSame( ParentChildResolver::ACCESS_FULL, ParentChildResolver::accessFor( $this->parent, $this->active ) );
        $this->assertSame( ParentChildResolver::ACCESS_TRIAL, ParentChildResolver::accessFor( $this->parent, $this->trial ) );
        $this->assertSame( ParentChildResolver::ACCESS_READ_ONLY, ParentChildResolver::accessFor( $this->parent, $this->graduated ) );
        $this->assertSame( ParentChildResolver::ACCESS_NONE, ParentChildResolver::accessFor( $this->parent, $this->released ) );
    }

    public function test_a_parent_of_only_a_trialist_is_still_a_parent_viewer(): void {
        global $wpdb;
        $solo  = self::factory()->user->create( [ 'role' => 'tt_parent' ] );
        $wpdb->insert( "{$wpdb->prefix}tt_player_parents", [
            'club_id'        => $this->club,
            'player_id'      => $this->trial,
            'parent_user_id' => $solo,
        ] );

        $this->assertTrue( ParentChildResolver::isParentViewer( $solo ), 'the trialist\'s family gets the parent dashboard' );
        $this->assertSame( $this->trial, (int) ParentChildResolver::defaultChild( $solo )->id );
        $this->assertFalse( AuthorizationService::canViewPlayer( $solo, $this->trial ), 'and still cannot open the record' );
    }

    // ── Active: unchanged ──────────────────────────────────────────────

    public function test_an_active_childs_guardian_keeps_full_access(): void {
        $this->assertTrue( AuthorizationService::canViewPlayer( $this->parent, $this->active ) );
        $this->assertTrue( AuthorizationService::parentCanViewSection( $this->parent, $this->active, 'evaluations' ) );
        $this->assertTrue( MatrixGate::can( $this->parent, 'evaluations', MatrixGate::READ, MatrixGate::SCOPE_PLAYER, $this->active ) );
        $this->assertTrue( MatrixGate::can( $this->parent, 'thread_messages', MatrixGate::CHANGE, MatrixGate::SCOPE_PLAYER, $this->active ) );
        $this->assertSame( [ $this->parent ], ParentChildResolver::guardiansOf( $this->active ) );
        $this->assertSame( [ $this->parent ], ParentChildResolver::guardiansOf( $this->active, true ) );
    }

    // ── Released: unchanged ────────────────────────────────────────────

    public function test_a_released_childs_guardian_has_nothing(): void {
        $this->assertFalse( AuthorizationService::canViewPlayer( $this->parent, $this->released ) );
        $this->assertFalse( AuthorizationService::parentCanViewSection( $this->parent, $this->released, 'evaluations' ) );
        $this->assertFalse( MatrixGate::can( $this->parent, 'activities', MatrixGate::READ, MatrixGate::SCOPE_PLAYER, $this->released ) );
        $this->assertFalse( ActivityAccess::canReadAsPlayerOrParent( $this->parent, $this->released ) );
        $this->assertSame( [], ParentChildResolver::guardiansOf( $this->released ) );
    }

    // ── Trial: schedule + attendance, and nothing else ─────────────────

    public function test_a_trialists_guardian_reads_the_schedule(): void {
        $this->assertTrue( MatrixGate::can( $this->parent, 'activities', MatrixGate::READ, MatrixGate::SCOPE_PLAYER, $this->trial ) );
        $this->assertTrue( MatrixGate::can( $this->parent, 'attendance', MatrixGate::READ, MatrixGate::SCOPE_PLAYER, $this->trial ) );
        $this->assertTrue( ActivityAccess::canReadAsPlayerOrParent( $this->parent, $this->trial ) );

        wp_set_current_user( $this->parent );
        $req = new WP_REST_Request( 'GET', '/talenttrack/v1/activities' );
        $req->set_query_params( [ 'filter' => [ 'player_id' => $this->trial, 'date_from' => gmdate( 'Y-m-d' ) ], 'per_page' => 100 ] );
        $res = rest_do_request( $req );
        $this->assertSame( 200, $res->get_status(), 'the trial\'s schedule answers' );

        $ids = [];
        foreach ( (array) ( $res->get_data()['data']['rows'] ?? [] ) as $row ) {
            $ids[] = (int) ( (array) $row )['id'];
        }
        $this->assertContains( $this->activity, $ids, 'with the trial\'s training on it' );

        $peek = rest_do_request( new WP_REST_Request( 'GET', "/talenttrack/v1/activities/{$this->activity}/summary" ) );
        $this->assertSame( 200, $peek->get_status(), 'and the training itself can be opened' );
    }

    public function test_a_trialists_guardian_reads_nothing_of_the_development_record(): void {
        $this->assertFalse( AuthorizationService::canViewPlayer( $this->parent, $this->trial ) );
        $this->assertFalse( ParentChildResolver::isParentOf( $this->parent, $this->trial ) );
        foreach ( [ 'evaluations', 'goals', 'journey', 'measurements', 'minutes', 'pdp', 'tournaments', 'training', 'activities' ] as $section ) {
            $this->assertFalse(
                AuthorizationService::parentCanViewSection( $this->parent, $this->trial, $section ),
                "the {$section} section is closed during the trial"
            );
        }
        $this->assertFalse( MatrixGate::can( $this->parent, 'evaluations', MatrixGate::READ, MatrixGate::SCOPE_PLAYER, $this->trial ) );
        $this->assertFalse( ActivityAccess::canReadAttendanceNotes( $this->parent, $this->trial ), 'no staff remark on the register either' );
        $this->assertTrue( ActivityAccess::canReadAttendanceNotes( $this->parent, $this->active ) );

        // The control, on the sibling.
        $this->assertTrue( MatrixGate::can( $this->parent, 'evaluations', MatrixGate::READ, MatrixGate::SCOPE_PLAYER, $this->active ) );
    }

    /**
     * Route by route: every per-player read the API offers refuses the
     * trialist's guardian. A route missing from this install is skipped,
     * and the test insists enough of them were checked to mean something.
     */
    public function test_no_per_player_route_answers_a_trialists_guardian(): void {
        $templates = [
            '/talenttrack/v1/players/(?P<id>\d+)',
            '/talenttrack/v1/players/(?P<id>\d+)/summary',
            '/talenttrack/v1/players/(?P<id>\d+)/evaluations',
            '/talenttrack/v1/players/(?P<id>\d+)/goals',
            '/talenttrack/v1/players/(?P<id>\d+)/pdp',
            '/talenttrack/v1/players/(?P<id>\d+)/timeline',
            '/talenttrack/v1/players/(?P<id>\d+)/transitions',
            '/talenttrack/v1/players/(?P<id>\d+)/rating-trend',
            '/talenttrack/v1/players/(?P<id>\d+)/events',
            '/talenttrack/v1/players/(?P<id>\d+)/injuries',
            '/talenttrack/v1/players/(?P<id>\d+)/suspensions',
            '/talenttrack/v1/players/(?P<player_id>\d+)/measurements',
            '/talenttrack/v1/players/(?P<id>\d+)/minutes',
            '/talenttrack/v1/players/(?P<id>\d+)/team',
            '/talenttrack/v1/players/(?P<id>\d+)/potential',
            '/talenttrack/v1/players/(?P<id>\d+)/status',
            '/talenttrack/v1/players/(?P<id>\d+)/behaviour-ratings',
            '/talenttrack/v1/players/(?P<id>\d+)/training-exposure',
            '/talenttrack/v1/players/(?P<id>\d+)/observations',
            '/talenttrack/v1/players/(?P<player_id>\d+)/goal-contributions',
            '/talenttrack/v1/players/(?P<id>\d+)/report-snapshots',
            '/talenttrack/v1/players/(?P<id>\d+)/scout-card',
            '/talenttrack/v1/players/(?P<id>\d+)/tournaments',
            '/talenttrack/v1/players/(?P<id>\d+)/strava/status',
        ];

        $routes  = rest_get_server()->get_routes();
        $checked = 0;
        wp_set_current_user( $this->parent );

        foreach ( $templates as $template ) {
            if ( ! isset( $routes[ $template ] ) ) continue;
            $url = str_replace( [ '(?P<id>\d+)', '(?P<player_id>\d+)' ], (string) $this->trial, $template );

            $res = rest_do_request( new WP_REST_Request( 'GET', $url ) );
            $this->assertGreaterThanOrEqual(
                400,
                $res->get_status(),
                "GET {$url} answered a trialist's guardian with " . $res->get_status()
            );
            $checked++;
        }

        $this->assertGreaterThanOrEqual( 15, $checked, 'enough per-player routes were registered to make this test mean something' );

        // The media list answers every media reader with a filtered list
        // rather than a refusal, so for it the proof is what comes back.
        if ( isset( $routes['/talenttrack/v1/players/(?P<id>\d+)/media'] ) ) {
            $res = rest_do_request( new WP_REST_Request( 'GET', "/talenttrack/v1/players/{$this->trial}/media" ) );
            if ( $res->get_status() === 200 ) {
                $envelope = (array) ( ( (array) $res->get_data() )['data'] ?? [] );
                $this->assertSame( [], (array) ( $envelope['items'] ?? [] ), 'no media of a trialist reaches the family' );
                $this->assertArrayNotHasKey( 'player_consent', $envelope, 'nor the trialist\'s consent state' );
            } else {
                $this->assertGreaterThanOrEqual( 400, $res->get_status() );
            }
        }
    }

    public function test_a_trialists_guardian_is_sent_nothing_about_development(): void {
        $this->assertSame( [ $this->parent ], ParentChildResolver::guardiansOf( $this->trial ), 'the trial welcome still reaches the family' );
        $this->assertSame( [], ParentChildResolver::guardiansOf( $this->trial, true ), 'a goal nudge or a plan does not' );

        $parents = array_filter(
            ( new RecipientResolver() )->forPlayer( $this->trial, true ),
            static fn( Recipient $r ): bool => $r->kind === Recipient::KIND_PARENT
        );
        $this->assertSame( [], array_values( $parents ) );
    }

    // ── Graduated: read-only, no messages ──────────────────────────────

    public function test_a_graduated_childs_guardian_reads_what_they_read_before(): void {
        $this->assertTrue( AuthorizationService::canViewPlayer( $this->parent, $this->graduated ) );
        $this->assertTrue( AuthorizationService::parentCanViewSection( $this->parent, $this->graduated, 'evaluations' ) );
        $this->assertTrue( MatrixGate::can( $this->parent, 'evaluations', MatrixGate::READ, MatrixGate::SCOPE_PLAYER, $this->graduated ) );
        $this->assertTrue( ActivityAccess::canReadAsPlayerOrParent( $this->parent, $this->graduated ) );
    }

    public function test_a_graduated_childs_guardian_writes_nothing(): void {
        $this->assertFalse(
            MatrixGate::can( $this->parent, 'thread_messages', MatrixGate::CHANGE, MatrixGate::SCOPE_PLAYER, $this->graduated ),
            'the matrix row grants change, and graduation narrows it to read'
        );
        $this->assertFalse( ParentChildResolver::isParentOf( $this->parent, $this->graduated, ParentChildResolver::NEED_FULL ) );
        $this->assertTrue( ParentChildResolver::isReadOnlyFor( $this->parent, $this->graduated ) );

        $author  = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $goal    = $this->goal( $this->graduated, $author );
        $sibling = $this->goal( $this->active, $author );
        $adapter = new GoalThreadAdapter();

        $this->assertTrue( $adapter->canRead( $this->parent, $goal ), 'the conversation stays readable' );
        $this->assertFalse( $adapter->canPost( $this->parent, $goal ), 'and closes to replies' );
        $this->assertNotContains( $this->parent, $adapter->participantUserIds( $goal ), 'and notifies them of nothing' );

        $this->assertTrue( $adapter->canPost( $this->parent, $sibling ), 'the active sibling\'s thread is unchanged' );
        $this->assertContains( $this->parent, $adapter->participantUserIds( $sibling ) );

        $route = '/talenttrack/v1/threads/(?P<type>[a-z_]+)/(?P<id>\d+)/messages';
        if ( ! isset( rest_get_server()->get_routes()[ $route ] ) ) return;

        wp_set_current_user( $this->parent );
        $req = new WP_REST_Request( 'POST', "/talenttrack/v1/threads/goal/{$goal}/messages" );
        $req->set_param( 'body', 'Thank you for everything.' );
        $res  = rest_do_request( $req );
        $data = (array) $res->get_data();
        $this->assertSame( 403, $res->get_status() );
        $this->assertSame( 'thread_read_only', (string) ( $data['code'] ?? '' ), 'with a reason a person can read' );
    }

    public function test_graduation_closes_the_child_out_for_messages(): void {
        $this->assertTrue( ParentChildResolver::isClosedOut( $this->graduated ) );
        $this->assertSame( [], ParentChildResolver::guardiansOf( $this->graduated ) );
        $this->assertSame( [], ( new RecipientResolver() )->forPlayerWithParents( $this->graduated ), 'nobody, the player\'s own account included' );
        $this->assertFalse( ParentChildResolver::isClosedOut( $this->trial ), 'a trial is not a close-out' );
    }

    // ── helpers ────────────────────────────────────────────────────────

    private function child( string $last, string $status ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id'    => $this->club,
            'team_id'    => $this->team,
            'first_name' => 'Status',
            'last_name'  => $last,
            'status'     => $status,
        ] );
        $id = (int) $wpdb->insert_id;
        $this->assertGreaterThan( 0, $id, "fixture: the {$status} player was written" );

        $wpdb->insert( "{$wpdb->prefix}tt_player_parents", [
            'club_id'        => $this->club,
            'player_id'      => $id,
            'parent_user_id' => $this->parent,
            'is_primary'     => 1,
        ] );
        return $id;
    }

    private function goal( int $player_id, int $author ): int {
        $id = ( new \TT\Infrastructure\Goals\GoalsRepository() )->create( [
            'player_id'  => $player_id,
            'title'      => 'Keep the ball close',
            'status'     => \TT\Domain\Vocabularies\Lookups\GoalStatus::IN_PROGRESS,
            'due_date'   => '2099-06-01',
            'created_by' => $author,
        ] );
        $this->assertGreaterThan( 0, $id, 'fixture: the goal was written' );
        return $id;
    }

    /**
     * @param list<int> $ids
     * @return list<int>
     */
    private function sorted( array $ids ): array {
        sort( $ids );
        return $ids;
    }
}
