<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_UnitTestCase;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Trials\Rest\TrialsRestController;
use TT\Modules\Trials\Security\TrialCaseAccessPolicy;
use TT\Modules\Trials\TrialsModule;

/**
 * `GET trial-cases` lists the cases the caller can open, and only a trial
 * manager lists them all. `tt_view_trial_synthesis` is held by every coach;
 * it says what they may read on a case they sit on, not which cases exist,
 * so a coach on one panel no longer receives every trialist's name and
 * decision in the club.
 */
final class TrialCaseListVisibilityTest extends WP_UnitTestCase {

    private int $playerA = 0;
    private int $playerB = 0;
    private int $caseA = 0;
    private int $caseB = 0;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        TrialsModule::ensureCapabilities();
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();

        $this->playerA = $this->player( 'Panel' );
        $this->playerB = $this->player( 'Elsewhere' );
        $this->caseA   = $this->trialCase( $this->playerA );
        $this->caseB   = $this->trialCase( $this->playerB );
    }

    public function tear_down(): void {
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_a_coach_lists_only_the_case_they_sit_on(): void {
        $coach = (int) self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $this->seat( $this->caseA, $coach );
        $this->assertTrue( user_can( $coach, 'tt_view_trial_synthesis' ), 'precondition: the coach holds the coarse capability' );

        $this->assertSame( [ $this->caseA ], $this->listIds( $coach ) );
        $this->assertTrue( TrialCaseAccessPolicy::canOpenCase( $coach, $this->caseA ) );
        $this->assertFalse( TrialCaseAccessPolicy::canOpenCase( $coach, $this->caseB ) );
    }

    public function test_a_player_filter_never_widens_the_list(): void {
        $coach = (int) self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $this->seat( $this->caseA, $coach );

        $this->assertSame( [], $this->listIds( $coach, [ 'player_id' => $this->playerB ] ) );
        $this->assertSame( [ $this->caseA ], $this->listIds( $coach, [ 'player_id' => $this->playerA ] ) );
    }

    public function test_a_coach_on_no_panel_lists_nothing(): void {
        $coach = (int) self::factory()->user->create( [ 'role' => 'tt_coach' ] );

        $this->assertSame( [], $this->listIds( $coach ) );
    }

    public function test_an_unassigned_seat_no_longer_lists_the_case(): void {
        $coach = (int) self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $this->seat( $this->caseA, $coach, '2026-09-15 10:00:00' );

        $this->assertSame( [], $this->listIds( $coach ) );
    }

    public function test_a_scout_lists_the_cases_of_linked_players(): void {
        $scout = (int) self::factory()->user->create( [ 'role' => 'tt_scout' ] );
        update_user_meta( $scout, 'tt_scout_player_ids', wp_json_encode( [ $this->playerB ] ) );
        AuthorizationService::flushCache();

        $this->assertSame( [ $this->caseB ], $this->listIds( $scout ) );
    }

    public function test_a_trial_manager_lists_every_case(): void {
        $hod = (int) self::factory()->user->create( [ 'role' => 'tt_head_dev' ] );

        $this->assertNull( TrialCaseAccessPolicy::visibleCaseFilter( $hod ) );
        $ids = $this->listIds( $hod );
        $this->assertContains( $this->caseA, $ids );
        $this->assertContains( $this->caseB, $ids );
    }

    private function player( string $last ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id'    => (int) CurrentClub::id(),
            'first_name' => 'Trialist',
            'last_name'  => $last,
            'status'     => 'trial',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function trialCase( int $player_id ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_trial_cases", [
            'club_id'    => (int) CurrentClub::id(),
            'player_id'  => $player_id,
            'status'     => 'open',
            'start_date' => '2026-09-01',
            'end_date'   => '2026-09-30',
            'created_by' => 1,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function seat( int $case_id, int $user_id, ?string $unassigned_at = null ): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_trial_case_staff", [
            'club_id'       => (int) CurrentClub::id(),
            'case_id'       => $case_id,
            'user_id'       => $user_id,
            'unassigned_at' => $unassigned_at,
        ] );
        AuthorizationService::flushCache();
    }

    /**
     * @param array<string, mixed> $params
     * @return list<int>
     */
    private function listIds( int $user, array $params = [] ): array {
        wp_set_current_user( $user );
        $request = new WP_REST_Request( 'GET', '/talenttrack/v1/trial-cases' );
        foreach ( $params as $key => $value ) {
            $request->set_param( $key, $value );
        }
        $data  = (array) TrialsRestController::list_cases( $request )->get_data();
        $cases = (array) ( ( (array) ( $data['data'] ?? [] ) )['cases'] ?? [] );
        $ids   = array_map( static fn( $c ): int => (int) ( ( (array) $c )['id'] ?? 0 ), $cases );
        sort( $ids );
        return $ids;
    }
}
