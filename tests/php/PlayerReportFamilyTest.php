<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Players\ParentChildResolver;
use TT\Infrastructure\Players\PlayerParentVisibilityRepository;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Analytics\Frontend\PlayerFamilyReportsTab;
use TT\Modules\Analytics\Reports\PlayerReportAudience;
use TT\Modules\Analytics\Reports\PlayerReportBlock;
use TT\Modules\Analytics\Reports\PlayerReportSnapshots;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Authorization\PersonaResolver;
use TT\Modules\Reports\Frontend\ReportWizardRedirect;

/**
 * #3955 (epic #3871) — reports shared with the player and their parents.
 *
 * Pinned:
 * - sharing freezes a snapshot whose **stored payload** carries only the family
 *   blocks, and evaluation scores without the coach's notes;
 * - for each family audience — a parent and the player — what they read is
 *   that allowlist: status, talking points, the development plan, staff notes,
 *   injuries, journey, behaviour, potential and the notes area are absent;
 * - a parent reads their own child's shared report and not another family's;
 * - a staff snapshot never reaches a family, and a family generates nothing,
 *   on screen or through REST;
 * - a section the child keeps from their parents is dropped for the parent;
 * - the retired wizard's links go on to the right place.
 *
 * Every refusal is paired with a grant in the same test, so "narrowed
 * correctly" cannot pass as "refused everything" (#3913, #3922). The coach is a
 * `tt_club_admin`, which resolves to `academy_admin`; a WordPress
 * `administrator` does not.
 *
 * Dates are in 2020 so nothing depends on the runner's clock.
 */
final class PlayerReportFamilyTest extends WP_UnitTestCase {

    private const FROM = '2020-03-01';
    private const TO   = '2020-03-31';
    private const NOTE = 'Needs to scan before receiving.';

    /** Every block a family must never receive. */
    private const EXCLUDED = [
        PlayerReportBlock::STATUS,
        PlayerReportBlock::TALKING_POINTS,
        PlayerReportBlock::PDP,
        PlayerReportBlock::THREAD_NOTES,
        PlayerReportBlock::INJURIES,
        PlayerReportBlock::JOURNEY,
        PlayerReportBlock::BEHAVIOUR,
        PlayerReportBlock::POTENTIAL,
        PlayerReportBlock::NOTES,
        PlayerReportBlock::MATCHES,
    ];

    private int $coach       = 0;
    private int $child       = 0;
    private int $other_child = 0;
    private int $parent      = 0;
    private int $other_parent = 0;
    private int $player_user = 0;

    public function set_up(): void {
        parent::set_up();
        $roles = new RolesService();
        $roles->installRoles();
        $roles->ensureCapabilities();
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();

        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        $this->coach = $this->makeUser( 'tt_club_admin', 'academy_admin' );

        global $wpdb;
        $p = $wpdb->prefix;
        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => CurrentClub::id(), 'name' => 'Family U13', 'age_group' => 'U13' ] );
        $team = (int) $wpdb->insert_id;

        $this->player_user = $this->makeUser( 'tt_player', 'player' );
        $this->child       = $this->makePlayer( 'Child', $team, $this->player_user );
        $this->other_child = $this->makePlayer( 'Other', $team, null );

        $this->parent       = $this->makeUser( 'tt_parent', 'parent' );
        $this->other_parent = $this->makeUser( 'tt_parent', 'parent' );
        $this->link( $this->parent, $this->child );
        $this->link( $this->other_parent, $this->other_child );

        foreach ( [ $this->child, $this->other_child ] as $pid ) {
            $wpdb->insert( "{$p}tt_evaluations", [
                'club_id'   => CurrentClub::id(),
                'player_id' => $pid,
                'coach_id'  => $this->coach,
                'eval_date' => '2020-03-10',
                'notes'     => self::NOTE,
            ] );
            $this->assertGreaterThan( 0, (int) $wpdb->insert_id, 'fixture: the evaluation was written' );
        }

        wp_set_current_user( $this->coach );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    // ── sharing ───────────────────────────────────────────────────────

    public function test_sharing_freezes_only_the_family_blocks_in_the_stored_payload(): void {
        $uuid = $this->shareEverything( $this->child );

        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT data_json, composition_json FROM {$wpdb->prefix}tt_player_report_snapshots WHERE uuid = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $uuid
        ), ARRAY_A );
        $this->assertIsArray( $row, 'fixture: the snapshot row exists' );

        $data = json_decode( (string) $row['data_json'], true );
        $this->assertIsArray( $data );
        $this->assertSame( PlayerReportAudience::FAMILY, $data['audience'] ?? null );
        $this->assertContains( PlayerReportBlock::RATINGS, $data['blocks'], 'the grant: scores are shared' );
        $this->assertSame( [], array_diff( $data['blocks'], PlayerReportAudience::FAMILY_BLOCKS ) );
        foreach ( self::EXCLUDED as $block ) {
            $this->assertArrayNotHasKey( $block, $data['data'], "{$block} is not in the stored payload" );
        }
        $this->assertCount( 1, $data['data']['ratings']['evaluations'] );
        $this->assertArrayNotHasKey( 'notes', $data['data']['ratings']['evaluations'][0] );
        $this->assertStringNotContainsString( self::NOTE, (string) $row['data_json'] );

        $composition = json_decode( (string) $row['composition_json'], true );
        $this->assertSame( PlayerReportAudience::FAMILY, $composition['audience'] ?? null );
    }

    public function test_the_parent_reads_the_family_allowlist_and_scores_without_notes(): void {
        $this->assertFamilyReadsTheAllowlist( $this->parent );
    }

    public function test_the_player_reads_the_family_allowlist_and_scores_without_notes(): void {
        $this->assertFamilyReadsTheAllowlist( $this->player_user );
    }

    public function test_a_parent_cannot_read_another_familys_report(): void {
        $own   = $this->shareEverything( $this->child );
        $other = $this->shareEverything( $this->other_child );

        $this->assertNotNull( PlayerReportSnapshots::read( $own, $this->parent ), 'the grant: their own child' );
        $this->assertNull( PlayerReportSnapshots::read( $other, $this->parent ), 'another family\'s child' );

        $this->assertCount( 1, PlayerReportSnapshots::sharedWithFamily( $this->child, $this->parent ) );
        $this->assertSame( [], PlayerReportSnapshots::sharedWithFamily( $this->other_child, $this->parent ) );

        wp_set_current_user( $this->parent );
        $this->assertSame( 200, $this->status( 'GET', "/talenttrack/v1/players/{$this->child}/report-snapshots" ) );
        $this->assertSame( 403, $this->status( 'GET', "/talenttrack/v1/players/{$this->other_child}/report-snapshots" ) );
        $this->assertSame( 200, $this->status( 'GET', "/talenttrack/v1/player-report-snapshots/{$own}" ) );
        $this->assertSame( 403, $this->status( 'GET', "/talenttrack/v1/player-report-snapshots/{$other}" ) );
    }

    public function test_a_staff_snapshot_never_reaches_the_family(): void {
        $shared = $this->shareEverything( $this->child );
        $staff  = PlayerReportSnapshots::take( $this->child, [ 'from' => self::FROM, 'to' => self::TO ], $this->coach );
        $this->assertNotSame( '', $staff, 'fixture: the staff snapshot was taken' );

        $this->assertNotNull( PlayerReportSnapshots::read( $shared, $this->parent ) );
        $this->assertNull( PlayerReportSnapshots::read( $staff, $this->parent ) );
        $this->assertNull( PlayerReportSnapshots::read( $staff, $this->player_user ) );

        $listed = array_column( PlayerReportSnapshots::sharedWithFamily( $this->child, $this->parent ), 'uuid' );
        $this->assertSame( [ $shared ], $listed );

        wp_set_current_user( $this->parent );
        $response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', "/talenttrack/v1/players/{$this->child}/report-snapshots" ) );
        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( [ $shared ], array_column( $response->get_data()['data']['snapshots'], 'uuid' ), 'a family reader lists only what was shared' );
    }

    public function test_families_generate_nothing(): void {
        // The grant: the coach shares, through REST.
        $share = new WP_REST_Request( 'POST', "/talenttrack/v1/players/{$this->child}/report-snapshots" );
        $share->set_param( 'audience', 'family' );
        $share->set_param( 'from', self::FROM );
        $share->set_param( 'to', self::TO );
        $response = rest_get_server()->dispatch( $share );
        $this->assertSame( 201, $response->get_status() );
        $this->assertSame( PlayerReportAudience::FAMILY, $response->get_data()['data']['audience'] ?? null );

        foreach ( [ $this->parent, $this->player_user ] as $reader ) {
            $this->assertSame( '', PlayerReportSnapshots::share( $this->child, [], $reader ) );
            $this->assertSame( '', PlayerReportSnapshots::take( $this->child, [], $reader ) );

            wp_set_current_user( $reader );
            $this->assertSame( 403, $this->status( 'GET', "/talenttrack/v1/players/{$this->child}/report" ) );
            $this->assertSame( 403, $this->status( 'POST', "/talenttrack/v1/players/{$this->child}/report-snapshots", [ 'audience' => 'family' ] ) );
        }
    }

    public function test_a_section_the_child_keeps_from_their_parents_is_dropped_for_the_parent_only(): void {
        $uuid = $this->shareEverything( $this->child );

        $this->assertTrue(
            ( new PlayerParentVisibilityRepository() )->setVisibility( $this->child, 'evaluations', false ),
            'fixture: the preference was written'
        );
        AuthorizationService::flushCache();

        $parent_view = PlayerReportSnapshots::read( $uuid, $this->parent );
        $this->assertNotNull( $parent_view );
        $this->assertNotContains( PlayerReportBlock::RATINGS, $parent_view['report']['blocks'] );
        $this->assertArrayNotHasKey( PlayerReportBlock::RATINGS, $parent_view['report']['data'] );
        $this->assertContains( PlayerReportBlock::ATTENDANCE, $parent_view['report']['blocks'], 'the sections the child still shares stay' );

        $player_view = PlayerReportSnapshots::read( $uuid, $this->player_user );
        $this->assertNotNull( $player_view );
        $this->assertContains( PlayerReportBlock::RATINGS, $player_view['report']['blocks'], 'the child reads their own scores' );
    }

    public function test_notes_are_refused_on_a_shared_report(): void {
        $shared = $this->shareEverything( $this->child );
        $staff  = PlayerReportSnapshots::take( $this->child, [ 'from' => self::FROM, 'to' => self::TO ], $this->coach );

        $this->assertTrue( PlayerReportSnapshots::note( $staff, 'ratings', 'Discussed.', $this->coach ) );
        $this->assertFalse( PlayerReportSnapshots::note( $shared, 'ratings', 'Discussed.', $this->coach ) );
        $this->assertFalse( PlayerReportSnapshots::note( $shared, 'ratings', 'Discussed.', $this->parent ) );
    }

    public function test_the_reports_tab_is_the_familys(): void {
        $this->assertTrue( PlayerFamilyReportsTab::isOffered( $this->parent, $this->child ) );
        $this->assertTrue( PlayerFamilyReportsTab::isOffered( $this->player_user, $this->child ) );
        $this->assertFalse( PlayerFamilyReportsTab::isOffered( $this->parent, $this->other_child ) );
        $this->assertFalse( PlayerFamilyReportsTab::isOffered( $this->coach, $this->child ), 'staff share from the player report' );

        $uuid = $this->shareEverything( $this->child );
        wp_set_current_user( $this->parent );
        $_GET['report'] = $uuid;
        ob_start();
        PlayerFamilyReportsTab::render( $this->child, $this->parent, 'https://example.test/?tt_view=overview&tab=reports' );
        $html = (string) ob_get_clean();
        unset( $_GET['report'] );

        $this->assertStringContainsString( 'data-tt-player-report', $html, 'the frozen report opens on the tab' );
        $this->assertStringContainsString( 'Family Child', $html );
        $this->assertStringNotContainsString( self::NOTE, $html );
    }

    // ── the retired wizard ────────────────────────────────────────────

    public function test_the_retired_wizard_leads_on(): void {
        $this->assertStringContainsString( 'standard-report', ReportWizardRedirect::targetFor( $this->coach, $this->child ) );

        $parent_target = ReportWizardRedirect::targetFor( $this->parent, $this->child );
        $this->assertStringContainsString( 'tab=reports', $parent_target );
        $this->assertStringContainsString( 'player_id=' . $this->child, $parent_target );

        $player_target = ReportWizardRedirect::targetFor( $this->player_user, $this->child );
        $this->assertStringContainsString( 'tab=reports', $player_target );
        $this->assertStringNotContainsString( 'player_id=', $player_target, 'the player reaches their own file without naming it' );

        $this->assertStringContainsString( 'standard-report', ReportWizardRedirect::targetFor( $this->parent, $this->other_child ), 'no family link, no family tab' );
    }

    public function test_the_wizard_and_its_settings_are_gone(): void {
        $this->assertFalse( class_exists( '\\TT\\Shared\\Frontend\\FrontendReportWizardView' ) );
        $this->assertFalse( class_exists( '\\TT\\Modules\\Reports\\PrivacySettings' ) );
        $this->assertFalse( class_exists( '\\TT\\Modules\\Reports\\AudienceDefaults' ) );
        $this->assertFalse( property_exists( '\\TT\\Modules\\Reports\\ReportConfig', 'tone_variant' ) );
        $this->assertTrue( class_exists( '\\TT\\Modules\\Reports\\ReportConfig' ), 'the scout flow still records what it sent' );
    }

    // ── helpers ────────────────────────────────────────────────────────

    private function assertFamilyReadsTheAllowlist( int $reader ): void {
        $uuid = $this->shareEverything( $this->child );

        $snapshot = PlayerReportSnapshots::read( $uuid, $reader );
        $this->assertNotNull( $snapshot, 'the grant: the family reads what was shared with them' );
        $this->assertSame( PlayerReportAudience::FAMILY, $snapshot['audience'] );
        $this->assertSame( [], $snapshot['notes'] );

        $report = $snapshot['report'];
        $this->assertContains( PlayerReportBlock::RATINGS, $report['blocks'] );
        $this->assertContains( PlayerReportBlock::ATTENDANCE, $report['blocks'] );
        $this->assertSame( [], array_diff( $report['blocks'], PlayerReportAudience::FAMILY_BLOCKS ) );
        $this->assertSame( [], array_diff( array_keys( $report['data'] ), PlayerReportAudience::FAMILY_BLOCKS ) );
        foreach ( self::EXCLUDED as $block ) {
            $this->assertNotContains( $block, $report['blocks'] );
            $this->assertArrayNotHasKey( $block, $report['data'], "{$block} never reaches the family" );
        }

        $evaluations = $report['data']['ratings']['evaluations'] ?? [];
        $this->assertCount( 1, $evaluations, 'the score arrives' );
        $this->assertArrayNotHasKey( 'notes', $evaluations[0], 'without the coach\'s note' );

        // Through REST, the same document.
        wp_set_current_user( $reader );
        $response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', "/talenttrack/v1/player-report-snapshots/{$uuid}" ) );
        $this->assertSame( 200, $response->get_status() );
        $this->assertStringNotContainsString( self::NOTE, (string) wp_json_encode( $response->get_data() ) );
    }

    /** Share every block there is: the allowlist must do all the cutting. */
    private function shareEverything( int $player_id ): string {
        $uuid = PlayerReportSnapshots::share(
            $player_id,
            [ 'from' => self::FROM, 'to' => self::TO, 'blocks' => implode( ',', PlayerReportBlock::ALL ) ],
            $this->coach
        );
        $this->assertNotSame( '', $uuid, 'fixture: the coach shared the report' );
        return $uuid;
    }

    private function makeUser( string $wp_role, string $expected_persona ): int {
        $uid = (int) self::factory()->user->create( [ 'role' => $wp_role ] );
        $this->assertContains(
            $expected_persona,
            PersonaResolver::personasFor( $uid ),
            "{$wp_role} must resolve to {$expected_persona} or nothing below means anything"
        );
        AuthorizationService::flushCache();
        return $uid;
    }

    private function makePlayer( string $last, int $team_id, ?int $wp_user_id ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id'       => (int) CurrentClub::id(),
            'team_id'       => $team_id,
            'first_name'    => 'Family',
            'last_name'     => $last,
            'status'        => 'active',
            'date_of_birth' => '2013-05-06',
            'wp_user_id'    => $wp_user_id,
        ] );
        $id = (int) $wpdb->insert_id;
        $this->assertGreaterThan( 0, $id, 'fixture: the player was written' );
        return $id;
    }

    private function link( int $parent, int $player_id ): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_player_parents", [
            'club_id'        => (int) CurrentClub::id(),
            'player_id'      => $player_id,
            'parent_user_id' => $parent,
        ] );
        AuthorizationService::flushCache();
        $this->assertTrue( ParentChildResolver::isParentOf( $parent, $player_id ), 'fixture: the guardian link resolves' );
    }

    /** @param array<string,string> $params */
    private function status( string $method, string $route, array $params = [] ): int {
        $request = new WP_REST_Request( $method, $route );
        foreach ( $params as $key => $value ) {
            $request->set_param( $key, $value );
        }
        return (int) rest_get_server()->dispatch( $request )->get_status();
    }
}
