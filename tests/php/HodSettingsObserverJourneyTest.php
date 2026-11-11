<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Core\FeatureRegistry;
use TT\Infrastructure\Config\ConfigService;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\REST\ConfigRestController;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Authorization\MatrixGate;
use TT\Shared\Configuration\SettingsRegistry;
use TT\Shared\Frontend\DashboardShortcode;
use TT\Shared\Tiles\TileRegistry;

/**
 * #4795 — the head of development reads settings and changes none of them;
 * the read-only observer reads the player journey and cohort transitions.
 *
 * Real personas on a matrix-active install: an account on the role and a
 * `tt_people` row, no capability added to any user. The rows come from the
 * migration, as on an upgraded install.
 */
final class HodSettingsObserverJourneyTest extends WP_UnitTestCase {

    private const MIGRATION = '/database/migrations/0312_authorization_hod_settings_observer_journey.php';
    private const VERDICT   = 'PDP verdict: promote';
    private const POSITION  = 'Moved to right back.';
    private const TRIAL     = 'Trial ended: hodset decision';
    private const SUSPEND   = 'Suspended: hodset reason';
    private const INJURY    = 'Medical: hodset ankle';
    private const WELFARE   = 'Safeguarding: hodset welfare';

    private string $matrix_was = '';

    /** @var list<array<string,mixed>> */
    private array $tiles_were = [];

    private int $team     = 0;
    private int $player   = 0;
    private int $hod      = 0;
    private int $observer = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb, $wp_rest_server;

        $roles = new RolesService();
        $roles->installRoles();
        $roles->ensureCapabilities();

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

        $club = (int) CurrentClub::id();
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => $club, 'name' => 'Hodset U16', 'age_group' => 'U16' ] );
        $this->team = (int) $wpdb->insert_id;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id' => $club, 'team_id' => $this->team, 'first_name' => 'Hodset', 'last_name' => 'Playerx', 'status' => 'active',
        ] );
        $this->player = (int) $wpdb->insert_id;

        $events = [
            [ 'pdp_verdict_recorded', '2026-06-20 09:00:00', self::VERDICT, '{"pdp_file_id":1,"decision":"promote"}', 'public' ],
            [ 'position_changed', '2026-06-10 09:00:00', self::POSITION, '{}', 'public' ],
            [ 'trial_ended', '2026-06-12 09:00:00', self::TRIAL, '{"trial_case_id":1,"decision":"admit","context":"post_trial"}', 'public' ],
            [ 'suspension_started', '2026-06-14 09:00:00', self::SUSPEND, '{}', 'public' ],
            [ 'injury_started', '2026-06-16 09:00:00', self::INJURY, '{}', 'medical' ],
            [ 'note_added', '2026-06-18 09:00:00', self::WELFARE, '{}', 'safeguarding' ],
        ];
        foreach ( $events as $i => [ $type, $when, $summary, $payload, $visibility ] ) {
            $wpdb->insert( "{$wpdb->prefix}tt_player_events", [
                'club_id' => $club, 'uuid' => wp_generate_uuid4(), 'player_id' => $this->player,
                'event_type' => $type, 'event_date' => $when, 'summary' => $summary, 'payload' => $payload,
                'visibility' => $visibility, 'source_module' => 'tests', 'source_entity_type' => 'test_event',
                'source_entity_id' => $i + 1,
            ] );
        }

        $this->hod      = $this->persona( 'tt_head_dev', 'head_of_development' );
        $this->observer = $this->persona( 'tt_readonly_observer', 'other' );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        $_GET = [];
        FeatureRegistry::setEnabled( 'cohort_transitions', false );
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

    public function test_the_head_of_development_reads_configuration_and_changes_none_of_it(): void {
        $this->assertTrue( user_can( $this->hod, 'tt_view_settings' ), 'HoD reads settings' );
        $this->assertFalse( user_can( $this->hod, 'tt_edit_settings' ), 'HoD edits no setting' );
        $this->assertFalse( user_can( $this->hod, 'tt_manage_authorization' ), 'the access matrix stays closed' );

        $entries = SettingsRegistry::entriesFor( $this->hod );
        $this->assertNotEmpty( $entries, 'HoD sees settings entries' );
        $this->assertNotContains( 'system', array_column( $entries, 'section' ), 'the System section stays closed' );
        $this->assertNotContains( 'matrix', array_column( $entries, 'key' ), 'the access matrix entry stays closed' );

        // A general setting, gated on the settings edit right, is not saved.
        $key = $this->generalSettingKey();
        QueryHelpers::set_config( $key, 'before' );
        wp_set_current_user( $this->hod );
        $request = new WP_REST_Request( 'POST', '/talenttrack/v1/config' );
        $request->set_param( 'config', [ $key => 'after' ] );
        rest_do_request( $request );
        $this->assertSame( 'before', QueryHelpers::get_config( $key, '' ), "HoD saved {$key}" );
    }

    public function test_the_observer_reads_the_journey_without_pdp_verdict_entries(): void {
        $this->assertTrue( MatrixGate::can( $this->observer, 'player_timeline', 'read', 'global' ) );

        wp_set_current_user( $this->observer );
        $response = rest_do_request( new WP_REST_Request( 'GET', "/talenttrack/v1/players/{$this->player}/timeline" ) );
        $this->assertSame( 200, (int) $response->get_status() );

        $data      = (array) $response->get_data();
        $summaries = array_map( static fn ( $e ): string => (string) ( ( (array) $e )['summary'] ?? '' ), (array) ( $data['data']['events'] ?? [] ) );
        $this->assertContains( self::POSITION, $summaries, 'the journey is read' );
        $this->assertNotContains( self::VERDICT, $summaries, 'without the PDP verdict entry' );
    }

    public function test_the_observer_reads_cohort_transitions(): void {
        FeatureRegistry::setEnabled( 'cohort_transitions', true );
        $this->flush();

        wp_set_current_user( $this->observer );
        $request = new WP_REST_Request( 'GET', '/talenttrack/v1/journey/cohort-transitions' );
        $request->set_param( 'event_type', 'position_changed' );
        $request->set_param( 'from', '2026-06-01 00:00:00' );
        $request->set_param( 'to', '2026-06-30 23:59:59' );
        $response = rest_do_request( $request );

        $this->assertSame( 200, (int) $response->get_status() );
        $data = (array) $response->get_data();
        $this->assertContains( self::POSITION, array_column( (array) ( $data['data']['rows'] ?? [] ), 'summary' ) );
    }

    /**
     * A journey entry follows the read right of the record it describes: the
     * observer, who reads the journey and (#4694 b) the trials but not
     * suspensions, gets the trial entry and not the suspension one; a head
     * coach of the team, who reads both, gets both.
     */
    public function test_trial_and_suspension_entries_follow_their_own_read_right(): void {
        wp_set_current_user( $this->observer );
        $observer_types = $this->timelineTypes();
        $this->assertContains( 'position_changed', $observer_types, 'the journey is read' );
        $this->assertContains( 'trial_ended', $observer_types, 'the trial entry follows the trial read' );
        $this->assertNotContains( 'suspension_started', $observer_types, 'no suspension entry without the suspension read' );

        $coach = $this->headCoach();
        foreach ( [ 'trial_cases', 'player_suspensions' ] as $entity ) {
            $this->assertTrue( AuthorizationService::canReadPlayerSection( $coach, $this->player, $entity ), "precondition: the head coach reads {$entity}" );
        }
        wp_set_current_user( $coach );
        $coach_types = $this->timelineTypes();
        $this->assertContains( 'trial_ended', $coach_types, 'the head coach gets the trial entry' );
        $this->assertContains( 'suspension_started', $coach_types, 'the head coach gets the suspension entry' );
    }

    /** Seeded medical and safeguarding entries never reach the observer. */
    public function test_medical_and_safeguarding_entries_are_absent_for_the_observer(): void {
        wp_set_current_user( $this->observer );

        $timeline = $this->timelineTypes();
        foreach ( [ 'injury_started', 'note_added', 'suspension_started' ] as $type ) {
            $this->assertNotContains( $type, $timeline, "timeline: {$type}" );
        }

        FeatureRegistry::setEnabled( 'cohort_transitions', true );
        $this->flush();
        // #4694 (b) — trial entries follow the trial read, which the observer holds.
        foreach ( [ 'injury_started', 'note_added', 'suspension_started', 'pdp_verdict_recorded' ] as $type ) {
            $request = new WP_REST_Request( 'GET', '/talenttrack/v1/journey/cohort-transitions' );
            $request->set_param( 'event_type', $type );
            $request->set_param( 'from', '2026-06-01 00:00:00' );
            $request->set_param( 'to', '2026-06-30 23:59:59' );
            $response = rest_do_request( $request );
            $this->assertSame( 200, (int) $response->get_status(), "precondition: the cohort answers for {$type}" );
            $data = (array) $response->get_data();
            $this->assertSame( [], array_values( (array) ( $data['data']['rows'] ?? [] ) ), "cohort: no {$type} row for the observer" );
        }
    }

    /** @return list<string> the event types on the current reader's timeline */
    private function timelineTypes(): array {
        $response = rest_do_request( new WP_REST_Request( 'GET', "/talenttrack/v1/players/{$this->player}/timeline" ) );
        $this->assertSame( 200, (int) $response->get_status() );
        $data = (array) $response->get_data();
        return array_values( array_map( static fn ( $e ): string => (string) ( ( (array) $e )['event_type'] ?? '' ), (array) ( $data['data']['events'] ?? [] ) ) );
    }

    private function headCoach(): int {
        global $wpdb;
        $club = (int) CurrentClub::id();
        $uid  = self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $wpdb->insert( "{$wpdb->prefix}tt_people", [
            'club_id' => $club, 'first_name' => 'Hodset', 'last_name' => 'Coach' . $uid,
            'role_type' => 'head_coach', 'wp_user_id' => $uid, 'status' => 'active',
        ] );
        $person = (int) $wpdb->insert_id;
        $wpdb->insert( "{$wpdb->prefix}tt_team_people", [
            'club_id' => $club, 'team_id' => $this->team, 'person_id' => $person,
            'role_in_team' => 'head_coach', 'is_head_coach' => 1,
        ] );
        $wpdb->insert( "{$wpdb->prefix}tt_user_role_scopes", [
            'person_id' => $person, 'role_id' => 1, 'scope_type' => 'team', 'scope_id' => $this->team,
        ] );
        $this->flush();
        return $uid;
    }

    public function test_the_migration_adds_each_row_once_and_read_only(): void {
        global $wpdb;
        $table = "{$wpdb->prefix}tt_authorization_matrix";
        foreach ( $this->cells() as [ $persona, $entity ] ) {
            $wpdb->delete( $table, [ 'persona' => $persona, 'entity' => $entity ] );
        }

        $this->migration()->up();
        // Twice, because a re-run must add nothing.
        $this->migration()->up();

        foreach ( $this->cells() as [ $persona, $entity ] ) {
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT activity, scope_kind, is_default FROM {$table} WHERE persona = %s AND entity = %s",
                $persona,
                $entity
            ), ARRAY_A );
            $this->assertSame(
                [ [ 'activity' => 'read', 'scope_kind' => 'global', 'is_default' => '1' ] ],
                $rows,
                "one global read row for {$persona} on {$entity}"
            );
        }
    }

    public function test_a_cell_the_academy_changed_keeps_its_value(): void {
        global $wpdb;
        $table = "{$wpdb->prefix}tt_authorization_matrix";
        $log   = "{$wpdb->prefix}tt_authorization_changelog";
        foreach ( $this->cells() as [ $persona, $entity ] ) {
            $wpdb->delete( $table, [ 'persona' => $persona, 'entity' => $entity ] );
        }

        // The observer's journey, revoked through the matrix admin: no row
        // left, the changelog remembers.
        foreach ( [ 'grant', 'revoke' ] as $type ) {
            $wpdb->insert( $log, [
                'persona' => 'readonly_observer', 'entity' => 'player_timeline', 'activity' => 'read',
                'scope_kind' => 'global', 'change_type' => $type, 'actor_user_id' => 1,
                'created_at' => current_time( 'mysql' ),
            ] );
        }
        // The observer's cohort, narrowed to team scope.
        $wpdb->insert( $table, [
            'persona' => 'readonly_observer', 'entity' => 'cohort_transitions', 'activity' => 'read',
            'scope_kind' => 'team', 'module_class' => 'x', 'is_default' => 0,
        ] );

        $this->migration()->up();
        $this->flush();

        $this->assertSame(
            0,
            (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE persona = 'readonly_observer' AND entity = 'player_timeline'" ),
            'the revoked cell stays revoked'
        );
        $this->assertSame(
            [ [ 'scope_kind' => 'team', 'is_default' => '0' ] ],
            $wpdb->get_results( "SELECT scope_kind, is_default FROM {$table} WHERE persona = 'readonly_observer' AND entity = 'cohort_transitions'", ARRAY_A ),
            'the narrowed cell stays narrowed'
        );
        $this->assertTrue( MatrixGate::can( $this->hod, 'settings', 'read', 'global' ), 'an untouched cell is added' );
    }

    public function test_neither_persona_gains_a_write(): void {
        global $wpdb;
        $this->assertSame(
            0,
            (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}tt_authorization_matrix WHERE persona = %s AND entity = %s AND activity <> 'read'",
                'head_of_development',
                'settings'
            ) ),
            'HoD holds no settings write'
        );
        $this->assertSame(
            [ 'read' ],
            array_values( (array) $wpdb->get_col( $wpdb->prepare(
                "SELECT DISTINCT activity FROM {$wpdb->prefix}tt_authorization_matrix WHERE persona = %s",
                'readonly_observer'
            ) ) ),
            'the observer reads only'
        );
    }

    /** A key `POST /config` gates on the settings edit right itself. */
    private function generalSettingKey(): string {
        $ref     = new \ReflectionClass( ConfigRestController::class );
        $allowed = (array) $ref->getReflectionConstant( 'ALLOWED_KEYS' )->getValue();
        $areas   = (array) $ref->getReflectionConstant( 'KEY_AREA_MAP' )->getValue();
        foreach ( $allowed as $key ) {
            $key = (string) $key;
            if ( isset( $areas[ $key ] ) || strpos( $key, 'persona_dashboard.' ) === 0 ) continue;
            if ( in_array( $key, [ 'profile_cards_hidden', 'comms_templates_disabled' ], true ) ) continue;
            return $key;
        }
        $this->fail( 'fixture: no general settings key found' );
    }

    /** @return list<array{0:string,1:string}> */
    private function cells(): array {
        $migration = $this->migration();
        return array_values( (array) constant( get_class( $migration ) . '::CELLS' ) );
    }

    private function persona( string $role, string $role_type ): int {
        global $wpdb;
        $uid = self::factory()->user->create( [ 'role' => $role ] );
        $wpdb->insert( "{$wpdb->prefix}tt_people", [
            'club_id' => (int) CurrentClub::id(), 'first_name' => 'Hodset', 'last_name' => 'Persona' . $uid,
            'role_type' => $role_type, 'wp_user_id' => $uid, 'status' => 'active',
        ] );
        $this->flush();
        return $uid;
    }

    private function migration(): object {
        return require dirname( __DIR__, 2 ) . self::MIGRATION;
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
