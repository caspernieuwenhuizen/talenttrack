<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Activities\Services\ActivityTimeWindow;
use TT\Modules\Authorization\Matrix\MatrixRepository;

/**
 * #3679 — the activity peek answers "when and where".
 *
 * `GET /activities/{id}/summary` returned a date and a type. A parent
 * deciding whether to open Tuesday's training is asking what time to be
 * there and which pitch, and neither was in the payload, so she read the
 * team WhatsApp instead. The peek now carries the time window, the presence
 * time and the location, in the order the panel reads them.
 *
 * Empty facts are still dropped, so an activity with no times and no
 * location peeks exactly as it did before.
 */
final class ActivityPeekWhenWhereTest extends WP_UnitTestCase {

    private int $club = 0;
    private int $team = 0;
    private int $hod  = 0;

    public function set_up(): void {
        parent::set_up();
        global $wp_rest_server, $wpdb;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        ( new RolesService() )->installRoles();
        MatrixRepository::clearCache();

        $this->club = (int) CurrentClub::id();
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => $this->club, 'name' => 'Peek JO11-1' ] );
        $this->team = (int) $wpdb->insert_id;

        // Global activities read, so every assertion below is about the
        // payload rather than about who may see it (#3688 covers that).
        $this->hod = self::factory()->user->create( [ 'role' => 'tt_head_dev' ] );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    /**
     * @param array<string,mixed> $extra
     */
    private function activity( array $extra = [] ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_activities", array_merge( [
            'club_id'             => $this->club,
            'team_id'             => $this->team,
            'title'               => 'JO11 training',
            'session_date'        => gmdate( 'Y-m-d', strtotime( '+2 days' ) ),
            'activity_type_key'   => 'training',
            'activity_status_key' => 'planned',
        ], $extra ) );
        return (int) $wpdb->insert_id;
    }

    /**
     * The peek's facts as `label => value`, in the order they are returned.
     *
     * @return array<string,string>
     */
    private function facts( int $activity_id ): array {
        wp_set_current_user( $this->hod );
        $res = rest_do_request(
            new WP_REST_Request( 'GET', '/talenttrack/v1/activities/' . $activity_id . '/summary' )
        );
        $this->assertSame( 200, $res->get_status() );

        $data  = (array) $res->get_data();
        $facts = [];
        foreach ( (array) ( $data['facts'] ?? [] ) as $fact ) {
            $row = (array) $fact;
            $facts[ (string) ( $row['label'] ?? '' ) ] = (string) ( $row['value'] ?? '' );
        }
        return $facts;
    }

    public function test_a_training_peek_carries_its_time_presence_and_location(): void {
        $id = $this->activity( [
            'start_time'       => '18:30:00',
            'end_time'         => '20:00:00',
            'time_of_presence' => '18:00:00',
            'location'         => 'Trainingsveld',
        ] );

        $facts = $this->facts( $id );

        $this->assertSame( '18:30–20:00', $facts['Time'] ?? '' );
        $this->assertSame( '18:00', $facts['Presence time'] ?? '' );
        $this->assertSame( 'Trainingsveld', $facts['Location'] ?? '' );

        $this->assertSame(
            [ 'Date', 'Time', 'Presence time', 'Location', 'Type' ],
            array_keys( $facts ),
            'The panel reads the facts top to bottom: what day, what time, be there by, where, what kind.'
        );
    }

    public function test_an_open_ended_activity_shows_its_start_alone(): void {
        $id = $this->activity( [ 'start_time' => '18:30:00', 'location' => 'Trainingsveld' ] );

        $facts = $this->facts( $id );

        $this->assertSame( '18:30', $facts['Time'] ?? '' );
        $this->assertArrayNotHasKey(
            'Presence time',
            $facts,
            'An unset presence time is absent, not an empty row.'
        );
    }

    public function test_an_activity_with_no_times_or_location_peeks_as_before(): void {
        $facts = $this->facts( $this->activity() );

        $this->assertSame( [ 'Date', 'Type' ], array_keys( $facts ) );
    }

    /**
     * The formatter itself, including the branch the peek never reaches
     * because the envelope would drop the fact anyway.
     */
    public function test_the_time_window_formatter(): void {
        $this->assertSame( '18:30–20:00', ActivityTimeWindow::format( '18:30:00', '20:00:00' ) );
        $this->assertSame( '18:30', ActivityTimeWindow::format( '18:30:00', '' ) );
        $this->assertSame( '', ActivityTimeWindow::format( '', '20:00:00' ) );
        $this->assertSame( '', ActivityTimeWindow::format( '', '' ) );
        $this->assertSame( '18:00', ActivityTimeWindow::clock( '18:00:00' ) );
        $this->assertSame( '', ActivityTimeWindow::clock( '' ) );
    }
}
