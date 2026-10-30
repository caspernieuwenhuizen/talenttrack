<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Domain\Vocabularies\Lookups\TrialCaseDecision;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Prospects\Domain\ProspectOutcome;
use TT\Modules\Prospects\Domain\ProspectStageClassifier;

/**
 * #4251 — a prospect's status follows its trial case's decision.
 *
 * Five places derived "where is this prospect now", in two orders, and
 * none read `tt_trial_cases.decision`: an admitted prospect listed as "In
 * trial" for good, and every undecided trialist showed "Joined" on the
 * visit page because admit-to-trial sets the player and the trial case
 * together. `ProspectOutcome` is now the one derivation, with D-8's
 * mapping, and `GET prospects`, its `status` filter and the funnel agree.
 */
final class ProspectOutcomeTrialDecisionTest extends WP_UnitTestCase {

    private int $prospect = 0;
    private int $trial    = 0;

    public function set_up(): void {
        parent::set_up();
        global $wp_rest_server, $wpdb;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );
        ( new RolesService() )->installRoles();
        ProspectOutcome::flushCache();
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $p = $wpdb->prefix;
        $wpdb->insert( "{$p}tt_players", [ 'club_id' => 1, 'first_name' => 'Trial', 'last_name' => 'Verhoevenx', 'status' => 'trial' ] );
        $player = (int) $wpdb->insert_id;

        $wpdb->insert( "{$p}tt_trial_cases", [
            'club_id'    => 1,
            'player_id'  => $player,
            'track_id'   => 1,
            'start_date' => '2026-09-01',
            'end_date'   => '2026-10-01',
            'created_by' => 1,
        ] );
        $this->trial = (int) $wpdb->insert_id;

        // Admit-to-trial sets both promotions, which is what made every
        // undecided trialist read "Joined" on the visit surfaces.
        $wpdb->insert( "{$p}tt_prospects", [
            'club_id'                   => 1,
            'first_name'                => 'Trial',
            'last_name'                 => 'Verhoevenx',
            'promoted_to_player_id'     => $player,
            'promoted_to_trial_case_id' => $this->trial,
        ] );
        $this->prospect = (int) $wpdb->insert_id;
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        ProspectOutcome::flushCache();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    private function decide( ?string $decision ): void {
        global $wpdb;
        $wpdb->update( "{$wpdb->prefix}tt_trial_cases", [ 'decision' => $decision ], [ 'id' => $this->trial ] );
        ProspectOutcome::flushCache();
    }

    /** @return array<string,mixed>|null the prospect's row from GET prospects */
    private function listed( string $status_filter = '' ): ?array {
        $req = new WP_REST_Request( 'GET', '/talenttrack/v1/prospects' );
        $params = [ 'search' => 'Verhoevenx', 'per_page' => 50 ];
        if ( $status_filter !== '' ) $params['filter'] = [ 'status' => $status_filter ];
        $req->set_query_params( $params );
        $res = rest_do_request( $req );
        $this->assertSame( 200, $res->get_status() );
        foreach ( (array) ( $res->get_data()['data']['rows'] ?? [] ) as $row ) {
            $row = (array) $row;
            if ( (int) $row['id'] === $this->prospect ) return $row;
        }
        return null;
    }

    private function prospectRow(): array {
        global $wpdb;
        return (array) $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}tt_prospects WHERE id = %d", $this->prospect ), ARRAY_A );
    }

    // ── the mapping (D-8) ──────────────────────────────────────────────

    public function test_every_decision_maps_as_d8_decided(): void {
        $this->assertSame( ProspectOutcome::IN_TRIAL, ProspectOutcome::forDecision( null ) );
        $this->assertSame( ProspectOutcome::JOINED, ProspectOutcome::forDecision( TrialCaseDecision::ADMIT ) );
        $this->assertSame( ProspectOutcome::JOINED, ProspectOutcome::forDecision( TrialCaseDecision::OFFERED_TEAM_POSITION ) );
        $this->assertSame( ProspectOutcome::NOT_ADMITTED, ProspectOutcome::forDecision( TrialCaseDecision::DENY_FINAL ) );
        $this->assertSame( ProspectOutcome::NOT_ADMITTED, ProspectOutcome::forDecision( TrialCaseDecision::DENY_ENCOURAGEMENT ) );
        $this->assertSame( ProspectOutcome::NOT_ADMITTED, ProspectOutcome::forDecision( TrialCaseDecision::DECLINED_OFFERED_POSITION ) );
        $this->assertSame( ProspectOutcome::IN_TRIAL, ProspectOutcome::forDecision( TrialCaseDecision::CONTINUE_IN_TRIAL_GROUP ) );
    }

    // ── list, filter and row agree ─────────────────────────────────────

    public function test_an_undecided_trialist_is_in_trial_everywhere_and_never_joined(): void {
        $this->assertSame( ProspectOutcome::IN_TRIAL, ProspectOutcome::forRow( $this->prospectRow() ), 'the visit surfaces' );
        $this->assertSame( 'trial', $this->listed()['status'] ?? null, 'GET prospects' );
        $this->assertNotNull( $this->listed( 'trial' ), 'the trial filter finds them' );
        $this->assertNull( $this->listed( 'joined' ), 'the joined filter does not' );
    }

    public function test_an_admitted_prospect_is_joined_in_the_list_the_filter_and_the_row(): void {
        $this->decide( TrialCaseDecision::ADMIT );

        $this->assertSame( 'joined', $this->listed()['status'] ?? null );
        $this->assertNotNull( $this->listed( 'joined' ) );
        $this->assertNull( $this->listed( 'trial' ) );
        $this->assertSame( ProspectOutcome::JOINED, ProspectOutcome::forRow( $this->prospectRow() ) );
    }

    public function test_a_denied_prospect_is_not_admitted_in_the_list_the_filter_and_the_row(): void {
        $this->decide( TrialCaseDecision::DENY_FINAL );

        $row = $this->listed();
        $this->assertSame( 'not_admitted', $row['status'] ?? null );
        $this->assertSame( 'Not admitted', $row['status_label'] ?? null );
        $this->assertNotNull( $this->listed( 'not_admitted' ) );
        $this->assertNull( $this->listed( 'trial' ) );
        $this->assertNull( $this->listed( 'joined' ) );
        $this->assertSame( ProspectOutcome::NOT_ADMITTED, ProspectOutcome::forRow( $this->prospectRow() ) );
    }

    public function test_continuing_in_the_trial_group_stays_in_trial(): void {
        $this->decide( TrialCaseDecision::CONTINUE_IN_TRIAL_GROUP );

        $this->assertSame( 'trial', $this->listed()['status'] ?? null );
        $this->assertNotNull( $this->listed( 'trial' ) );
    }

    // ── the funnel ─────────────────────────────────────────────────────

    public function test_a_declined_child_never_classifies_as_joined(): void {
        foreach ( [ 'released', 'inactive' ] as $player_status ) {
            $row = (object) [
                'promoted_to_player_id'     => 9,
                'promoted_to_trial_case_id' => 7,
                'player_status'             => $player_status,
                'created_at'                => gmdate( 'Y-m-d H:i:s' ),
            ];
            $this->assertNotSame( 'joined', ProspectStageClassifier::classify( $row, 0 ), $player_status );
        }
    }

    public function test_a_graduated_player_still_classifies_as_joined(): void {
        $row = (object) [
            'promoted_to_player_id'     => 9,
            'promoted_to_trial_case_id' => 7,
            'player_status'             => 'active',
            'created_at'                => gmdate( 'Y-m-d H:i:s' ),
        ];
        $this->assertSame( 'joined', ProspectStageClassifier::classify( $row, 0 ) );
    }
}
