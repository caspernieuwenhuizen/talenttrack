<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Config\ConfigService;
use TT\Infrastructure\Journey\InjuryRepository;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Activities\Services\AttendanceDateRule;
use TT\Modules\Authorization\FunctionalRoleGrants;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Journey\JourneyModule;
use TT\Modules\Journey\Workflow\InjuryRecoveryDueTemplate;
use TT\Modules\Journey\Workflow\InjuryRecoveryTasks;
use TT\Modules\Workflow\TaskStatus;

/**
 * #4246 / #4269 — one recovery reminder per player, on the workflow
 * chokepoint (#4287): due on the record's check date, to the team's physio
 * and head coach (the head coach alone without a physio), skipped when the
 * date moves or the record is closed, and created by the daily sweep for a
 * record whose date arrives without an edit.
 */
final class InjuryRecoveryReminderTest extends WP_UnitTestCase {

    private int $club   = 0;
    private int $team   = 0;
    private int $player = 0;
    private int $coach  = 0;
    private string $today = '';

    public function set_up(): void {
        parent::set_up();
        $this->club  = (int) CurrentClub::id();
        $this->today = AttendanceDateRule::today();

        $roles = new RolesService();
        $roles->installRoles();
        $roles->ensureCapabilities();
        JourneyModule::ensureCapabilities();
        JourneyModule::registerWorkflowTemplates();
        MatrixRepository::clearCache();
        FunctionalRoleGrants::clearCache();
        AuthorizationService::flushCache();

        global $wpdb;
        $wpdb->hide_errors();
        $this->team   = $this->insertTeam( 'JO10-1' );
        $this->player = $this->insertPlayer( $this->team );
        $this->coach  = $this->makeHeadCoach( $this->team );

        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
    }

    public function tear_down(): void {
        wp_set_current_user( 0 );
        MatrixRepository::clearCache();
        FunctionalRoleGrants::clearCache();
        parent::tear_down();
    }

    public function test_a_return_due_today_reaches_the_physio_and_the_head_coach(): void {
        $physio = $this->makePhysio( $this->team );

        ( new InjuryRepository() )->create( [
            'player_id'       => $this->player,
            'started_on'      => $this->day( -10 ),
            'expected_return' => $this->today,
        ] );

        $tasks = $this->active();
        $assignees = array_map( static fn ( array $t ): int => (int) $t['assignee_user_id'], $tasks );
        sort( $assignees );
        $expected = [ $this->coach, $physio ];
        sort( $expected );

        $this->assertSame( $expected, $assignees );
        foreach ( $tasks as $task ) {
            $this->assertSame( $this->today . ' 00:00:00', (string) $task['due_at'] );
        }
    }

    public function test_without_a_physio_the_head_coach_alone_is_asked(): void {
        ( new InjuryRepository() )->create( [
            'player_id'       => $this->player,
            'started_on'      => $this->day( -10 ),
            'expected_return' => $this->day( -1 ),
        ] );

        $tasks = $this->active();
        $this->assertCount( 1, $tasks );
        $this->assertSame( $this->coach, (int) $tasks[0]['assignee_user_id'] );
    }

    public function test_a_date_still_ahead_creates_nothing_yet(): void {
        ( new InjuryRepository() )->create( [
            'player_id'       => $this->player,
            'started_on'      => $this->day( -2 ),
            'expected_return' => $this->day( 5 ),
        ] );

        $this->assertSame( [], $this->all() );
    }

    public function test_moving_the_date_supersedes_the_old_task(): void {
        $repo = new InjuryRepository();
        $id   = $repo->create( [
            'player_id'       => $this->player,
            'started_on'      => $this->day( -20 ),
            'expected_return' => $this->day( -2 ),
        ] );
        $first = $this->active();
        $this->assertCount( 1, $first );

        // Extended into the future: the old reminder goes, none yet for the new date.
        $repo->update( $id, [ 'expected_return' => $this->day( 10 ) ] );
        $this->assertSame( [], $this->active() );
        $this->assertSame( TaskStatus::SKIPPED, $this->status( (int) $first[0]['id'] ) );

        // Moved to a day that has arrived: a new reminder on that day.
        $repo->update( $id, [ 'expected_return' => $this->day( -1 ) ] );
        $now = $this->active();
        $this->assertCount( 1, $now );
        $this->assertSame( $this->day( -1 ) . ' 00:00:00', (string) $now[0]['due_at'] );
    }

    public function test_an_edit_that_keeps_the_date_creates_no_second_task(): void {
        $repo = new InjuryRepository();
        $id   = $repo->create( [
            'player_id'       => $this->player,
            'started_on'      => $this->day( -20 ),
            'expected_return' => $this->day( -2 ),
        ] );
        $repo->update( $id, [ 'notes' => 'Seen by the physio.' ] );
        $repo->update( $id, [ 'notes' => 'Seen again.' ] );

        $this->assertCount( 1, $this->all() );
    }

    public function test_closing_the_record_skips_the_task(): void {
        $repo = new InjuryRepository();
        $id   = $repo->create( [
            'player_id'       => $this->player,
            'started_on'      => $this->day( -20 ),
            'expected_return' => $this->day( -2 ),
        ] );
        $task = $this->active()[0];

        $repo->update( $id, [ 'actual_return' => $this->today ] );

        $this->assertSame( [], $this->active() );
        $this->assertSame( TaskStatus::SKIPPED, $this->status( (int) $task['id'] ) );
    }

    public function test_the_daily_sweep_reminds_about_an_incomplete_record_once(): void {
        global $wpdb;
        // Written without the repository, so no event fires: only the sweep can find it.
        $wpdb->insert( "{$wpdb->prefix}tt_player_injuries", [
            'club_id'    => $this->club,
            'player_id'  => $this->player,
            'started_on' => $this->day( -20 ),
            'notes'      => 'ankle',
        ] );
        $this->assertSame( [], $this->all() );

        $this->assertGreaterThanOrEqual( 1, InjuryRecoveryTasks::sweep() );
        $tasks = $this->active();
        $this->assertCount( 1, $tasks );
        $this->assertSame( $this->day( -6 ) . ' 00:00:00', (string) $tasks[0]['due_at'], 'due the day it became incomplete' );

        $this->assertSame( 0, InjuryRecoveryTasks::sweep(), 'once a day' );

        ( new ConfigService() )->set( InjuryRecoveryTasks::LAST_SWEEP_KEY, '' );
        InjuryRecoveryTasks::sweep();
        $this->assertCount( 1, $this->all(), 'a second run on another day creates nothing new' );
    }

    public function test_the_task_carries_no_injury_detail(): void {
        ( new InjuryRepository() )->create( [
            'player_id'       => $this->player,
            'started_on'      => $this->day( -10 ),
            'expected_return' => $this->day( -1 ),
            'notes'           => 'ankle',
        ] );
        $template = new InjuryRecoveryDueTemplate();
        $json     = (string) wp_json_encode( [ $this->all(), $template->name(), $template->description() ] );
        foreach ( [ 'ankle', 'expected_return', 'body_part', 'severity' ] as $leak ) {
            $this->assertStringNotContainsString( $leak, $json );
        }
    }

    // ---- fixtures ------------------------------------------------------

    private function day( int $offset ): string {
        return gmdate( 'Y-m-d', (int) strtotime( $this->today . ' 00:00:00 UTC ' . ( $offset >= 0 ? '+' : '' ) . $offset . ' days' ) );
    }

    /** @return list<array<string, mixed>> */
    private function all(): array {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tt_workflow_tasks WHERE template_key = %s AND player_id = %d ORDER BY id ASC",
            InjuryRecoveryDueTemplate::KEY,
            $this->player
        ), ARRAY_A );
        return is_array( $rows ) ? array_values( $rows ) : [];
    }

    /** @return list<array<string, mixed>> */
    private function active(): array {
        return array_values( array_filter(
            $this->all(),
            static fn ( array $t ): bool => TaskStatus::isActionable( (string) $t['status'] )
        ) );
    }

    private function status( int $task_id ): string {
        global $wpdb;
        return (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT status FROM {$wpdb->prefix}tt_workflow_tasks WHERE id = %d",
            $task_id
        ) );
    }

    private function insertTeam( string $name ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => $this->club, 'name' => $name ] );
        return (int) $wpdb->insert_id;
    }

    private function insertPlayer( int $team_id ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id'    => $this->club,
            'team_id'    => $team_id,
            'first_name' => 'Reminder',
            'last_name'  => 'Player',
            'status'     => 'active',
        ] );
        return (int) $wpdb->insert_id;
    }

    /** A head coach on one team (see InjuryOverviewAuditTest). */
    private function makeHeadCoach( int $team_id ): int {
        global $wpdb;
        $p   = $wpdb->prefix;
        $uid = self::factory()->user->create( [ 'role' => 'tt_coach' ] );

        $role_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}tt_roles WHERE role_key = %s AND club_id = %d LIMIT 1",
            'head_coach',
            $this->club
        ) );
        $this->assertGreaterThan( 0, $role_id, 'the head coach auth role must be seeded' );

        $wpdb->insert( "{$p}tt_people", [
            'club_id'    => $this->club,
            'first_name' => 'Head',
            'last_name'  => 'Coach',
            'role_type'  => 'head_coach',
            'wp_user_id' => $uid,
            'status'     => 'active',
        ] );
        $person_id = (int) $wpdb->insert_id;

        // The reminder finds the head coach the way every recipient lookup
        // does: through the `head_coach` functional role on the assignment.
        $functional = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}tt_functional_roles WHERE role_key = %s AND club_id = %d",
            'head_coach', $this->club
        ) );
        if ( $functional <= 0 ) {
            $wpdb->insert( "{$p}tt_functional_roles", [
                'club_id'     => $this->club,
                'role_key'    => 'head_coach',
                'label'       => 'Head coach',
                'description' => 'Created by InjuryRecoveryReminderTest.',
                'is_system'   => 1,
                'sort_order'  => 10,
            ] );
            $functional = (int) $wpdb->insert_id;
        }

        $wpdb->insert( "{$p}tt_team_people", [
            'club_id'            => $this->club,
            'team_id'            => $team_id,
            'person_id'          => $person_id,
            'functional_role_id' => $functional,
            'role_in_team'       => 'head_coach',
            'is_head_coach'      => 1,
        ] );
        $wpdb->insert( "{$p}tt_user_role_scopes", [
            'club_id'    => $this->club,
            'person_id'  => $person_id,
            'role_id'    => $role_id,
            'scope_type' => 'team',
            'scope_id'   => $team_id,
        ] );

        AuthorizationService::flushCache();
        return $uid;
    }

    /** A staff member who is the physio of one team (see InjuriesCollectionRestTest). */
    private function makePhysio( int $team_id ): int {
        global $wpdb;
        $p   = $wpdb->prefix;
        $uid = self::factory()->user->create( [ 'role' => 'tt_staff' ] );

        $wpdb->insert( "{$p}tt_people", [
            'club_id'    => $this->club,
            'first_name' => 'Team',
            'last_name'  => 'Physio',
            'role_type'  => 'staff',
            'wp_user_id' => $uid,
            'status'     => 'active',
        ] );
        $person_id = (int) $wpdb->insert_id;

        $role_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}tt_functional_roles WHERE role_key = %s AND club_id = %d",
            'physio', $this->club
        ) );
        if ( $role_id <= 0 ) {
            $wpdb->insert( "{$p}tt_functional_roles", [
                'club_id'     => $this->club,
                'role_key'    => 'physio',
                'label'       => 'Physio',
                'description' => 'Created by InjuryRecoveryReminderTest.',
                'is_system'   => 1,
                'sort_order'  => 90,
            ] );
            $role_id = (int) $wpdb->insert_id;
        }

        $wpdb->insert( "{$p}tt_team_people", [
            'club_id'            => $this->club,
            'team_id'            => $team_id,
            'person_id'          => $person_id,
            'functional_role_id' => $role_id,
            'role_in_team'       => 'physio',
        ] );
        $wpdb->insert( "{$p}tt_user_role_scopes", [
            'person_id'  => $person_id,
            'role_id'    => 1,
            'scope_type' => 'team',
            'scope_id'   => $team_id,
        ] );

        AuthorizationService::flushCache();
        FunctionalRoleGrants::clearCache();
        return $uid;
    }
}
