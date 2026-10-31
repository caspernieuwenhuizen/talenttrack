<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Journey\InjuryRepository;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Activities\Services\AttendanceDateRule;
use TT\Modules\Authorization\FunctionalRoleGrants;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Authorization\MatrixGate;
use TT\Modules\Journey\JourneyModule;
use TT\Modules\Journey\Workflow\InjuryRecoveryConfirmForm;
use TT\Modules\Journey\Workflow\InjuryRecoveryDueTemplate;
use TT\Modules\Workflow\TaskStatus;
use TT\Modules\Workflow\WorkflowModule;

/**
 * #4289 — answering the recovery check with "extend" re-dates the record the
 * form named, and only that one, through the repository with the same audit
 * row an edit writes. "On track" and "unsure" write nothing. The form refuses
 * another player's record, a closed or archived one, and a date that is not
 * after today; somebody who may not change the record is not offered it.
 */
final class InjuryRecoveryExtendTest extends WP_UnitTestCase {

    private int $club   = 0;
    private int $team   = 0;
    private int $player = 0;
    private int $coach  = 0;
    private int $admin  = 0;
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
        $this->team   = $this->insertTeam( 'JO12-1' );
        $this->player = $this->insertPlayer( $this->team, 'Extend' );
        $this->coach  = $this->makeHeadCoach( $this->team );

        $this->admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $this->admin );
    }

    public function tear_down(): void {
        wp_set_current_user( 0 );
        MatrixRepository::clearCache();
        FunctionalRoleGrants::clearCache();
        parent::tear_down();
    }

    public function test_extend_redates_only_the_chosen_record_with_the_edit_audit_row(): void {
        $repo   = new InjuryRepository();
        $first  = $this->injury( -20, -3 );
        $second = $this->injury( -10, -1 );
        $task   = $this->openTask();

        $html = ( new InjuryRecoveryConfirmForm() )->render( $task );
        $this->assertStringContainsString( 'value="' . $first . '"', $html, 'both records due are listed' );
        $this->assertStringContainsString( 'value="' . $second . '"', $html );

        $this->answer( $task, [
            'recovery_status'     => InjuryRecoveryConfirmForm::OPTION_EXTEND,
            'injury_id'           => (string) $second,
            'new_expected_return' => $this->day( 10 ),
        ] );

        $this->assertSame( $this->day( 10 ), (string) $repo->find( $second )->expected_return );
        $this->assertSame( $this->day( -3 ), (string) $repo->find( $first )->expected_return, 'the other record is untouched' );

        $audit = $this->auditPayload( $second );
        $this->assertIsArray( $audit, 'the change is in the audit log' );
        $this->assertSame( $this->player, (int) $audit['player_id'] );
        $this->assertSame( [ 'expected_return' ], $audit['fields'] );
        $this->assertNull( $this->auditPayload( $first ) );
    }

    public function test_one_record_due_is_preselected_and_its_reminder_follows_the_new_date(): void {
        $id   = $this->injury( -20, -2 );
        $task = $this->openTask();

        $html = ( new InjuryRecoveryConfirmForm() )->render( $task );
        $this->assertMatchesRegularExpression( '/name="injury_id" value="' . $id . '"\s+checked/', $html );

        $this->answer( $task, [
            'recovery_status'     => InjuryRecoveryConfirmForm::OPTION_EXTEND,
            'injury_id'           => (string) $id,
            'new_expected_return' => $this->day( 7 ),
        ] );

        $this->assertSame( [], $this->activeTasks(), 'the new date is ahead, so no reminder is open' );
    }

    public function test_on_track_and_unsure_change_no_record(): void {
        global $wpdb;
        $repo = new InjuryRepository();
        $id   = $this->injury( -20, -2 );
        $task = $this->openTask();

        foreach ( [ InjuryRecoveryConfirmForm::OPTION_ON_TRACK, InjuryRecoveryConfirmForm::OPTION_UNSURE ] as $status ) {
            // The same reminder, answered once each way.
            $wpdb->update( "{$wpdb->prefix}tt_workflow_tasks", [ 'status' => TaskStatus::OPEN ], [ 'id' => (int) $task['id'] ] );
            $this->answer( $task, [
                'recovery_status'     => $status,
                'injury_id'           => (string) $id,
                'new_expected_return' => $this->day( 10 ),
            ] );
            $this->assertSame( $this->day( -2 ), (string) $repo->find( $id )->expected_return, "{$status} writes nothing" );
        }
        $this->assertNull( $this->auditPayload( $id ) );
    }

    public function test_another_players_closed_or_archived_record_is_refused(): void {
        global $wpdb;
        $this->injury( -20, -2 );
        $task = $this->openTask();
        $form = new InjuryRecoveryConfirmForm();

        $other_player = $this->insertPlayer( $this->team, 'Other' );
        $foreign = ( new InjuryRepository() )->create( [
            'player_id'       => $other_player,
            'started_on'      => $this->day( -20 ),
            'expected_return' => $this->day( -2 ),
        ] );
        $closed = $this->injury( -30, -10 );
        ( new InjuryRepository() )->update( $closed, [ 'actual_return' => $this->day( -1 ) ] );
        $archived = $this->injury( -30, -5 );
        $wpdb->update( "{$wpdb->prefix}tt_player_injuries", [ 'archived_at' => current_time( 'mysql' ) ], [ 'id' => $archived ] );

        foreach ( [ 'another player' => $foreign, 'closed' => $closed, 'archived' => $archived, 'missing' => 0 ] as $case => $id ) {
            $errors = $form->validate( [
                'recovery_status'     => InjuryRecoveryConfirmForm::OPTION_EXTEND,
                'injury_id'           => (string) $id,
                'new_expected_return' => $this->day( 10 ),
            ], $task );
            $this->assertArrayHasKey( 'injury_id', $errors, "a {$case} record is refused" );
            $this->assertArrayHasKey( '__form', $errors, 'and the task view can show why' );
        }
        $this->assertSame( $this->day( -2 ), (string) ( new InjuryRepository() )->find( $foreign )->expected_return );
    }

    public function test_a_date_that_is_not_after_today_is_refused(): void {
        $id   = $this->injury( -20, -2 );
        $task = $this->openTask();
        $form = new InjuryRecoveryConfirmForm();

        foreach ( [ 'past' => $this->day( -1 ), 'today' => $this->today, 'empty' => '', 'garbage' => '2026-13-45' ] as $case => $date ) {
            $errors = $form->validate( [
                'recovery_status'     => InjuryRecoveryConfirmForm::OPTION_EXTEND,
                'injury_id'           => (string) $id,
                'new_expected_return' => $date,
            ], $task );
            $this->assertArrayHasKey( 'new_expected_return', $errors, "a {$case} date is refused" );
        }

        $this->assertSame( [], $form->validate( [
            'recovery_status'     => InjuryRecoveryConfirmForm::OPTION_EXTEND,
            'injury_id'           => (string) $id,
            'new_expected_return' => $this->day( 1 ),
        ], $task ), 'tomorrow is fine' );
    }

    public function test_a_viewer_without_change_rights_is_not_offered_extend(): void {
        ( new MatrixRepository() )->removeRow( 'head_coach', 'player_injuries', MatrixGate::CHANGE, MatrixGate::SCOPE_TEAM );
        MatrixRepository::clearCache();
        FunctionalRoleGrants::clearCache();
        AuthorizationService::flushCache();
        $this->assertTrue( AuthorizationService::canRecordInjury( $this->coach, $this->player, 'read' ), 'precondition: the head coach may read' );
        $this->assertFalse( AuthorizationService::canRecordInjury( $this->coach, $this->player, 'change' ), 'precondition: but not change' );

        $repo = new InjuryRepository();
        $id   = $this->injury( -20, -2 );
        $task = $this->openTask();
        wp_set_current_user( $this->coach );

        $form = new InjuryRecoveryConfirmForm();
        $html = $form->render( $task );
        $this->assertStringNotContainsString( 'value="' . InjuryRecoveryConfirmForm::OPTION_EXTEND . '"', $html );
        $this->assertStringNotContainsString( 'name="new_expected_return"', $html );
        $this->assertStringContainsString( 'value="' . InjuryRecoveryConfirmForm::OPTION_ON_TRACK . '"', $html );

        $raw = [
            'recovery_status'     => InjuryRecoveryConfirmForm::OPTION_EXTEND,
            'injury_id'           => (string) $id,
            'new_expected_return' => $this->day( 10 ),
        ];
        $this->assertArrayHasKey( 'recovery_status', $form->validate( $raw, $task ) );

        // A forged completion past the form still writes nothing.
        WorkflowModule::engine()->complete( (int) $task['id'], $form->serializeResponse( $raw, $task ) );
        $this->assertSame( $this->day( -2 ), (string) $repo->find( $id )->expected_return );
    }

    public function test_the_task_title_and_description_name_no_injury_detail(): void {
        $template = new InjuryRecoveryDueTemplate();
        foreach ( [ $template->name(), $template->description() ] as $text ) {
            foreach ( [ 'ankle', 'knee', 'expected_return', 'severity' ] as $leak ) {
                $this->assertStringNotContainsString( $leak, $text );
            }
        }
    }

    // ---- fixtures ------------------------------------------------------

    /** @param array<string,string> $raw */
    private function answer( array $task, array $raw ): void {
        $form = new InjuryRecoveryConfirmForm();
        $this->assertSame( [], $form->validate( $raw, $task ), 'the answer is valid' );
        $this->assertTrue( WorkflowModule::engine()->complete( (int) $task['id'], $form->serializeResponse( $raw, $task ) ) );
    }

    private function injury( int $started_offset, int $expected_offset ): int {
        return ( new InjuryRepository() )->create( [
            'player_id'       => $this->player,
            'started_on'      => $this->day( $started_offset ),
            'expected_return' => $this->day( $expected_offset ),
        ] );
    }

    /** @return array<string,mixed> */
    private function openTask(): array {
        $tasks = $this->activeTasks();
        $this->assertNotEmpty( $tasks, 'precondition: a reminder is open' );
        return $tasks[0];
    }

    /** @return list<array<string,mixed>> */
    private function activeTasks(): array {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tt_workflow_tasks WHERE template_key = %s AND player_id = %d ORDER BY id ASC",
            InjuryRecoveryDueTemplate::KEY,
            $this->player
        ), ARRAY_A );
        return array_values( array_filter(
            is_array( $rows ) ? $rows : [],
            static fn ( array $t ): bool => TaskStatus::isActionable( (string) $t['status'] )
        ) );
    }

    /** @return array<string,mixed>|null */
    private function auditPayload( int $injury_id ): ?array {
        global $wpdb;
        $payload = $wpdb->get_var( $wpdb->prepare(
            "SELECT payload FROM {$wpdb->prefix}tt_audit_log
              WHERE action = 'player.injury_updated' AND entity_type = 'player_injury' AND entity_id = %d
              ORDER BY id DESC LIMIT 1",
            $injury_id
        ) );
        if ( $payload === null ) return null;
        $decoded = json_decode( (string) $payload, true );
        return is_array( $decoded ) ? $decoded : null;
    }

    private function day( int $offset ): string {
        return gmdate( 'Y-m-d', (int) strtotime( $this->today . ' 00:00:00 UTC ' . ( $offset >= 0 ? '+' : '' ) . $offset . ' days' ) );
    }

    private function insertTeam( string $name ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => $this->club, 'name' => $name ] );
        return (int) $wpdb->insert_id;
    }

    private function insertPlayer( int $team_id, string $last ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id'    => $this->club,
            'team_id'    => $team_id,
            'first_name' => 'Recovery',
            'last_name'  => $last,
            'status'     => 'active',
        ] );
        return (int) $wpdb->insert_id;
    }

    /** A head coach on one team (see InjuryRecoveryReminderTest). */
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

        $functional = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}tt_functional_roles WHERE role_key = %s AND club_id = %d",
            'head_coach', $this->club
        ) );
        if ( $functional <= 0 ) {
            $wpdb->insert( "{$p}tt_functional_roles", [
                'club_id'     => $this->club,
                'role_key'    => 'head_coach',
                'label'       => 'Head coach',
                'description' => 'Created by InjuryRecoveryExtendTest.',
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
}
