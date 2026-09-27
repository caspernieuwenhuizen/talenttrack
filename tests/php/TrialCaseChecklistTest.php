<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_UnitTestCase;
use TT\Domain\Vocabularies\Lookups\TrialCaseDecision;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Trials\Rest\TrialCaseChecklistRestController;
use TT\Modules\Trials\Services\TrialCaseChecklistService;
use TT\Modules\Trials\Services\TrialDecisionService;
use TT\Modules\Trials\TrialsModule;

/**
 * #4008 — an admitted trial case carries its follow-up checklist.
 *
 * Trial case 465 was admitted and then nothing followed: no letter, the
 * player never put on a team, the guardian's e-mail left to someone
 * remembering to ask. The checklist keeps those three steps on the case.
 */
final class TrialCaseChecklistTest extends WP_UnitTestCase {

    /** Long enough for the decision motivation floor. */
    private const MOTIVATION = 'Sterk in de duels en coachbaar; past binnen de speelwijze van de lichting.';

    private string $p;
    private int $club;
    private int $player  = 0;
    private int $track   = 0;
    private int $manager = 0;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        TrialsModule::ensureCapabilities();
        TrialCaseChecklistService::init();

        global $wpdb, $wp_rest_server;
        $wp_rest_server = new \WP_REST_Server();
        do_action( 'rest_api_init' );

        $this->p    = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();

        $wpdb->insert( "{$this->p}tt_players", [
            'club_id'    => $this->club,
            'first_name' => 'Liam',
            'last_name'  => 'Proef',
            'status'     => 'trial',
        ] );
        $this->player = (int) $wpdb->insert_id;

        $wpdb->insert( "{$this->p}tt_trial_tracks", [ 'club_id' => $this->club, 'slug' => 'std-' . uniqid(), 'name' => 'Standard' ] );
        $this->track = (int) $wpdb->insert_id;

        $this->manager = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $this->manager );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_admit_creates_the_three_items(): void {
        $case = $this->decidedCase( TrialCaseDecision::ADMIT );

        $keys = array_column( ( new TrialCaseChecklistService() )->itemsFor( $case ), 'item_key' );
        $this->assertSame(
            [ TrialCaseChecklistService::LETTER_SENT, TrialCaseChecklistService::PLAYER_ON_TEAM, TrialCaseChecklistService::GUARDIAN_CONTACT ],
            $keys
        );
    }

    public function test_other_decisions_create_no_items(): void {
        foreach ( [ TrialCaseDecision::DENY_FINAL, TrialCaseDecision::DENY_ENCOURAGEMENT ] as $decision ) {
            $case = $this->decidedCase( $decision );
            $this->assertSame( [], ( new TrialCaseChecklistService() )->itemsFor( $case ), $decision . ' created a checklist' );
        }
    }

    public function test_recording_admit_twice_does_not_duplicate_items(): void {
        $case = $this->decidedCase( TrialCaseDecision::ADMIT );
        ( new TrialCaseChecklistService() )->seedFor( $case, TrialCaseDecision::ADMIT );

        $this->assertCount( 3, ( new TrialCaseChecklistService() )->itemsFor( $case ) );
    }

    public function test_the_letter_item_ticks_itself_once_the_admit_letter_exists(): void {
        $case = $this->decidedCase( TrialCaseDecision::ADMIT );

        $letter = $this->item( $case, TrialCaseChecklistService::LETTER_SENT );
        $this->assertTrue( $letter['done'], 'recording the admit generated the letter' );
        $this->assertTrue( $letter['done_automatically'] );
    }

    public function test_the_team_item_ticks_itself_when_the_player_joins_a_team(): void {
        global $wpdb;
        $case = $this->decidedCase( TrialCaseDecision::ADMIT );

        $this->assertFalse( $this->item( $case, TrialCaseChecklistService::PLAYER_ON_TEAM )['done'] );

        $wpdb->insert( "{$this->p}tt_teams", [ 'club_id' => $this->club, 'name' => 'JO7-1', 'age_group' => 'JO7' ] );
        $wpdb->update( "{$this->p}tt_players", [ 'team_id' => (int) $wpdb->insert_id ], [ 'id' => $this->player ] );

        $item = $this->item( $case, TrialCaseChecklistService::PLAYER_ON_TEAM );
        $this->assertTrue( $item['done'] );
        $this->assertTrue( $item['done_automatically'] );
        $this->assertNotNull( $item['done_at'] );
    }

    public function test_an_item_can_be_ticked_and_assigned_by_hand(): void {
        $case     = $this->decidedCase( TrialCaseDecision::ADMIT );
        $assignee = self::factory()->user->create( [ 'role' => 'tt_coach', 'display_name' => 'Gijs Coach' ] );

        $res = TrialCaseChecklistRestController::update_item( $this->patch( $case, TrialCaseChecklistService::GUARDIAN_CONTACT, [
            'done'             => true,
            'assignee_user_id' => $assignee,
        ] ) );

        $this->assertSame( 200, $res->get_status() );
        $item = $this->item( $case, TrialCaseChecklistService::GUARDIAN_CONTACT );
        $this->assertTrue( $item['done'] );
        $this->assertFalse( $item['done_automatically'] );
        $this->assertSame( $this->manager, $item['done_by'] );
        $this->assertSame( $assignee, $item['assignee_user_id'] );
        $this->assertSame( 'Gijs Coach', $item['assignee_name'] );
    }

    public function test_a_non_staff_assignee_is_refused(): void {
        $case    = $this->decidedCase( TrialCaseDecision::ADMIT );
        $outside = self::factory()->user->create( [ 'role' => 'subscriber' ] );

        $res = TrialCaseChecklistRestController::update_item( $this->patch( $case, TrialCaseChecklistService::GUARDIAN_CONTACT, [
            'assignee_user_id' => $outside,
        ] ) );

        $this->assertSame( 400, $res->get_status() );
    }

    public function test_a_caller_who_cannot_edit_the_case_is_refused(): void {
        $case  = $this->decidedCase( TrialCaseDecision::ADMIT );
        $coach = self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        wp_set_current_user( $coach );

        $res = TrialCaseChecklistRestController::update_item( $this->patch( $case, TrialCaseChecklistService::LETTER_SENT, [ 'done' => false ] ) );
        $this->assertSame( 403, $res->get_status() );

        $routed = rest_do_request( $this->patch( $case, TrialCaseChecklistService::LETTER_SENT, [ 'done' => false ] ) );
        $this->assertSame( 403, (int) $routed->get_status() );
    }

    public function test_an_unknown_item_is_a_404(): void {
        $case = $this->decidedCase( TrialCaseDecision::ADMIT );

        $res = TrialCaseChecklistRestController::update_item( $this->patch( $case, 'bake_a_cake', [ 'done' => true ] ) );
        $this->assertSame( 404, $res->get_status() );
    }

    // ── helpers ────────────────────────────────────────────────────────

    private function decidedCase( string $decision ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_trial_cases", [
            'club_id'    => $this->club,
            'player_id'  => $this->player,
            'track_id'   => $this->track,
            'start_date' => '2026-09-01',
            'end_date'   => '2026-10-01',
            'status'     => 'open',
            'uuid'       => wp_generate_uuid4(),
        ] );
        $case = (int) $wpdb->insert_id;

        $result = ( new TrialDecisionService() )->record( $case, $decision, $this->manager, self::MOTIVATION );
        $this->assertTrue( $result['recorded'] );
        return $case;
    }

    /** @return array<string,mixed> */
    private function item( int $case, string $key ): array {
        foreach ( ( new TrialCaseChecklistService() )->itemsFor( $case ) as $row ) {
            if ( $row['item_key'] === $key ) return $row;
        }
        $this->fail( "no {$key} item on case {$case}" );
    }

    /** @param array<string,mixed> $body */
    private function patch( int $case, string $key, array $body ): WP_REST_Request {
        $r = new WP_REST_Request( 'PATCH', '/talenttrack/v1/trial-cases/' . $case . '/checklist/' . $key );
        $r->set_url_params( [ 'id' => (string) $case, 'item_key' => $key ] );
        $r->set_header( 'content-type', 'application/json' );
        $r->set_body( (string) wp_json_encode( $body ) );
        return $r;
    }
}
