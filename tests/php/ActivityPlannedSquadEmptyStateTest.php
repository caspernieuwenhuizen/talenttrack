<?php
namespace TT\Tests\Php;

use ReflectionMethod;
use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Domain\Vocabularies\Lookups\ActivityStatusKey;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Activities\Repositories\ActivitiesRepository;
use TT\Shared\Frontend\FrontendActivitiesManageView;

/**
 * #3847 — an activity with no squad says so, and offers the way in.
 *
 * `renderPlannedAttendance()` loaded the planned roster and returned on an
 * empty one, so an activity nobody had picked a squad for showed **no card
 * at all**. That card is the only place on the detail page that links into
 * the plan editor, so the prompt and the route onward disappeared together:
 * a coach opening the activity the evening before a training saw date,
 * time, type and notes, and went back to paper.
 *
 * #3800 keeps most activities off this state by seeding a team activity's
 * roster at creation. It is not redundant with it: an activity with no
 * team is never seeded, activities created before #3800 are not
 * backfilled, and a coach who empties a squad on purpose must not be left
 * on a dead-end screen.
 */
final class ActivityPlannedSquadEmptyStateTest extends WP_UnitTestCase {

    private int $team_id = 0;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->ensureCapabilities();

        global $wpdb;
        $wpdb->hide_errors();

        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        $wpdb->insert( $wpdb->prefix . 'tt_teams', [ 'club_id' => 1, 'name' => 'Ajax U17' ] );
        $this->team_id = (int) $wpdb->insert_id;
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    // ---- fixtures ---------------------------------------------------------

    /**
     * An activity with no team, so #3800's seeder leaves its planned
     * roster alone — the state this card has to cope with after that
     * shipped.
     *
     * The status is stamped afterwards rather than posted: `extract()`
     * validates it against the `activity_status` lookup and falls back to
     * `planned` when the lookup is not seeded, which would quietly make
     * two of the cases below test the same thing.
     */
    private function createTeamlessActivity( string $status = ActivityStatusKey::PLANNED ): int {
        $request = new WP_REST_Request( 'POST', '/talenttrack/v1/activities' );
        $request->set_header( 'Content-Type', 'application/json' );
        $request->set_body( (string) wp_json_encode( [
            'title'             => 'Keeperstraining',
            'session_date'      => '2026-11-20',
            'start_time'        => '18:30',
            'end_time'          => '20:00',
            'activity_type_key' => 'training',
        ] ) );

        $response = rest_get_server()->dispatch( $request );
        $this->assertSame( 200, (int) $response->get_status(), 'the activity is created' );

        $data        = json_decode( (string) wp_json_encode( $response->get_data() ), true );
        $activity_id = (int) ( $data['data']['id'] ?? 0 );
        $this->assertGreaterThan( 0, $activity_id );

        if ( $status !== ActivityStatusKey::PLANNED ) {
            global $wpdb;
            $wpdb->update(
                $wpdb->prefix . 'tt_activities',
                [ 'activity_status_key' => $status ],
                [ 'id' => $activity_id ]
            );
        }

        return $activity_id;
    }

    private function plannedCount( int $activity_id ): int {
        $response = rest_get_server()->dispatch(
            new WP_REST_Request( 'GET', '/talenttrack/v1/activities/' . $activity_id . '/planned-attendance' )
        );
        $data = json_decode( (string) wp_json_encode( $response->get_data() ), true );

        return (int) ( $data['data']['count'] ?? $data['count'] ?? -1 );
    }

    private function renderCard( int $activity_id ): string {
        $row = ( new ActivitiesRepository() )->findByIdIncludingArchived( $activity_id );
        $this->assertNotNull( $row, 'the activity row loads' );

        $method = new ReflectionMethod( FrontendActivitiesManageView::class, 'renderPlannedAttendance' );
        $method->setAccessible( true );

        ob_start();
        $method->invoke( null, $row );

        return (string) ob_get_clean();
    }

    // ---- the reported case -------------------------------------------------

    public function test_an_activity_with_no_planned_squad_shows_the_card_and_the_way_in(): void {
        $activity_id = $this->createTeamlessActivity();

        $this->assertSame( 0, $this->plannedCount( $activity_id ), 'nothing was planned for it' );

        $html = $this->renderCard( $activity_id );

        $this->assertNotSame( '', trim( $html ), 'the card renders rather than nothing' );
        $this->assertStringContainsString( 'tt-act-card-d', $html );
        $this->assertStringContainsString( 'Expected attendance', $html );
        $this->assertStringContainsString( 'No squad picked yet.', $html );
        $this->assertStringContainsString( 'Pick the squad', $html );
        $this->assertStringContainsString( 'action=edit', html_entity_decode( $html ) );
    }

    public function test_a_reader_who_may_not_edit_sees_the_state_without_the_link(): void {
        $activity_id = $this->createTeamlessActivity();

        wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

        $html = $this->renderCard( $activity_id );

        $this->assertStringContainsString( 'No squad picked yet.', $html );
        $this->assertStringNotContainsString( 'Pick the squad', $html );
        $this->assertStringNotContainsString( 'action=edit', html_entity_decode( $html ) );
    }

    /**
     * The attendance summary answers for a played activity, and offering
     * to pick a squad for it is a stale affordance.
     */
    public function test_a_completed_activity_with_no_plan_is_unchanged(): void {
        $activity_id = $this->createTeamlessActivity( ActivityStatusKey::COMPLETED );

        $this->assertSame( '', trim( $this->renderCard( $activity_id ) ) );
    }

    /** Nothing left to build on a cancelled one, so the card states it and stops. */
    public function test_a_cancelled_activity_states_it_without_offering_the_editor(): void {
        $activity_id = $this->createTeamlessActivity( ActivityStatusKey::CANCELLED );

        $html = $this->renderCard( $activity_id );

        $this->assertStringContainsString( 'No squad picked yet.', $html );
        $this->assertStringNotContainsString( 'Pick the squad', $html );
    }

    /** A squad that exists still renders exactly as it did. */
    public function test_an_activity_with_a_squad_still_lists_it(): void {
        global $wpdb;

        $activity_id = $this->createTeamlessActivity();

        $wpdb->insert( $wpdb->prefix . 'tt_players', [
            'club_id'    => 1,
            'team_id'    => $this->team_id,
            'first_name' => 'Youri',
            'last_name'  => 'Jansen',
        ] );
        $player_id = (int) $wpdb->insert_id;

        ( new ActivitiesRepository() )->replacePlannedAttendance( $activity_id, [
            $player_id => [ 'status' => '', 'notes' => '' ],
        ] );

        $html = $this->renderCard( $activity_id );

        $this->assertStringContainsString( 'Youri', $html );
        $this->assertStringNotContainsString( 'No squad picked yet.', $html );
        $this->assertStringContainsString( 'Edit plan', $html );
    }

    /** At 360px the card is one column of text and a 48px-tall link. */
    public function test_the_empty_state_uses_the_shared_card_classes(): void {
        $html = $this->renderCard( $this->createTeamlessActivity() );

        $this->assertStringContainsString( 'tt-act-card-d__head', $html );
        $this->assertStringContainsString( 'tt-act-card-d__title', $html );
        $this->assertStringContainsString( 'tt-act-card-d__body', $html );
        $this->assertStringContainsString( 'tt-act-card-d__link', $html );
    }
}
