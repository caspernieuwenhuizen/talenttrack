<?php
namespace TT\Tests\Php;

use ReflectionMethod;
use WP_UnitTestCase;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Activities\Repositories\ActivitiesRepository;
use TT\Shared\Frontend\FrontendMyActivitiesView;

/**
 * #3771 — the player's own activity screens name the time.
 *
 * `PlayerActivityReader::findForPlayer()` selects `a.*`, so the detail always
 * had `start_time`, `end_time` and `time_of_presence` on the row and simply
 * never printed one. "Coming up" was worse: its query did not fetch them at
 * all. A player could therefore learn the day of their next fixture from
 * TalentTrack and then had to text a coach to learn when to be there —
 * the most practical thing an activity tells a player.
 *
 * The staff detail already shows all three (#3678) off the shared
 * `ActivityTimeWindow` helper (#3679), so this is the same vocabulary
 * reaching the screen the player actually opens.
 */
final class MyActivitiesTimesTest extends WP_UnitTestCase {

    private string $p;
    private int $club;
    private int $team;
    private int $player;

    public function set_up(): void {
        parent::set_up();

        global $wpdb;
        $this->p    = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();

        $wpdb->insert( "{$this->p}tt_teams", [ 'club_id' => $this->club, 'name' => 'U11 Times' ] );
        $this->team = (int) $wpdb->insert_id;

        $wpdb->insert( "{$this->p}tt_players", [
            'club_id'    => $this->club,
            'team_id'    => $this->team,
            'first_name' => 'Timed',
            'last_name'  => 'Player',
            'status'     => 'active',
        ] );
        $this->player = (int) $wpdb->insert_id;
    }

    /* ---- the reported case ---------------------------------------------- */

    /** The activity from the report: meet 09:30, kick-off 10:00, end 11:15. */
    public function test_a_match_detail_shows_presence_kick_off_and_end(): void {
        $html = $this->renderDetail( $this->insertActivity( 'game', '10:00:00', '11:15:00', '09:30:00' ) );

        $this->assertStringContainsString( 'Presence time:', $html );
        $this->assertStringContainsString( '09:30', $html, 'the meet-up time' );
        $this->assertStringContainsString( 'Kick-off:', $html );
        $this->assertStringContainsString( '10:00', $html, 'the kick-off' );
        $this->assertStringContainsString( 'End time:', $html );
        $this->assertStringContainsString( '11:15', $html, 'the end time' );
    }

    /** Stored as `HH:MM:SS`; read as clock time. */
    public function test_the_times_render_without_seconds(): void {
        $html = $this->renderDetail( $this->insertActivity( 'game', '10:00:00', '11:15:00', '09:30:00' ) );

        $this->assertStringNotContainsString( '10:00:00', $html );
        $this->assertStringNotContainsString( '09:30:00', $html );
    }

    /** They read in the order the day happens. */
    public function test_the_detail_runs_presence_then_kick_off_then_end(): void {
        $html = $this->renderDetail( $this->insertActivity( 'game', '10:00:00', '11:15:00', '09:30:00' ) );

        $presence = strpos( $html, '09:30' );
        $kick     = strpos( $html, '10:00' );
        $end      = strpos( $html, '11:15' );

        $this->assertIsInt( $presence );
        $this->assertIsInt( $kick );
        $this->assertIsInt( $end );
        $this->assertLessThan( $kick, $presence );
        $this->assertLessThan( $end, $kick );
    }

    /* ---- no empty placeholders ------------------------------------------ */

    public function test_an_activity_with_no_times_renders_no_time_chips(): void {
        $html = $this->renderDetail( $this->insertActivity( 'training', null, null, null ) );

        $this->assertStringContainsString( 'Date:', $html, 'the rest of the meta row is unchanged' );
        $this->assertStringNotContainsString( 'Presence time:', $html );
        $this->assertStringNotContainsString( 'Kick-off:', $html );
        $this->assertStringNotContainsString( 'End time:', $html );
        $this->assertStringNotContainsString( 'Time:', $html );
    }

    public function test_a_match_without_the_optional_times_shows_only_the_kick_off(): void {
        $html = $this->renderDetail( $this->insertActivity( 'game', '10:00:00', null, null ) );

        $this->assertStringContainsString( 'Kick-off:', $html );
        $this->assertStringNotContainsString( 'Presence time:', $html );
        $this->assertStringNotContainsString( 'End time:', $html );
    }

    /* ---- the non-match branch ------------------------------------------- */

    /** A training reads one window rather than a kick-off it does not have. */
    public function test_a_training_shows_a_single_time_window(): void {
        $html = $this->renderDetail( $this->insertActivity( 'training', '18:30:00', '20:00:00', null ) );

        $this->assertStringContainsString( 'Time:', $html );
        $this->assertStringContainsString( '18:30–20:00', $html );
        $this->assertStringNotContainsString( 'Kick-off:', $html );
    }

    /**
     * A tournament is a multi-game day (#2686) and has no single kick-off,
     * so it reads as a window — matching the staff detail's own branch.
     */
    public function test_a_tournament_reads_as_a_window_not_a_kick_off(): void {
        $html = $this->renderDetail( $this->insertActivity( 'tournament', '09:30:00', '15:00:00', '09:00:00' ) );

        $this->assertStringNotContainsString( 'Kick-off:', $html );
        $this->assertStringContainsString( 'Time:', $html );
        $this->assertStringContainsString( '09:30–15:00', $html );
        $this->assertStringContainsString( 'Presence time:', $html );
        $this->assertStringContainsString( '09:00', $html );
    }

    /** An open-ended activity reads as its start, not as a dangling dash. */
    public function test_a_training_without_an_end_shows_the_start_alone(): void {
        $html = $this->renderDetail( $this->insertActivity( 'training', '18:30:00', null, null ) );

        $this->assertStringContainsString( 'Time:', $html );
        $this->assertStringContainsString( '18:30', $html );
        $this->assertStringNotContainsString( '18:30–', $html );
    }

    /* ---- Coming up ------------------------------------------------------ */

    public function test_upcoming_for_team_returns_the_stored_times(): void {
        $this->insertActivity( 'training', '18:30:00', '20:00:00', '18:15:00', '+3 days' );

        $rows = ( new ActivitiesRepository() )->upcomingForTeam( $this->team, 5 );
        $this->assertNotEmpty( $rows, 'the upcoming peek finds the activity' );

        $row = (array) $rows[0];
        $this->assertSame( '18:30:00', (string) $row['start_time'] );
        $this->assertSame( '20:00:00', (string) $row['end_time'] );
        $this->assertSame( '18:15:00', (string) $row['time_of_presence'] );
        // The columns the existing consumers read are still there.
        $this->assertArrayHasKey( 'title', $row );
        $this->assertArrayHasKey( 'session_date', $row );
        $this->assertArrayHasKey( 'location', $row );
    }

    public function test_the_coming_up_card_shows_the_time(): void {
        $this->insertActivity( 'training', '18:30:00', '20:00:00', null, '+4 days' );

        $html = $this->renderComingUp();

        $this->assertStringContainsString( 'Coming up', $html );
        $this->assertStringContainsString( 'tt-myact-upcoming__time', $html );
        $this->assertStringContainsString( '18:30–20:00', $html );
    }

    public function test_the_coming_up_card_omits_the_time_when_there_is_none(): void {
        $this->insertActivity( 'training', null, null, null, '+5 days' );

        $html = $this->renderComingUp();

        $this->assertStringContainsString( 'Coming up', $html );
        $this->assertStringNotContainsString( 'tt-myact-upcoming__time', $html );
    }

    /* ---- helpers -------------------------------------------------------- */

    private function insertActivity(
        string $type,
        ?string $start,
        ?string $end,
        ?string $presence,
        string $when = '+3 days'
    ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_activities", [
            'club_id'             => $this->club,
            'team_id'             => $this->team,
            'title'               => 'Activity ' . $type . ' ' . $when,
            'session_date'        => gmdate( 'Y-m-d', (int) strtotime( $when ) ),
            'location'            => 'Thuisveld',
            'activity_type_key'   => $type,
            'activity_status_key' => 'planned',
            'start_time'          => $start,
            'end_time'            => $end,
            'time_of_presence'    => $presence,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function renderDetail( int $activity_id ): string {
        $method = new ReflectionMethod( FrontendMyActivitiesView::class, 'renderDetail' );
        $method->setAccessible( true );

        ob_start();
        $method->invoke( null, (object) [ 'id' => $this->player, 'team_id' => $this->team ], $activity_id );
        return (string) ob_get_clean();
    }

    private function renderComingUp(): string {
        $method = new ReflectionMethod( FrontendMyActivitiesView::class, 'renderComingUp' );
        $method->setAccessible( true );

        ob_start();
        $method->invoke( null, (object) [ 'id' => $this->player, 'team_id' => $this->team ] );
        return (string) ob_get_clean();
    }
}
