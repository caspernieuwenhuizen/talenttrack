<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Config\ConfigService;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Authorization\MatrixGate;
use TT\Modules\Pdp\PdpAccess;
use TT\Modules\Pdp\PdpModule;
use TT\Shared\Frontend\DashboardShortcode;
use TT\Shared\Tiles\TileRegistry;

/**
 * #4688 — the read-only observer reads the non-sensitive entities, and
 * nothing else; PDP stays closed on every route.
 *
 * Asked as real personas: a WordPress account on the role, a `tt_people`
 * row for it, team and scope rows for the coach used as the control, the
 * matrix switched on, and no capability added to any user. The grants come
 * from the matrix the migration leaves behind, as on an upgraded install.
 */
final class ObserverReadsNonSensitiveTest extends WP_UnitTestCase {

    private const MIGRATION = '/database/migrations/0310_authorization_observer_reads_non_sensitive.php';

    /** Read at global scope before #4688, through the view capabilities. */
    private const HELD_BEFORE = [
        'team', 'players', 'people', 'evaluations', 'activities',
        'activities_panel', 'goals', 'reports', 'settings',
    ];

    /** Closed by the owner, 2 November 2026, and by #4695. */
    private const CLOSED = [
        'player_injuries', 'safeguarding_notes', 'player_notes',
        'staff_only_notes', 'thread_messages', 'parent_accounts', 'audit_log',
        'pdp_file', 'pdp_conversations', 'pdp_verdict', 'pdp_evidence_packet',
        'pdp_planning', 'pdp_panel', 'pdp_calendar_export', 'my_pdp_panel',
        'report_studio', 'report_publishing',
    ];

    /** Not granted here: each follows with its masking or its own review. */
    private const WITH_THEIR_MASKING = [
        'attendance'              => 'its masking (#4692)',
        'evaluations_panel'       => 'its masking (#4693)',
        'player_status'           => 'its masking (#4694)',
        'player_status_breakdown' => 'its masking (#4694)',
        'player_potential'        => 'its masking (#4694)',
        'prospects'               => 'its masking (#4694)',
        'test_trainings'          => 'its masking (#4694)',
        'trial_cases'             => 'its masking (#4694)',
        'trial_decisions'         => 'its masking (#4694)',
        'scout_history'           => 'its masking (#4694)',
        'scouting_visits_panel'   => 'its masking (#4694)',
        'custom_field_values'     => 'its masking (#4716)',
        'people_directory_panel'  => 'its masking (#4715)',
        'team_roster_panel'       => 'its masking (#4691)',
        'coach_player_list_panel' => 'its masking (#4691)',
        'compare'                 => 'its masking (#4691)',
        'vct'                     => 'its own review',
        'training_plan'           => 'its own review',
        'training_exposure'       => 'its own review',
        'tournaments'             => 'its own review',
        'media'                   => 'its own review',
        'measurements'            => 'its own review',
        'measurement_sessions'    => 'its own review',
        'measurement_definitions' => 'its own review',
    ];

    private string $matrix_was = '';

    /** @var list<array<string,mixed>> */
    private array $tiles_were = [];

    private int $team     = 0;
    private int $player   = 0;
    private int $file     = 0;
    private int $observer = 0;
    private int $coach    = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb, $wp_rest_server;
        $p    = $wpdb->prefix;
        $club = (int) CurrentClub::id();

        $roles = new RolesService();
        $roles->installRoles();
        $roles->ensureCapabilities();
        PdpModule::ensureCapabilities();

        $this->tiles_were = TileRegistry::allRegistered();
        TileRegistry::clear();
        \TT\Shared\CoreSurfaceRegistration::register();

        $config           = new ConfigService();
        $this->matrix_was = (string) $config->get( 'tt_authorization_active', '' );
        $config->set( 'tt_authorization_active', '1' );
        $this->resetMatrixActiveCaches();

        $this->migration()->up();
        $this->flush();

        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $club, 'name' => 'Obsread U15', 'age_group' => 'U15' ] );
        $this->team = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_players", [
            'club_id' => $club, 'team_id' => $this->team,
            'first_name' => 'Obsread', 'last_name' => 'Playerx', 'status' => 'active',
        ] );
        $this->player = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_seasons", [
            'club_id' => $club, 'name' => 'Obsread 2026/27',
            'start_date' => '2026-07-01', 'end_date' => '2027-06-30', 'is_current' => 1,
        ] );
        $season = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_pdp_files", [
            'club_id' => $club, 'player_id' => $this->player, 'season_id' => $season, 'status' => 'open',
        ] );
        $this->file = (int) $wpdb->insert_id;
        $this->assertGreaterThan( 0, $this->file, 'fixture: the PDP file was written' );

        $this->observer = self::factory()->user->create( [ 'role' => 'tt_readonly_observer' ] );
        $wpdb->insert( "{$p}tt_people", [
            'club_id' => $club, 'first_name' => 'Obsread', 'last_name' => 'Observerx',
            'role_type' => 'other', 'wp_user_id' => $this->observer, 'status' => 'active',
        ] );

        $this->coach = self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $wpdb->insert( "{$p}tt_people", [
            'club_id' => $club, 'first_name' => 'Obsread', 'last_name' => 'Coachx',
            'role_type' => 'head_coach', 'wp_user_id' => $this->coach, 'status' => 'active',
        ] );
        $person = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_team_people", [
            'club_id' => $club, 'team_id' => $this->team, 'person_id' => $person,
            'role_in_team' => 'head_coach', 'is_head_coach' => 1,
        ] );
        $wpdb->insert( "{$p}tt_user_role_scopes", [
            'person_id' => $person, 'role_id' => 1, 'scope_type' => 'team', 'scope_id' => $this->team,
        ] );
        $this->flush();
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        ( new ConfigService() )->set( 'tt_authorization_active', $this->matrix_was );
        $this->resetMatrixActiveCaches();
        TileRegistry::clear();
        foreach ( $this->tiles_were as $tile ) {
            TileRegistry::register( $tile );
        }
        $this->flush();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_the_observer_reads_every_granted_entity_and_none_of_the_closed_ones(): void {
        foreach ( array_merge( self::HELD_BEFORE, $this->granted() ) as $entity ) {
            $this->assertTrue(
                MatrixGate::can( $this->observer, $entity, MatrixGate::READ, MatrixGate::SCOPE_GLOBAL ),
                "the observer reads {$entity} academy-wide"
            );
        }
        foreach ( self::CLOSED as $entity ) {
            $this->assertFalse(
                MatrixGate::can( $this->observer, $entity, MatrixGate::READ, MatrixGate::SCOPE_GLOBAL ),
                "{$entity} stays closed to the observer"
            );
        }
    }

    public function test_nothing_arrives_before_its_masking(): void {
        foreach ( self::WITH_THEIR_MASKING as $entity => $issue ) {
            $this->assertFalse(
                MatrixGate::can( $this->observer, $entity, MatrixGate::READ, MatrixGate::SCOPE_GLOBAL ),
                "{$entity} follows with {$issue}, not before"
            );
        }
    }

    public function test_the_seed_and_the_migration_grant_the_same_set(): void {
        $seeded = [];
        foreach ( $this->seed() as $row ) {
            if ( ( $row['persona'] ?? '' ) !== 'readonly_observer' ) continue;
            $seeded[] = (string) $row['entity'];
        }
        $expected = array_merge( self::HELD_BEFORE, $this->granted() );
        sort( $seeded );
        sort( $expected );

        $this->assertSame( $expected, $seeded, 'the seed and 0310::ENTITIES have drifted apart' );
        $this->assertSame( [], array_intersect( $this->granted(), self::CLOSED ) );
        $this->assertSame( [], array_intersect( $this->granted(), array_keys( self::WITH_THEIR_MASKING ) ) );
    }

    public function test_the_observer_has_no_create_change_or_delete_right_anywhere(): void {
        global $wpdb;
        $activities = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT activity FROM {$wpdb->prefix}tt_authorization_matrix WHERE persona = %s",
            'readonly_observer'
        ) );
        $this->assertSame( [ 'read' ], array_values( $activities ) );

        $entities = array_unique( array_map( static fn ( array $r ): string => (string) $r['entity'], $this->seed() ) );
        foreach ( $entities as $entity ) {
            foreach ( [ MatrixGate::CHANGE, MatrixGate::CREATE_DELETE ] as $activity ) {
                $this->assertFalse(
                    MatrixGate::can( $this->observer, $entity, $activity, MatrixGate::SCOPE_GLOBAL ),
                    "the observer may not {$activity} {$entity}"
                );
            }
        }
    }

    public function test_the_migration_adds_each_row_once(): void {
        global $wpdb;
        $table = "{$wpdb->prefix}tt_authorization_matrix";
        foreach ( $this->granted() as $entity ) {
            $wpdb->delete( $table, [ 'persona' => 'readonly_observer', 'entity' => $entity ] );
        }

        $this->migration()->up();
        // Twice, because a re-run must add nothing.
        $this->migration()->up();

        foreach ( $this->granted() as $entity ) {
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT activity, scope_kind, is_default FROM {$table} WHERE persona = %s AND entity = %s",
                'readonly_observer',
                $entity
            ), ARRAY_A );
            $this->assertSame(
                [ [ 'activity' => 'read', 'scope_kind' => 'global', 'is_default' => '1' ] ],
                $rows,
                "one global read row for {$entity}"
            );
        }
    }

    public function test_a_cell_the_academy_changed_keeps_its_value(): void {
        global $wpdb;
        $p     = $wpdb->prefix;
        $table = "{$p}tt_authorization_matrix";
        $log   = "{$p}tt_authorization_changelog";
        foreach ( [ 'methodology', 'seasons', 'exercises' ] as $entity ) {
            $wpdb->delete( $table, [ 'persona' => 'readonly_observer', 'entity' => $entity ] );
        }

        // Edited, then wiped by a reset to the defaults: the edit no longer stands.
        $wpdb->insert( $log, [
            'persona' => 'readonly_observer', 'entity' => 'exercises', 'activity' => 'read',
            'scope_kind' => 'global', 'change_type' => 'revoke', 'actor_user_id' => 1,
            'created_at' => current_time( 'mysql' ),
        ] );
        $wpdb->insert( $log, [
            'persona' => '*', 'entity' => '*', 'activity' => '*', 'scope_kind' => '*',
            'change_type' => 'reset', 'actor_user_id' => 1, 'created_at' => current_time( 'mysql' ),
        ] );
        // Narrowed to team scope through the matrix admin.
        $wpdb->insert( $table, [
            'persona' => 'readonly_observer', 'entity' => 'methodology', 'activity' => 'read',
            'scope_kind' => 'team', 'module_class' => 'x', 'is_default' => 0,
        ] );
        // Granted and then revoked after the reset: no row left, the
        // changelog remembers.
        foreach ( [ 'grant', 'revoke' ] as $type ) {
            $wpdb->insert( $log, [
                'persona' => 'readonly_observer', 'entity' => 'seasons', 'activity' => 'read',
                'scope_kind' => 'global', 'change_type' => $type, 'actor_user_id' => 1,
                'created_at' => current_time( 'mysql' ),
            ] );
        }

        $this->migration()->up();
        $this->flush();

        $this->assertSame(
            [ [ 'scope_kind' => 'team', 'is_default' => '0' ] ],
            $wpdb->get_results( "SELECT scope_kind, is_default FROM {$table} WHERE persona = 'readonly_observer' AND entity = 'methodology'", ARRAY_A ),
            'the narrowed cell stays narrowed'
        );
        $this->assertSame(
            0,
            (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE persona = 'readonly_observer' AND entity = 'seasons'" ),
            'the revoked cell stays revoked'
        );
        $this->assertTrue(
            MatrixGate::can( $this->observer, 'exercises', MatrixGate::READ, MatrixGate::SCOPE_GLOBAL ),
            'a change wiped by a reset does not hold the row back'
        );
    }

    public function test_the_observer_cannot_open_pdp_by_any_route(): void {
        // The capability, through the matrix bridge and on the role itself.
        $this->assertFalse( user_can( $this->observer, 'tt_view_pdp' ) );
        $this->assertFalse( (bool) get_role( 'tt_readonly_observer' )->has_cap( 'tt_view_pdp' ) );
        $this->assertFalse( PdpAccess::canSeeFile( $this->observer, $this->player ) );

        // The views.
        foreach ( [ 'pdp', 'pdp-planning' ] as $slug ) {
            $this->assertFalse( TileRegistry::canAccessViewSlug( $slug, $this->observer ), "tile {$slug}" );
            $this->assertFalse( DashboardShortcode::dispatchAllows( $slug, $this->observer ), "dispatch {$slug}" );
        }

        // The REST routes, with the control beside each refusal.
        $routes = [
            "/talenttrack/v1/players/{$this->player}/pdp",
            '/talenttrack/v1/pdp-files',
            "/talenttrack/v1/pdp-files/{$this->file}",
            "/talenttrack/v1/pdp-files/{$this->file}/verdict",
        ];
        wp_set_current_user( $this->observer );
        foreach ( $routes as $route ) {
            $this->assertSame( 403, $this->status( $route ), "observer: {$route}" );
        }

        wp_set_current_user( $this->coach );
        $this->assertSame( 200, $this->status( "/talenttrack/v1/players/{$this->player}/pdp" ), 'the head coach reads their player\'s plan' );
        $this->assertSame( 200, $this->status( "/talenttrack/v1/pdp-files/{$this->file}" ), 'the head coach opens the file' );
    }

    public function test_a_stale_raw_pdp_grant_is_revoked_with_the_matrix_off(): void {
        $role = get_role( 'tt_readonly_observer' );
        $this->assertNotNull( $role );
        // The grant an install carries from before: on the role, not the user.
        $role->add_cap( 'tt_view_pdp' );

        PdpModule::ensureCapabilities();

        ( new ConfigService() )->set( 'tt_authorization_active', '0' );
        $this->resetMatrixActiveCaches();
        $this->flush();
        $fresh = new \WP_User( $this->observer );

        $this->assertFalse( (bool) get_role( 'tt_readonly_observer' )->has_cap( 'tt_view_pdp' ) );
        $this->assertFalse( $fresh->has_cap( 'tt_view_pdp' ) );
        $this->assertTrue( (bool) get_role( 'tt_coach' )->has_cap( 'tt_view_pdp' ), 'coaches keep it' );
    }

    /**
     * A read grant opens no write. Asked with the observer holding global
     * read on both entities behind the VCT configuration and the training
     * planner, as an academy that widens the seat would leave it.
     */
    public function test_every_bridged_write_gate_refuses_a_reader(): void {
        global $wpdb;
        foreach ( [ 'vct_library', 'training_plan' ] as $entity ) {
            $wpdb->query( $wpdb->prepare(
                "INSERT IGNORE INTO {$wpdb->prefix}tt_authorization_matrix
                   (persona, entity, activity, scope_kind, module_class, is_default)
                 VALUES (%s, %s, 'read', 'global', 'x', 0)",
                'readonly_observer',
                $entity
            ) );
        }
        $this->flush();
        $this->assertTrue( MatrixGate::can( $this->observer, 'training_plan', MatrixGate::READ, MatrixGate::SCOPE_GLOBAL ), 'precondition: the reader reads plans' );

        foreach ( [ 'tt_vct_admin_config', 'tt_vct_admin_library', 'tt_training_plan' ] as $cap ) {
            $this->assertFalse( AuthorizationService::userCanOrMatrix( $this->observer, $cap ), $cap );
        }
        wp_set_current_user( $this->observer );

        ob_start();
        \TT\Modules\Training\Frontend\FrontendTrainingPlansView::render( $this->observer, false );
        $this->assertStringContainsString( 'You do not have permission to view training plans.', (string) ob_get_clean(), 'the planner' );
        ob_start();
        \TT\Modules\Training\Frontend\FrontendTrainingPhotoView::render( $this->observer, false );
        $this->assertStringContainsString( 'You do not have permission to build training plans.', (string) ob_get_clean(), 'photo to plan' );
        $writes = [
            [ 'POST',   '/talenttrack/v1/vct/age-profiles' ],
            [ 'PATCH',  '/talenttrack/v1/vct/age-profiles/1' ],
            [ 'DELETE', '/talenttrack/v1/vct/age-profiles/1' ],
            [ 'PUT',    '/talenttrack/v1/vct/macro-blocks' ],
            [ 'PUT',    '/talenttrack/v1/vct/team-cycles' ],
            [ 'DELETE', '/talenttrack/v1/vct/team-cycles' ],
            [ 'PUT',    '/talenttrack/v1/vct/cycle-weeks' ],
            [ 'POST',   '/talenttrack/v1/training/plans' ],
            [ 'PATCH',  '/talenttrack/v1/training/plans/1' ],
            [ 'DELETE', '/talenttrack/v1/training/plans/1' ],
            [ 'POST',   '/talenttrack/v1/training/plans/generate' ],
            [ 'POST',   '/talenttrack/v1/training/plans/1/duplicate' ],
            [ 'POST',   '/talenttrack/v1/training/plans/1/publish' ],
            [ 'POST',   '/talenttrack/v1/training/runs' ],
            [ 'PATCH',  '/talenttrack/v1/training/runs/1' ],
            [ 'DELETE', '/talenttrack/v1/training/runs/1' ],
            [ 'POST',   '/talenttrack/v1/training/runs/1/observations' ],
            [ 'DELETE', '/talenttrack/v1/training/observations/1' ],
        ];
        foreach ( $writes as [ $method, $route ] ) {
            $status = (int) rest_do_request( new WP_REST_Request( $method, $route ) )->get_status();
            $this->assertSame( 403, $status, "{$method} {$route}" );
        }
    }

    /**
     * Opening the record is not reading its plan: a staff member on the
     * player's own team reads the player and holds no PDP row.
     */
    public function test_a_staff_member_on_the_team_gets_no_family_pdp_projection(): void {
        global $wpdb;
        $p     = $wpdb->prefix;
        $club  = (int) CurrentClub::id();
        $staff = self::factory()->user->create( [ 'role' => 'tt_staff' ] );
        $wpdb->insert( "{$p}tt_people", [
            'club_id' => $club, 'first_name' => 'Obsread', 'last_name' => 'Staffx',
            'role_type' => 'staff', 'wp_user_id' => $staff, 'status' => 'active',
        ] );
        $person = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_team_people", [
            'club_id' => $club, 'team_id' => $this->team, 'person_id' => $person,
            'role_in_team' => 'staff', 'is_head_coach' => 0,
        ] );
        $wpdb->insert( "{$p}tt_user_role_scopes", [
            'person_id' => $person, 'role_id' => 1, 'scope_type' => 'team', 'scope_id' => $this->team,
        ] );
        $this->flush();

        $this->assertTrue( AuthorizationService::canViewPlayer( $staff, $this->player ), 'precondition: staff opens the record' );
        wp_set_current_user( $staff );
        $this->assertSame( 403, $this->status( "/talenttrack/v1/players/{$this->player}/pdp" ) );
    }

    /**
     * Removing an observation asks what writing one asks: its author, or
     * someone who may evaluate that player. The head coach's own run, a
     * guest from another squad on it, a note written by someone else.
     */
    public function test_deleting_an_observation_asks_the_author_or_the_player(): void {
        global $wpdb;
        $p    = $wpdb->prefix;
        $club = (int) CurrentClub::id();

        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $club, 'name' => 'Obsread Guest U15', 'age_group' => 'U15' ] );
        $other_team = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_players", [
            'club_id' => $club, 'team_id' => $other_team,
            'first_name' => 'Obsread', 'last_name' => 'Guestx', 'status' => 'active',
        ] );
        $guest = (int) $wpdb->insert_id;

        $plan = ( new \TT\Modules\Training\Repositories\TrainingPlansRepository() )->create( [
            'club_id' => $club, 'team_id' => $this->team, 'title' => 'Obsread plan',
        ] );
        $wpdb->insert( "{$p}tt_activities", [
            'club_id' => $club, 'team_id' => $this->team,
            'session_date' => '2026-08-23', 'activity_type_key' => 'training',
        ] );
        $run = ( new \TT\Modules\Training\Repositories\TrainingPlanRunsRepository() )->attach(
            (int) $plan, (int) $wpdb->insert_id, $this->team, '2026-08-23'
        );
        $this->assertGreaterThan( 0, $run, 'fixture: the run was attached' );

        $author = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $repo   = new \TT\Modules\Training\Repositories\TrainingObservationsRepository();
        wp_set_current_user( $author );
        $on_guest = $repo->create( [ 'run_id' => $run, 'player_id' => $guest, 'note' => 'Obsread guest note.' ] );
        $on_own   = $repo->create( [ 'run_id' => $run, 'player_id' => $this->player, 'note' => 'Obsread own note.' ] );
        $this->assertGreaterThan( 0, $on_guest, 'fixture: the guest observation was written' );
        $this->assertGreaterThan( 0, $on_own, 'fixture: the squad observation was written' );

        wp_set_current_user( $this->coach );
        $this->assertFalse( AuthorizationService::canEvaluatePlayer( $this->coach, $guest ), 'precondition: the guest is not the coach\'s player' );
        $this->assertSame( 404, $this->deleteStatus( $on_guest ), 'somebody else\'s note on another squad\'s child' );
        $this->assertSame( 200, $this->deleteStatus( $on_own ), 'a note on the coach\'s own player' );

        wp_set_current_user( $this->coach );
        $mine = $repo->create( [ 'run_id' => $run, 'player_id' => $guest, 'note' => 'Obsread my note.' ] );
        $this->assertSame( 200, $this->deleteStatus( $mine ), 'the author removes their own note' );
    }

    private function deleteStatus( int $observation_id ): int {
        return (int) rest_do_request( new WP_REST_Request( 'DELETE', "/talenttrack/v1/training/observations/{$observation_id}" ) )->get_status();
    }

    /** @return list<string> */
    private function granted(): array {
        $migration = $this->migration();
        return array_values( (array) constant( get_class( $migration ) . '::ENTITIES' ) );
    }

    /** @return list<array<string,string>> */
    private function seed(): array {
        $rows = require dirname( __DIR__, 2 ) . '/config/authorization_seed.php';
        $this->assertIsArray( $rows );
        return $rows;
    }

    private function migration(): object {
        return require dirname( __DIR__, 2 ) . self::MIGRATION;
    }

    private function status( string $route ): int {
        return (int) rest_do_request( new WP_REST_Request( 'GET', $route ) )->get_status();
    }

    private function flush(): void {
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
    }

    private function resetMatrixActiveCaches(): void {
        foreach ( [ TileRegistry::class, DashboardShortcode::class ] as $class ) {
            $prop = new \ReflectionProperty( $class, 'matrix_active_cache' );
            $prop->setAccessible( true );
            $prop->setValue( null, null );
        }
    }
}
