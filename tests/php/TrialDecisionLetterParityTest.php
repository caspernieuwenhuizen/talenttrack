<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_UnitTestCase;
use TT\Domain\Vocabularies\Lookups\TrialCaseDecision;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Reports\AudienceType;
use TT\Modules\Trials\Rest\TrialsRestController;
use TT\Modules\Trials\Services\TrialDecisionService;
use TT\Modules\Trials\TrialsModule;

/**
 * #4042 — deciding a trial leaves the same case behind, whichever door the
 * decision came in by.
 *
 * The case screen recorded the decision and then generated the letter for
 * the matching audience. `POST trial-cases/{id}/decision` recorded the
 * decision and stopped, so a head of development who decided over the API
 * got a decided case with nothing to hand the family. The rule saying which
 * of the three letters a decision warrants lived in a private method on the
 * render class, which is why the route could not have made the second half
 * of the call: the mapping was unreachable, untested and un-API-able.
 */
final class TrialDecisionLetterParityTest extends WP_UnitTestCase {

    /** At least `TrialsRestController::DECISION_NOTES_MIN` characters. */
    private const MOTIVATION = 'Sterk in de duels en coachbaar; past binnen de speelwijze van de lichting.';

    private int $case_id = 0;
    private int $player  = 0;
    private int $manager = 0;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        TrialsModule::ensureCapabilities();

        global $wpdb;
        $p    = $wpdb->prefix;
        $club = (int) CurrentClub::id();

        $wpdb->insert( "{$p}tt_players", [
            'club_id'    => $club,
            'first_name' => 'Proef',
            'last_name'  => 'Speler',
            'status'     => 'trial',
        ] );
        $this->player = (int) $wpdb->insert_id;

        $wpdb->insert( "{$p}tt_trial_tracks", [ 'club_id' => $club, 'slug' => 'std-' . uniqid(), 'name' => 'Standard' ] );
        $track = (int) $wpdb->insert_id;

        $wpdb->insert( "{$p}tt_trial_cases", [
            'club_id'    => $club,
            'player_id'  => $this->player,
            'track_id'   => $track,
            'start_date' => '2026-09-01',
            'end_date'   => '2026-10-01',
            'status'     => 'open',
            'uuid'       => wp_generate_uuid4(),
        ] );
        $this->case_id = (int) $wpdb->insert_id;

        $this->manager = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $this->manager );
    }

    public function tear_down(): void {
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    /* ---- The mapping, outside the view ------------------------------- */

    public function test_each_decision_tab_outcome_maps_to_its_own_letter(): void {
        $this->assertSame(
            AudienceType::TRIAL_ADMITTANCE,
            TrialDecisionService::audienceFor( TrialCaseDecision::ADMIT )
        );
        $this->assertSame(
            AudienceType::TRIAL_DENIAL_FINAL,
            TrialDecisionService::audienceFor( TrialCaseDecision::DENY_FINAL )
        );
        $this->assertSame(
            AudienceType::TRIAL_DENIAL_ENCOURAGE,
            TrialDecisionService::audienceFor( TrialCaseDecision::DENY_ENCOURAGEMENT )
        );
    }

    /**
     * The three decisions the trial-group workflow writes warrant no letter.
     *
     * Two of them do not end the trial at all, and the third is the family's
     * answer rather than the club's. The view's mapping fell through to the
     * final-denial letter for all three, so a case that had just been
     * offered a place could produce a letter saying the opposite.
     */
    public function test_a_workflow_decision_warrants_no_letter(): void {
        foreach ( [
            TrialCaseDecision::OFFERED_TEAM_POSITION,
            TrialCaseDecision::CONTINUE_IN_TRIAL_GROUP,
            TrialCaseDecision::DECLINED_OFFERED_POSITION,
        ] as $decision ) {
            $this->assertNull( TrialDecisionService::audienceFor( $decision ), $decision . ' produced a letter' );
        }
    }

    /* ---- Both paths leave the same case behind ----------------------- */

    public function test_deciding_over_rest_generates_the_letter_the_decision_warrants(): void {
        $res = TrialsRestController::record_decision( $this->request( [
            'decision' => TrialCaseDecision::ADMIT,
            'notes'    => self::MOTIVATION,
        ] ) );

        $this->assertSame( 200, $res->get_status() );
        $data = (array) $res->get_data();
        $body = (array) ( $data['data'] ?? [] );
        $this->assertTrue( (bool) ( $body['recorded'] ?? false ) );
        $this->assertGreaterThan( 0, (int) ( $body['letter_id'] ?? 0 ), 'the route reported no letter' );

        $letters = $this->letters();
        $this->assertCount( 1, $letters, 'deciding over REST left the family with nothing to read' );
        $this->assertSame( AudienceType::TRIAL_ADMITTANCE, $letters[0]['audience'] );
        $this->assertSame( $this->case_id, $letters[0]['case_id'] );
    }

    public function test_the_encouragement_decline_gets_the_encouragement_letter(): void {
        $res = TrialsRestController::record_decision( $this->request( [
            'decision'          => TrialCaseDecision::DENY_ENCOURAGEMENT,
            'notes'             => self::MOTIVATION,
            'strengths_summary' => 'Werkt hard en is coachbaar.',
            'growth_areas'      => 'Handelingssnelheid onder druk.',
        ] ) );

        $this->assertSame( 200, $res->get_status() );
        $letters = $this->letters();
        $this->assertCount( 1, $letters );
        $this->assertSame( AudienceType::TRIAL_DENIAL_ENCOURAGE, $letters[0]['audience'] );
    }

    /**
     * The screen path's other half: what the service does is what the case
     * screen's decide action now calls, so recording through the service
     * leaves the same single live letter the route leaves.
     */
    public function test_the_service_leaves_one_live_letter_per_decision(): void {
        $svc = new TrialDecisionService();

        $first = $svc->record( $this->case_id, TrialCaseDecision::ADMIT, $this->manager, self::MOTIVATION );
        $this->assertTrue( $first['recorded'] );
        $this->assertGreaterThan( 0, $first['letter_id'] );

        // Deciding again supersedes rather than accumulates — two live
        // letters saying different things to one family is the failure
        // `TrialLetterService::generate()` prevents.
        $second = $svc->record( $this->case_id, TrialCaseDecision::DENY_FINAL, $this->manager, self::MOTIVATION );
        $this->assertGreaterThan( 0, $second['letter_id'] );

        $live = $this->letters();
        $this->assertCount( 1, $live, 'the case carries more than one live letter' );
        $this->assertSame( AudienceType::TRIAL_DENIAL_FINAL, $live[0]['audience'] );
    }

    public function test_an_unrecordable_decision_generates_no_letter(): void {
        $result = ( new TrialDecisionService() )->record( 0, TrialCaseDecision::ADMIT, $this->manager, self::MOTIVATION );

        $this->assertFalse( $result['recorded'] );
        $this->assertSame( 0, $result['letter_id'] );
        $this->assertSame( [], $this->letters() );
    }

    /* ---- Helpers ------------------------------------------------------ */

    /** @param array<string,mixed> $body */
    private function request( array $body ): WP_REST_Request {
        $r = new WP_REST_Request( 'POST', '/talenttrack/v1/trial-cases/' . $this->case_id . '/decision' );
        $r->set_param( 'id', $this->case_id );
        $r->set_header( 'content-type', 'application/json' );
        $r->set_body( (string) wp_json_encode( $body ) );
        return $r;
    }

    /**
     * The live letters about this player, newest first.
     *
     * Read out of `tt_player_reports` — trial letters reuse it — with the
     * case id pulled back out of `config_json`, which is where
     * `TrialLetterService` records which case a letter belongs to.
     *
     * @return array<int,array{audience:string, case_id:int}>
     */
    private function letters(): array {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT audience, config_json FROM {$wpdb->prefix}tt_player_reports
              WHERE player_id = %d AND revoked_at IS NULL
              ORDER BY id DESC",
            $this->player
        ), ARRAY_A );

        $out = [];
        foreach ( is_array( $rows ) ? $rows : [] as $row ) {
            $data   = (array) $row;
            $config = json_decode( (string) ( $data['config_json'] ?? '{}' ), true );
            $out[]  = [
                'audience' => (string) ( $data['audience'] ?? '' ),
                'case_id'  => (int) ( is_array( $config ) ? ( $config['case_id'] ?? 0 ) : 0 ),
            ];
        }
        return $out;
    }
}
