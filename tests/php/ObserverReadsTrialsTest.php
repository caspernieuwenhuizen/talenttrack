<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Config\ConfigService;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Authorization\MatrixGate;
use TT\Modules\License\LicenseGate;
use TT\Modules\Trials\Repositories\TrialCaseStaffRepository;
use TT\Modules\Trials\Security\TrialCaseAccessPolicy;
use TT\Shared\Frontend\DashboardShortcode;
use TT\Shared\Frontend\FrontendTrialCaseView;
use TT\Shared\Tiles\TileRegistry;

/**
 * #4694 (b) — the read-only observer lists and opens every trial case,
 * read-only, with the free text masked; the synthesis stays with the
 * panel. A scout and a head coach list exactly what they listed before.
 */
final class ObserverReadsTrialsTest extends WP_UnitTestCase {

    private const MIGRATION = '/database/migrations/0333_authorization_observer_reads_trials.php';

    private const SUMMARY = 'Quick in the turn, needs a stronger first touch.';
    private const INPUT   = 'Read the game early in every drill.';
    private const REASON  = 'Second look at the regional tournament.';

    private string $p;
    private string $matrix_was = '';
    private int $club;
    private int $observer = 0;
    private int $scout    = 0;
    private int $coach    = 0;
    private int $panellist = 0;
    private int $team     = 0;
    private int $case_on_team  = 0;
    private int $case_linked   = 0;
    private int $case_elsewhere = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->p    = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();

        $roles = new RolesService();
        $roles->installRoles();
        $roles->ensureCapabilities();

        $config           = new ConfigService();
        $this->matrix_was = (string) $config->get( 'tt_authorization_active', '' );
        $config->set( 'tt_authorization_active', '1' );
        $this->resetMatrixActiveCaches();
        $this->migration()->up();

        $wpdb->insert( "{$this->p}tt_teams", [ 'club_id' => $this->club, 'name' => 'Obstrial U14', 'age_group' => 'U14' ] );
        $this->team = (int) $wpdb->insert_id;
        $wpdb->insert( "{$this->p}tt_trial_tracks", [ 'club_id' => $this->club, 'name' => 'Standard' ] );
        $track = (int) $wpdb->insert_id;

        $on_team   = $this->player( 'Teamx', $this->team );
        $linked    = $this->player( 'Linkx', 0 );
        $elsewhere = $this->player( 'Farx', 0 );
        $this->case_on_team   = $this->trialCase( $on_team, $track );
        $this->case_linked    = $this->trialCase( $linked, $track );
        $this->case_elsewhere = $this->trialCase( $elsewhere, $track );

        $this->observer  = self::factory()->user->create( [ 'role' => 'tt_readonly_observer' ] );
        $this->scout     = self::factory()->user->create( [ 'role' => 'tt_scout' ] );
        $this->panellist = self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        update_user_meta( $this->scout, 'tt_scout_player_ids', wp_json_encode( [ $linked ] ) );
        $this->coach = $this->headCoach( $this->team );

        ( new TrialCaseStaffRepository() )->assign( $this->case_elsewhere, $this->panellist, 'Panel' );
        $wpdb->insert( "{$this->p}tt_trial_case_staff_inputs", [
            'club_id'         => $this->club,
            'case_id'         => $this->case_elsewhere,
            'user_id'         => $this->panellist,
            'overall_rating'  => 7,
            'free_text_notes' => self::INPUT,
            'submitted_at'    => '2026-09-20 10:00:00',
            'released_at'     => '2026-09-21 10:00:00',
        ] );
        $wpdb->insert( "{$this->p}tt_trial_extensions", [
            'club_id'           => $this->club,
            'case_id'           => $this->case_elsewhere,
            'extended_at'       => '2026-09-25 10:00:00',
            'previous_end_date' => '2026-10-01',
            'new_end_date'      => '2026-10-15',
            'justification'     => self::REASON,
            'extended_by'       => 1,
        ] );
        $this->flush();
    }

    public function tear_down(): void {
        ( new ConfigService() )->set( 'tt_authorization_active', $this->matrix_was );
        $this->resetMatrixActiveCaches();
        $this->flush();
        unset( $_GET['tt_view'], $_GET['id'], $_GET['tab'] );
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_the_observer_lists_and_opens_every_case_read_only(): void {
        $this->assertTrue( TrialCaseAccessPolicy::readsEveryCase( $this->observer ) );
        $this->assertNull( TrialCaseAccessPolicy::visibleCaseFilter( $this->observer ), 'every case' );
        foreach ( [ $this->case_on_team, $this->case_linked, $this->case_elsewhere ] as $case ) {
            $this->assertTrue( TrialCaseAccessPolicy::canOpenCase( $this->observer, $case ) );
            $this->assertFalse( TrialCaseAccessPolicy::canViewSynthesis( $this->observer, $case ), 'the synthesis stays with the panel' );
            $this->assertFalse( TrialCaseAccessPolicy::canManageCase( $this->observer, $case ) );
        }
        $this->assertFalse( AuthorizationService::userCanOrMatrix( $this->observer, 'tt_manage_trials' ) );
        $this->assertFalse( TrialCaseAccessPolicy::seesCaseText( $this->observer, $this->case_elsewhere ) );
    }

    public function test_a_scout_and_a_head_coach_list_what_they_listed_before(): void {
        $this->assertFalse( TrialCaseAccessPolicy::readsEveryCase( $this->scout ) );
        $this->assertFalse( TrialCaseAccessPolicy::readsEveryCase( $this->coach ) );

        $scout = (array) TrialCaseAccessPolicy::visibleCaseFilter( $this->scout );
        sort( $scout );
        $this->assertSame( [ $this->case_linked ], $scout, 'the scout: the linked player only' );

        $coach = (array) TrialCaseAccessPolicy::visibleCaseFilter( $this->coach );
        sort( $coach );
        $this->assertSame( [ $this->case_on_team ], $coach, 'the head coach: their own team only' );

        $this->assertFalse( TrialCaseAccessPolicy::canOpenCase( $this->scout, $this->case_elsewhere ) );
        $this->assertFalse( TrialCaseAccessPolicy::canOpenCase( $this->coach, $this->case_elsewhere ) );
        $this->assertTrue( TrialCaseAccessPolicy::seesCaseText( $this->scout, $this->case_linked ), 'unchanged for the scout' );
        $this->assertTrue( TrialCaseAccessPolicy::seesCaseText( $this->panellist, $this->case_elsewhere ), 'and for the panel' );
    }

    public function test_the_case_page_masks_the_text_and_keeps_the_rating(): void {
        $overview = $this->render( $this->observer, null );
        $this->assertStringNotContainsString( self::SUMMARY, $overview );
        $this->assertStringNotContainsString( self::REASON, $overview );
        $this->assertStringContainsString( 'tt-masked-value', $overview );
        $this->assertStringNotContainsString( 'tab=execution', $overview, 'no Execution tab' );
        $this->assertStringNotContainsString( 'tab=decision', $overview, 'no Decision tab' );
        $this->assertStringNotContainsString( 'tab=letter', $overview, 'no Letter tab' );

        $inputs = $this->render( $this->observer, 'inputs' );
        $this->assertStringNotContainsString( self::INPUT, $inputs );
        $this->assertStringContainsString( '<strong>7.00</strong>', $inputs, 'the rating shows' );

        $hod   = self::factory()->user->create( [ 'role' => 'tt_head_dev' ] );
        $staff = $this->render( $hod, 'inputs' );
        $this->assertStringContainsString( self::INPUT, $staff, 'a trial manager reads the text' );
        $this->assertStringContainsString( self::SUMMARY, $this->render( $hod, null ) );
    }

    public function test_the_migration_adds_the_rows_once(): void {
        global $wpdb;
        $table = "{$this->p}tt_authorization_matrix";
        $wpdb->query( "DELETE FROM {$table} WHERE persona = 'readonly_observer' AND entity LIKE 'trial\\_%'" );
        $this->flush();

        $this->migration()->up();
        $this->migration()->up();

        $rows = $wpdb->get_col( "SELECT entity FROM {$table} WHERE persona = 'readonly_observer' AND entity LIKE 'trial\\_%' AND activity = 'read' AND scope_kind = 'global' ORDER BY entity" );
        $this->assertSame( [ 'trial_cases', 'trial_decisions', 'trial_inputs', 'trial_letters_generated', 'trial_synthesis' ], array_values( $rows ) );
        $this->flush();
        $this->assertTrue( MatrixGate::can( $this->observer, 'trial_cases', MatrixGate::READ, MatrixGate::SCOPE_GLOBAL ) );
    }

    private function render( int $user_id, ?string $tab ): string {
        if ( class_exists( LicenseGate::class ) && ! LicenseGate::allows( 'trial_module' ) ) {
            $this->markTestSkipped( 'Trials is out of plan in this install; the view renders the upgrade panel.' );
        }
        wp_set_current_user( $user_id );
        $_GET['tt_view'] = 'trial-case';
        $_GET['id']      = (string) $this->case_elsewhere;
        unset( $_GET['tab'] );
        if ( $tab !== null ) $_GET['tab'] = $tab;
        ob_start();
        FrontendTrialCaseView::render( $user_id, false );
        return (string) ob_get_clean();
    }

    private function player( string $last, int $team ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_players", [
            'club_id' => $this->club, 'first_name' => 'Trial', 'last_name' => $last,
            'team_id' => $team, 'status' => 'trial',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function trialCase( int $player, int $track ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_trial_cases", [
            'club_id' => $this->club, 'player_id' => $player, 'track_id' => $track,
            'start_date' => '2026-09-01', 'end_date' => '2026-10-01', 'status' => 'open',
            'notes' => self::SUMMARY, 'uuid' => wp_generate_uuid4(),
        ] );
        return (int) $wpdb->insert_id;
    }

    private function headCoach( int $team ): int {
        global $wpdb;
        $coach = self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $wpdb->insert( "{$this->p}tt_people", [
            'club_id' => $this->club, 'first_name' => 'Obstrial', 'last_name' => 'Coachx',
            'role_type' => 'head_coach', 'wp_user_id' => $coach, 'status' => 'active',
        ] );
        $person = (int) $wpdb->insert_id;
        $wpdb->insert( "{$this->p}tt_team_people", [
            'club_id' => $this->club, 'team_id' => $team, 'person_id' => $person,
            'role_in_team' => 'head_coach', 'is_head_coach' => 1,
        ] );
        $wpdb->insert( "{$this->p}tt_user_role_scopes", [
            'person_id' => $person, 'role_id' => 1, 'scope_type' => 'team', 'scope_id' => $team,
        ] );
        return $coach;
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
