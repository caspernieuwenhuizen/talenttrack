<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_UnitTestCase;
use TT\Modules\Activities\Domain\SyncLockedFields;
use TT\Modules\Activities\Repositories\ActivitiesRepository;
use TT\Modules\Spond\CredentialsManager;
use TT\Modules\Spond\SpondSync;

/**
 * #4485 — a field group a person edits on a Spond activity is locked, and
 * the Spond sync leaves it alone until someone chooses to follow Spond again.
 *
 * The sync runs end to end: the Spond login and event feed are answered
 * through `pre_http_request`, so the write path under test is the real one.
 */
final class SpondSyncLockedFieldsTest extends WP_UnitTestCase {

    private const UID = 'spond-ev-4485';

    /** @var int */
    private $team = 0;

    /** @var array<string,mixed> */
    private $event = [];

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $wpdb->hide_errors();

        $wpdb->insert( "{$wpdb->prefix}tt_teams", [
            'club_id'        => 1,
            'name'           => 'JO14-1',
            'spond_group_id' => 'grp-4485',
        ] );
        $this->team = (int) $wpdb->insert_id;
        CredentialsManager::save( 'coach@example.test', 'secret' );

        $this->event = $this->spondEvent( 'JO14-1 - vv Hedel', 10 );
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
            return $this->response( [ $this->event ] );
        }
        return $pre;
    }

    public function test_a_sync_after_an_edit_keeps_the_edited_group_and_follows_spond_elsewhere(): void {
        $id = $this->importedActivity();

        $this->assertTrue( ( new ActivitiesRepository() )->update( $id, $this->formSave( $id, [ 'title' => 'JO14-1 - VV Hedel 2' ] ) ) );
        $this->assertSame( [ 'title' ], $this->locks( $id ) );

        $this->event = $this->spondEvent( 'JO14-1 - vv Hedel (verplaatst)', 11 );
        $this->sync();

        $row = $this->row( $id );
        $this->assertSame( 'JO14-1 - VV Hedel 2', $row['title'], 'the coach\'s title survives the sync' );
        $this->assertSame( $this->localDate( 11 ), $row['session_date'], 'the date nobody edited follows Spond' );
    }

    public function test_after_follow_spond_again_the_next_sync_writes_the_group(): void {
        $id   = $this->importedActivity();
        $repo = new ActivitiesRepository();
        $repo->update( $id, $this->formSave( $id, [ 'title' => 'Coach title' ] ) );

        $this->assertSame( [], $repo->unlockSyncGroup( $id, 'title' ) );

        $this->event = $this->spondEvent( 'Spond title', 10 );
        $this->sync();

        $this->assertSame( 'Spond title', $this->row( $id )['title'] );
        $this->assertSame( [], $this->locks( $id ) );
    }

    public function test_an_unchanged_save_locks_nothing(): void {
        $id     = $this->importedActivity();
        $stored = $this->row( $id );

        // The edit form posts times as HH:MM; the row holds HH:MM:SS.
        ( new ActivitiesRepository() )->update( $id, $this->formSave( $id, [
            'start_time' => substr( (string) $stored['start_time'], 0, 5 ),
        ] ) );

        $this->assertNull( $this->row( $id )['sync_locked_fields'] );
    }

    public function test_a_times_edit_locks_kickoff_with_the_group(): void {
        $id = $this->importedActivity();
        ( new ActivitiesRepository() )->update( $id, $this->formSave( $id, [ 'start_time' => '09:15:00' ] ) );
        $this->assertSame( [ 'times' ], $this->locks( $id ) );

        $this->event = $this->spondEvent( 'JO14-1 - vv Hedel', 10, '14:00' );
        $this->sync();

        $row = $this->row( $id );
        $this->assertSame( '09:15:00', $row['start_time'] );
        $this->assertNotSame( '14:00:00', (string) $row['kickoff_time'], 'kickoff is part of the times group' );
    }

    public function test_a_manual_activity_never_gets_locks(): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_activities", [
            'club_id'             => 1,
            'team_id'             => $this->team,
            'title'               => 'Training',
            'session_date'        => $this->localDate( 3 ),
            'activity_type_key'   => 'training',
            'activity_status_key' => 'planned',
            'activity_source_key' => 'manual',
        ] );
        $id = (int) $wpdb->insert_id;

        ( new ActivitiesRepository() )->update( $id, [ 'title' => 'Renamed training' ] );

        $this->assertNull( $this->row( $id )['sync_locked_fields'] );
    }

    public function test_the_list_carries_the_locks_and_delete_returns_what_remains(): void {
        $id   = $this->importedActivity();
        $repo = new ActivitiesRepository();
        $repo->update( $id, $this->formSave( $id, [ 'title' => 'Coach title', 'location' => 'Veld 3' ] ) );

        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        do_action( 'rest_api_init' );

        $list = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/talenttrack/v1/activities' ) );
        $this->assertSame( 200, $list->get_status() );
        $rows = (array) ( $list->get_data()['data']['rows'] ?? $list->get_data()['rows'] ?? [] );
        $mine = array_values( array_filter( $rows, static function ( $r ) use ( $id ): bool { return (int) $r['id'] === $id; } ) );
        $this->assertCount( 1, $mine );
        $this->assertSame( [ 'title', 'location' ], $mine[0]['sync_locked_fields'] );

        $res  = rest_get_server()->dispatch( new WP_REST_Request( 'DELETE', "/talenttrack/v1/activities/{$id}/sync-locks/title" ) );
        $this->assertSame( 200, $res->get_status() );
        $data = (array) ( $res->get_data()['data'] ?? $res->get_data() );
        $this->assertSame( [ 'location' ], $data['sync_locked_fields'] );

        $bad = rest_get_server()->dispatch( new WP_REST_Request( 'DELETE', "/talenttrack/v1/activities/{$id}/sync-locks/opponent" ) );
        $this->assertSame( 400, $bad->get_status(), 'the opponent is not a lock group' );
    }

    public function test_unlocking_needs_the_edit_capability(): void {
        $id = $this->importedActivity();
        ( new ActivitiesRepository() )->update( $id, $this->formSave( $id, [ 'title' => 'Coach title' ] ) );

        wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
        do_action( 'rest_api_init' );
        $res = rest_get_server()->dispatch( new WP_REST_Request( 'DELETE', "/talenttrack/v1/activities/{$id}/sync-locks/title" ) );

        $this->assertContains( $res->get_status(), [ 401, 403 ] );
        $this->assertSame( [ 'title' ], $this->locks( $id ) );
    }

    /**
     * Through the real edit path: `PUT` lays the body over the stored row
     * and runs it through `extract()`, which reshapes times and derives
     * `kickoff_time`. A save that changes only the notes must lock nothing.
     */
    public function test_a_rest_edit_of_only_the_notes_locks_nothing(): void {
        $id = $this->importedActivity();

        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        do_action( 'rest_api_init' );
        $req = new WP_REST_Request( 'PUT', "/talenttrack/v1/activities/{$id}" );
        $req->set_body_params( [ 'notes' => 'Bring the away kit.' ] );
        $res = rest_get_server()->dispatch( $req );

        $this->assertSame( 200, $res->get_status(), (string) wp_json_encode( $res->get_data() ) );
        $row = $this->row( $id );
        $this->assertSame( 'Bring the away kit.', $row['notes'], 'the edit was written' );
        $this->assertNull( $row['sync_locked_fields'], 'a notes-only save through PUT locks no group' );
    }

    public function test_a_coach_outside_the_team_is_refused_and_the_lock_stays(): void {
        global $wpdb;
        $id = $this->importedActivity();
        ( new ActivitiesRepository() )->update( $id, $this->formSave( $id, [ 'title' => 'Coach title' ] ) );

        ( new \TT\Infrastructure\Security\RolesService() )->installRoles();
        \TT\Modules\Authorization\Matrix\MatrixRepository::clearCache();

        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => 1, 'name' => 'JO15-1' ] );
        $other_team = (int) $wpdb->insert_id;
        $coach      = (int) self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $wpdb->insert( "{$wpdb->prefix}tt_people", [
            'club_id'    => 1,
            'first_name' => 'Other',
            'last_name'  => 'Coach',
            'role_type'  => 'head_coach',
            'wp_user_id' => $coach,
            'status'     => 'active',
        ] );
        $wpdb->insert( "{$wpdb->prefix}tt_user_role_scopes", [
            'person_id'  => (int) $wpdb->insert_id,
            'role_id'    => 1,
            'scope_type' => 'team',
            'scope_id'   => $other_team,
        ] );
        wp_set_current_user( $coach );
        $this->assertTrue( \TT\Infrastructure\REST\ActivitiesRestController::can_edit(), 'the coach holds the edit capability, so the refusal is about the team' );

        $req = new WP_REST_Request( 'DELETE', "/talenttrack/v1/activities/{$id}/sync-locks/title" );
        $req->set_url_params( [ 'id' => (string) $id, 'group' => 'title' ] );
        $res = \TT\Infrastructure\REST\ActivitiesRestController::unlock_sync_group( $req );

        $this->assertSame( 403, $res->get_status() );
        $body = (array) $res->get_data();
        $this->assertSame( 'forbidden_team', (string) ( $body['errors'][0]['code'] ?? '' ) );
        $this->assertSame( [ 'title' ], $this->locks( $id ), 'a refused unlock leaves the lock in place' );
    }

    public function test_the_lock_rules(): void {
        $stored = [ 'activity_source_key' => 'spond', 'sync_locked_fields' => '["date"]', 'title' => 'A', 'session_date' => '2026-11-01', 'location' => 'X' ];
        $this->assertSame( '["title","date"]', SyncLockedFields::afterEdit( $stored, [ 'title' => 'B' ] ) );
        $this->assertNull( SyncLockedFields::afterEdit( $stored, [ 'session_date' => '2026-11-02' ] ), 'already locked' );
        $this->assertNull( SyncLockedFields::afterEdit( $stored, [ 'title' => ' A ' ] ), 'whitespace is not a change' );
        $this->assertSame(
            [ 'location' => 'Y', 'opponent' => 'Z' ],
            SyncLockedFields::stripLocked( [ 'title' => 'T', 'location' => 'Y', 'opponent' => 'Z' ], [ 'title' ] )
        );
    }

    // Fixtures

    private function sync(): void {
        $result = SpondSync::syncTeam( $this->team );
        $this->assertSame( 'ok', $result['status'] ?? '', (string) ( $result['last_message'] ?? 'sync failed' ) );
    }

    private function importedActivity(): int {
        $this->sync();
        global $wpdb;
        $id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}tt_activities WHERE external_id = %s AND activity_source_key = 'spond'",
            self::UID
        ) );
        $this->assertGreaterThan( 0, $id, 'the Spond event was imported' );
        $this->assertNull( $this->row( $id )['sync_locked_fields'], 'an import locks nothing' );
        return $id;
    }

    /**
     * What the edit form writes: every lockable field as stored, with the
     * given changes on top.
     *
     * @param array<string,mixed> $changes
     * @return array<string,mixed>
     */
    private function formSave( int $id, array $changes ): array {
        $row = $this->row( $id );
        return $changes + [
            'title'            => $row['title'],
            'session_date'     => $row['session_date'],
            'location'         => $row['location'],
            'start_time'       => $row['start_time'],
            'end_time'         => $row['end_time'],
            'time_of_presence' => $row['time_of_presence'],
            'notes'            => $row['notes'],
        ];
    }

    /** @return array<string,mixed> */
    private function row( int $id ): array {
        global $wpdb;
        return (array) $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tt_activities WHERE id = %d",
            $id
        ), ARRAY_A );
    }

    /** @return list<string> */
    private function locks( int $id ): array {
        return SyncLockedFields::decode( $this->row( $id )['sync_locked_fields'] ?? null );
    }

    /** @return array<string,mixed> */
    private function spondEvent( string $heading, int $days_ahead, string $start = '18:30' ): array {
        $day   = gmdate( 'Y-m-d', time() + $days_ahead * DAY_IN_SECONDS );
        $start_ts = strtotime( $day . ' ' . $start . ':00 UTC' );
        return [
            'id'              => self::UID,
            'heading'         => $heading,
            'startTimestamp'  => gmdate( 'Y-m-d\TH:i:s.000\Z', (int) $start_ts ),
            'endTimestamp'    => gmdate( 'Y-m-d\TH:i:s.000\Z', (int) $start_ts + 90 * MINUTE_IN_SECONDS ),
            'meetupTimestamp' => gmdate( 'Y-m-d\TH:i:s.000\Z', (int) $start_ts - 45 * MINUTE_IN_SECONDS ),
            'location'        => [ 'feature' => 'Sportpark De Hoef' ],
            'matchEvent'      => true,
        ];
    }

    private function localDate( int $days_ahead ): string {
        $utc = gmdate( 'Y-m-d', time() + $days_ahead * DAY_IN_SECONDS ) . ' 18:30:00';
        return ( new \DateTimeImmutable( $utc, new \DateTimeZone( 'UTC' ) ) )->setTimezone( wp_timezone() )->format( 'Y-m-d' );
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
