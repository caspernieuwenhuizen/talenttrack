<?php
namespace TT\Tests\Php;

use ReflectionClass;
use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Trials\Repositories\TrialCasesRepository;
use TT\Modules\Trials\Security\TrialCaseAccessPolicy;
use TT\Shared\Frontend\FrontendTrialCaseView;

/**
 * #3787 — the motivation behind a recorded trial decision, read back.
 *
 * It is the academy's own record of why a child was admitted or turned
 * away, written once at the moment that matters. These tests pin the two
 * halves of where it may be read: on the Decision tab of the case it
 * belongs to, with who recorded it and when — and **nowhere else**,
 * least of all on the player's own record, which is read by
 * considerably more people than the panel it was written for.
 */
final class TrialMotivationReadbackTest extends WP_UnitTestCase {

    private const MOTIVATION = 'Sterk in de duels en leest het spel goed; hij past bij de selectie van volgend seizoen.';

    private string $p;
    private int $club;
    private int $manager   = 0;
    private int $coach     = 0;
    private int $player_id = 0;
    private int $case_id   = 0;

    public function set_up(): void {
        parent::set_up();
        $roles = new RolesService();
        $roles->installRoles();
        $roles->ensureCapabilities();

        global $wp_rest_server, $wpdb;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        $this->p    = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();

        $this->manager = self::factory()->user->create( [ 'role' => 'administrator', 'display_name' => 'Marco de Wit' ] );
        $this->coach   = self::factory()->user->create( [ 'role' => 'tt_coach', 'display_name' => 'Lex Vermeulen' ] );

        $wpdb->insert( "{$this->p}tt_players", [
            'club_id' => $this->club, 'first_name' => 'Maxim', 'last_name' => 'Terpstra', 'status' => 'active',
        ] );
        $this->player_id = (int) $wpdb->insert_id;

        $wpdb->insert( "{$this->p}tt_trial_tracks", [ 'club_id' => $this->club, 'name' => 'Standard' ] );
        $track = (int) $wpdb->insert_id;

        $wpdb->insert( "{$this->p}tt_trial_cases", [
            'club_id'          => $this->club,
            'player_id'        => $this->player_id,
            'track_id'         => $track,
            'start_date'       => '2026-09-01',
            'end_date'         => '2026-10-01',
            'status'           => 'decided',
            'decision'         => 'admit',
            'decision_notes'   => self::MOTIVATION,
            'decision_made_at' => '2026-10-01 14:30:00',
            'decision_made_by' => $this->manager,
            'uuid'             => wp_generate_uuid4(),
        ] );
        $this->case_id = (int) $wpdb->insert_id;

        wp_set_current_user( $this->manager );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    // ── on the Decision tab ────────────────────────────────────────────

    public function test_the_decision_tab_reads_the_motivation_back_with_its_author_and_date(): void {
        $html = $this->renderDecision();

        $this->assertStringContainsString( self::MOTIVATION, $html );
        $this->assertStringContainsString( 'Marco de Wit', $html, 'who recorded it' );
        $this->assertStringContainsString( '2026-10-01 14:30:00', $html, 'when' );
    }

    /**
     * Read-only. The motivation is rendered as text, not handed back into
     * a field somebody could type over — amending a recorded decision is
     * a different question with its own consequences.
     */
    public function test_there_is_no_edit_affordance_on_the_motivation(): void {
        $html = $this->renderDecision();

        $this->assertStringNotContainsString( 'name="decision_notes"', $html );
        $this->assertStringNotContainsString( '<textarea', $html );
    }

    /**
     * A case decided before the motivation was captured at all. It must
     * render as a decision with no motivation, not as a decision with an
     * empty one.
     */
    public function test_a_decision_with_no_motivation_renders_no_empty_block(): void {
        global $wpdb;
        $wpdb->update( "{$this->p}tt_trial_cases", [ 'decision_notes' => null ], [ 'id' => $this->case_id ] );

        $html = $this->renderDecision();

        $this->assertStringNotContainsString( 'tt-trial-decision-motivation', $html );
        $this->assertStringContainsString( 'Marco de Wit', $html, 'the rest of the summary is unaffected' );
    }

    // ── and nowhere else ───────────────────────────────────────────────

    /**
     * The decision the issue turned on. The player file is read by
     * considerably more people than the trial panel, and a
     * decline-flavoured sentence would follow an admitted child around
     * their record for years.
     */
    public function test_the_player_payload_carries_no_trace_of_it(): void {
        wp_set_current_user( $this->manager );
        $request  = new WP_REST_Request( 'GET', '/talenttrack/v1/players/' . $this->player_id );
        $response = rest_do_request( $request );

        $this->assertSame( 200, (int) $response->get_status() );
        $json = (string) wp_json_encode( $response->get_data() );

        $this->assertStringNotContainsString( 'decision_notes', $json );
        $this->assertStringNotContainsString( self::MOTIVATION, $json );
        $this->assertStringNotContainsString( 'Sterk in de duels', $json );
    }

    /**
     * The tab itself is manager-only, and a manager passes
     * `canViewSynthesis()` by definition — so the gate on the motivation
     * is the same one the REST read uses, asserted here rather than
     * assumed from the tab list.
     */
    public function test_a_viewer_without_the_synthesis_gate_has_no_decision_tab(): void {
        $case = ( new TrialCasesRepository() )->find( $this->case_id );
        $this->assertNotNull( $case );

        $this->assertTrue( TrialCaseAccessPolicy::canViewSynthesis( $this->manager, $this->case_id ) );
        $this->assertFalse(
            TrialCaseAccessPolicy::canViewSynthesis( $this->coach, $this->case_id ),
            'an unassigned coach reads no synthesis on this case'
        );

        $this->assertArrayHasKey( 'decision', $this->tabsFor( $case, true ) );
        $this->assertArrayNotHasKey( 'decision', $this->tabsFor( $case, false ) );
    }

    // ── helpers ────────────────────────────────────────────────────────

    private function renderDecision(): string {
        $case = ( new TrialCasesRepository() )->find( $this->case_id );
        $this->assertNotNull( $case );

        $method = ( new ReflectionClass( FrontendTrialCaseView::class ) )->getMethod( 'renderDecisionTab' );
        $method->setAccessible( true );

        ob_start();
        $method->invoke( null, $case );
        return (string) ob_get_clean();
    }

    /**
     * @return array<string,string>
     */
    private function tabsFor( object $case, bool $is_manager ): array {
        $method = ( new ReflectionClass( FrontendTrialCaseView::class ) )->getMethod( 'tabSet' );
        $method->setAccessible( true );
        /** @var array<string,string> $tabs */
        $tabs = $method->invoke( null, $case, $is_manager, $is_manager );
        return $tabs;
    }
}
