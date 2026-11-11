<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\Spond\CredentialsManager;
use TT\Modules\Spond\SpondSync;

/**
 * #5072 — the Spond sync finds the activity to update on the syncing team
 * first, then club-wide, lowest id first.
 *
 * It used to match an event's `external_id` across the whole club with no
 * order, so when two activities shared the id (a same-install import, two
 * teams on one Spond group) the schedule landed on whichever row came back.
 * A coach's move to another team in TalentTrack still has to be found by
 * the old team's feed, or the event is inserted twice.
 *
 * The sync runs end to end, with the Spond login and event feed answered
 * through `pre_http_request`, as in `SpondSyncLockedFieldsTest`.
 */
final class SpondSyncTeamMatchTest extends WP_UnitTestCase {

    private const UID = 'spond-ev-5072';

    private int $team_a = 0;
    private int $team_b = 0;
    private int $team_c = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $wpdb->hide_errors();

        $this->team_a = $this->team( 'JO13-1', 'grp-5072' );
        $this->team_b = $this->team( 'JO13-2', '' );
        $this->team_c = $this->team( 'JO13-3', '' );
        CredentialsManager::save( 'coach@example.test', 'secret' );
        add_filter( 'pre_http_request', [ $this, 'fakeSpond' ], 10, 3 );
    }

    public function tear_down(): void {
        remove_filter( 'pre_http_request', [ $this, 'fakeSpond' ], 10 );
        parent::tear_down();
    }

    /**
     * @param mixed               $pre
     * @param array<string,mixed> $args
     * @return mixed
     */
    public function fakeSpond( $pre, $args, string $url ) {
        if ( strpos( $url, '/auth2/login' ) !== false ) {
            return $this->response( [ 'accessToken' => [ 'token' => 'test-token' ] ] );
        }
        if ( strpos( $url, '/sponds/' ) !== false ) {
            return $this->response( [ $this->event( 'Training vanuit Spond' ) ] );
        }
        return $pre;
    }

    public function test_two_teams_sharing_an_event_id_only_the_syncing_teams_row_is_updated(): void {
        $other = $this->activity( $this->team_b, 'Kopie van het andere team' );
        $own   = $this->activity( $this->team_a, 'Oude titel' );

        $result = $this->sync();

        $this->assertSame( 1, (int) $result['updated_count'] );
        $this->assertSame( 0, (int) $result['created_count'] );
        $this->assertSame( 'Training vanuit Spond', $this->row( $own )['title'], 'team A\'s own row follows Spond' );
        $this->assertSame( 'Kopie van het andere team', $this->row( $other )['title'], 'the other team\'s row, the lower id, is left alone' );
        $this->assertSame( 2, $this->countFor( self::UID ) );
    }

    public function test_an_activity_moved_to_another_team_is_updated_not_inserted_again(): void {
        $moved = $this->activity( $this->team_b, 'Verplaatst door de coach' );

        $result = $this->sync();

        $this->assertSame( 1, (int) $result['updated_count'] );
        $this->assertSame( 0, (int) $result['created_count'], 'no duplicate for the old team' );
        $this->assertSame( 1, $this->countFor( self::UID ) );
        $row = $this->row( $moved );
        $this->assertSame( 'Training vanuit Spond', $row['title'], 'Spond owns the schedule fields' );
        $this->assertSame( $this->team_b, (int) $row['team_id'], 'the move stays as the coach set it' );
    }

    public function test_with_no_row_on_the_team_the_lowest_club_wide_id_is_updated(): void {
        $low  = $this->activity( $this->team_b, 'Eerste' );
        $high = $this->activity( $this->team_c, 'Tweede' );

        $result = $this->sync();

        $this->assertSame( 0, (int) $result['created_count'] );
        $this->assertSame( 'Training vanuit Spond', $this->row( $low )['title'], 'the lowest id' );
        $this->assertSame( 'Tweede', $this->row( $high )['title'] );
        $this->assertSame( $this->team_b, (int) $this->row( $low )['team_id'] );
    }

    public function test_a_new_event_is_inserted_on_the_syncing_team(): void {
        $result = $this->sync();

        $this->assertSame( 1, (int) $result['created_count'] );
        global $wpdb;
        $team = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT team_id FROM {$wpdb->prefix}tt_activities WHERE external_id = %s AND activity_source_key = 'spond'",
            self::UID
        ) );
        $this->assertSame( $this->team_a, $team );
    }

    /** The re-sync update array still carries no team. */
    public function test_the_update_never_writes_the_team(): void {
        $src   = (string) file_get_contents( (string) ( new \ReflectionClass( SpondSync::class ) )->getFileName() );
        $start = strpos( $src, '$update   = [' );
        $this->assertNotFalse( $start, 'the re-sync update array moved; re-point this test' );
        $end = strpos( $src, '] + self::timeColumns', $start );
        $this->assertNotFalse( $end );
        $this->assertStringNotContainsString( 'team_id', substr( $src, $start, $end - $start ) );
    }

    // Fixtures

    /** @return array<string,mixed> */
    private function sync(): array {
        $result = SpondSync::syncTeam( $this->team_a );
        $this->assertSame( 'ok', $result['status'] ?? '', (string) ( $result['last_message'] ?? 'sync failed' ) );
        return $result;
    }

    private function team( string $name, string $group ): int {
        global $wpdb;
        $row = [ 'club_id' => 1, 'name' => $name ];
        if ( $group !== '' ) $row['spond_group_id'] = $group;
        $wpdb->insert( "{$wpdb->prefix}tt_teams", $row );
        return (int) $wpdb->insert_id;
    }

    private function activity( int $team_id, string $title ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_activities", [
            'club_id'             => 1,
            'team_id'             => $team_id,
            'title'               => $title,
            'session_date'        => gmdate( 'Y-m-d', time() + 5 * DAY_IN_SECONDS ),
            'activity_type_key'   => 'training',
            'activity_status_key' => 'planned',
            'activity_source_key' => 'spond',
            'external_id'         => self::UID,
        ] );
        return (int) $wpdb->insert_id;
    }

    /** @return array<string,mixed> */
    private function row( int $id ): array {
        global $wpdb;
        return (array) $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}tt_activities WHERE id = %d", $id ), ARRAY_A );
    }

    private function countFor( string $uid ): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_activities WHERE external_id = %s AND activity_source_key = 'spond'",
            $uid
        ) );
    }

    /** @return array<string,mixed> */
    private function event( string $heading ): array {
        $start = strtotime( gmdate( 'Y-m-d', time() + 6 * DAY_IN_SECONDS ) . ' 18:30:00 UTC' );
        return [
            'id'              => self::UID,
            'heading'         => $heading,
            'startTimestamp'  => gmdate( 'Y-m-d\TH:i:s.000\Z', (int) $start ),
            'endTimestamp'    => gmdate( 'Y-m-d\TH:i:s.000\Z', (int) $start + 90 * MINUTE_IN_SECONDS ),
            'location'        => [ 'feature' => 'Sportpark De Hoef' ],
        ];
    }

    /**
     * @param mixed $body
     * @return array<string,mixed>
     */
    private function response( $body ): array {
        return [
            'headers'  => [],
            'body'     => (string) wp_json_encode( $body ),
            'response' => [ 'code' => 200, 'message' => 'OK' ],
            'cookies'  => [],
            'filename' => null,
        ];
    }
}
