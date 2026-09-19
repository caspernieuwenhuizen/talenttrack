<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Core\FeatureRegistry;
use TT\Domain\Vocabularies\Lookups\PotentialBand;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Shared\Frontend\FrontendPlayerStatusCaptureView;

/**
 * #3715 — the profile's "history" links land on a page that shows the
 * history, for the staff who may read it.
 *
 * The capture screen refused on the capture question and returned before
 * anything was rendered, so a head coach who may read a player's file but
 * may not record against it followed "Status · history" or
 * "Potential · history" to one sentence. The same coach reads the same
 * rows over `GET players/{id}/potential`, which is what makes the empty
 * page a rendering bug rather than a permission boundary.
 *
 * `PlayerStatusModule` documents the rule both capture checks are written
 * against: switching capture off means "stop asking us for this", not
 * "hide what we already decided".
 */
final class PlayerStatusHistoryReadOnlyTest extends WP_UnitTestCase {

    private string $p;
    private int $club;
    private int $team_id;
    private int $player_id;
    private int $staff;

    /** Oldest first — a rise, a rise, then a revision down. */
    private const SERIES = [
        PotentialBand::RECREATIONAL,
        PotentialBand::TOP_AMATEUR,
        PotentialBand::FIRST_TEAM,
        PotentialBand::SEMI_PRO,
    ];

    public function set_up(): void {
        parent::set_up();
        global $wpdb, $wp_rest_server;
        $this->p    = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();

        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        $wpdb->insert( "{$this->p}tt_teams", [ 'club_id' => $this->club, 'name' => 'JO15-1 history' ] );
        $this->team_id = (int) $wpdb->insert_id;

        $wpdb->insert( "{$this->p}tt_players", [
            'club_id'       => $this->club,
            'team_id'       => $this->team_id,
            'first_name'    => 'Sem',
            'last_name'     => 'Trajectory',
            'status'        => 'active',
            'date_of_birth' => gmdate( 'Y-m-d', strtotime( '-15 years' ) ),
        ] );
        $this->player_id = (int) $wpdb->insert_id;

        $this->staff = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $this->staff );

        $this->recordSeries();
        $this->recordBehaviour( 7.0, 'Led the warm-up.' );
        $this->recordBehaviour( 8.0, 'Took the substitution well.' );

        FeatureRegistry::setEnabled( 'behaviour_rating', true );
        FeatureRegistry::setEnabled( 'potential_rating', true );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        FeatureRegistry::setEnabled( 'behaviour_rating', true );
        FeatureRegistry::setEnabled( 'potential_rating', true );
        unset( $_GET['tt_view'], $_GET['player_id'] );
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    // --- fixtures --------------------------------------------------------

    private function recordSeries(): void {
        global $wpdb;
        $days = 400;
        foreach ( self::SERIES as $band ) {
            $wpdb->insert( "{$this->p}tt_player_potential", [
                'club_id'        => $this->club,
                'player_id'      => $this->player_id,
                'set_at'         => gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - $days * DAY_IN_SECONDS ),
                'set_by'         => $this->staff,
                'potential_band' => $band,
            ] );
            $days -= 90;
        }
    }

    private function recordBehaviour( float $rating, string $notes ): void {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_player_behaviour_ratings", [
            'club_id'   => $this->club,
            'player_id' => $this->player_id,
            'rating'    => $rating,
            'notes'     => $notes,
            'rated_at'  => gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 10 * DAY_IN_SECONDS ),
            'rated_by'  => $this->staff,
        ] );
    }

    /** Neither half may be captured — the academy switched both off. */
    private function withdrawByFeature(): void {
        FeatureRegistry::setEnabled( 'behaviour_rating', false );
        FeatureRegistry::setEnabled( 'potential_rating', false );
    }

    /**
     * Neither half may be captured — by this user. The reported case: a
     * staff account that reads a player's file and holds neither act-cap.
     * `add_cap( …, false )` denies a role-granted capability per user.
     */
    private function withdrawByCapability(): void {
        $user = get_userdata( $this->staff );
        $this->assertInstanceOf( \WP_User::class, $user );
        $user->add_cap( 'tt_rate_player_behaviour', false );
        $user->add_cap( 'tt_set_player_potential', false );
        wp_set_current_user( $this->staff );
    }

    private function captureHtml(): string {
        $_GET['tt_view']   = 'player-status-capture';
        $_GET['player_id'] = (string) $this->player_id;
        ob_start();
        FrontendPlayerStatusCaptureView::render( get_current_user_id(), false );
        return (string) ob_get_clean();
    }

    /** The `<ol>` the trajectory renders into, so "Current:" can't fool an order assertion. */
    private function historyList( string $html ): string {
        $start = strpos( $html, '<ol class="tt-psc-history__list">' );
        $this->assertNotFalse( $start, 'The potential trajectory did not render.' );
        $end = strpos( $html, '</ol>', (int) $start );
        $this->assertNotFalse( $end );
        return substr( $html, (int) $start, (int) $end - (int) $start );
    }

    /** @param list<string> $needles */
    private function assertInOrder( array $needles, string $haystack ): void {
        $last = -1;
        foreach ( $needles as $needle ) {
            $at = strpos( $haystack, $needle );
            $this->assertNotFalse( $at, "Missing from the history: {$needle}" );
            $this->assertGreaterThan( $last, (int) $at, "Out of order: {$needle}" );
            $last = (int) $at;
        }
    }

    // --- the bug ---------------------------------------------------------

    public function test_a_staff_reader_without_the_capabilities_gets_the_history(): void {
        $this->withdrawByCapability();
        $html = $this->captureHtml();

        $this->assertStringContainsString( 'tt-psc-history__list', $html, 'The trajectory is still missing.' );
        $this->assertStringContainsString( 'Semi-pro', $html );
        $this->assertStringContainsString( 'Recent ratings', $html, 'The behaviour ratings are still missing.' );
        $this->assertStringContainsString( 'Led the warm-up.', $html );
    }

    public function test_a_staff_reader_gets_the_history_when_both_features_are_off(): void {
        $this->withdrawByFeature();
        $html = $this->captureHtml();

        $this->assertStringContainsString( 'tt-psc-history__list', $html );
        $this->assertStringContainsString( 'Recent ratings', $html );
    }

    /**
     * The notice still says what it said — the reader is told nothing is
     * being recorded here, and then shown what was.
     */
    public function test_the_notice_still_explains_why_there_is_no_form(): void {
        $this->withdrawByFeature();
        $html = $this->captureHtml();

        $this->assertStringContainsString( 'are not being recorded here', $html );
        $this->assertStringNotContainsString( 'name="potential_band"', $html );
        $this->assertStringNotContainsString( 'name="rating"', $html );
    }

    /** A history screen says so — the h1 no longer promises a capture form. */
    public function test_the_page_is_titled_as_a_history(): void {
        $this->withdrawByFeature();
        $this->assertStringContainsString( 'Behaviour &amp; potential history', $this->captureHtml() );
    }

    /**
     * The single-entry case the trajectory deliberately stays silent for:
     * without the current-band line, a band set exactly once would leave
     * the reader with the notice alone.
     */
    public function test_one_recorded_band_still_states_the_current_band(): void {
        global $wpdb;
        $wpdb->delete( "{$this->p}tt_player_potential", [ 'player_id' => $this->player_id ] );
        $wpdb->insert( "{$this->p}tt_player_potential", [
            'club_id'        => $this->club,
            'player_id'      => $this->player_id,
            'set_at'         => gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 30 * DAY_IN_SECONDS ),
            'set_by'         => $this->staff,
            'potential_band' => PotentialBand::TOP_AMATEUR,
        ] );

        $this->withdrawByFeature();
        $html = $this->captureHtml();

        $this->assertStringContainsString( 'Top amateur', $html );
        $this->assertStringNotContainsString( 'tt-psc-history__list', $html, 'One entry is not a trajectory.' );
    }

    // --- the screen and the API agree ------------------------------------

    /**
     * The acceptance criterion: what the page shows is what the same user
     * reads over REST, in the same sequence. The route returns oldest
     * first; the screen reads newest first, which is the same series.
     */
    public function test_the_history_matches_the_rest_route_for_the_same_user(): void {
        $this->withdrawByCapability();

        $response = rest_do_request( new WP_REST_Request( 'GET', "/talenttrack/v1/players/{$this->player_id}/potential" ) );
        $this->assertSame( 200, $response->get_status() );

        $data = $response->get_data();
        $this->assertIsArray( $data );
        $payload = is_array( $data['data'] ?? null ) ? $data['data'] : $data;
        $entries = $payload['entries'];
        $this->assertCount( count( self::SERIES ), $entries );

        $labels = array_reverse( array_map(
            static fn( array $entry ): string => (string) $entry['label'],
            $entries
        ) );

        $this->assertInOrder( $labels, $this->historyList( $this->captureHtml() ) );
    }

    // --- the boundary is unchanged ---------------------------------------

    public function test_a_viewer_without_read_access_gets_only_the_notice(): void {
        $outsider = self::factory()->user->create( [ 'role' => 'subscriber' ] );
        wp_set_current_user( $outsider );
        AuthorizationService::flushCache();

        $this->withdrawByFeature();
        $html = $this->captureHtml();

        $this->assertStringContainsString( 'are not being recorded here', $html );
        $this->assertStringNotContainsString( 'tt-psc-history__list', $html );
        $this->assertStringNotContainsString( 'Recent ratings', $html );
        $this->assertStringNotContainsString( 'Semi-pro', $html );
    }

    public function test_a_viewer_who_may_capture_still_gets_the_form(): void {
        $html = $this->captureHtml();

        $this->assertStringContainsString( 'name="potential_band"', $html );
        $this->assertStringContainsString( 'Capture behaviour &amp; potential', $html );
        $this->assertStringContainsString( 'tt-psc-history__list', $html, 'The capture screen kept its trajectory.' );
    }

    // --- the shared read check -------------------------------------------

    public function test_the_staff_read_check_answers_for_staff_and_refuses_everyone_else(): void {
        $this->assertTrue( AuthorizationService::isStaffForPlayer( $this->staff, $this->player_id ) );

        $outsider = self::factory()->user->create( [ 'role' => 'subscriber' ] );
        AuthorizationService::flushCache();
        $this->assertFalse( AuthorizationService::isStaffForPlayer( $outsider, $this->player_id ) );

        $this->assertFalse( AuthorizationService::isStaffForPlayer( 0, $this->player_id ) );
        $this->assertFalse( AuthorizationService::isStaffForPlayer( $this->staff, 0 ) );
    }
}
