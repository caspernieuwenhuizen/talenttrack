<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_UnitTestCase;
use TT\Infrastructure\REST\StravaRestController;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Strava\ActivityIngestService;
use TT\Modules\Strava\ActivityRepository;
use TT\Modules\Strava\ConnectionRepository;
use TT\Modules\Strava\TokenRefreshService;

/**
 * #2058 (epic #2002) — Strava activity ingest.
 *
 * The headline invariant is Gate 1: heart-rate (and any biometric) data
 * NEVER enters the model. `mapNonHr()` is an explicit allowlist, so a
 * payload carrying `average_heartrate` is mapped with that field silently
 * dropped — there's no column for it and the mapper never reads it.
 *
 * Also covered: upsert-by-external-id, soft-archive on delete + deauth,
 * and that the read endpoint surfaces no token / HR field.
 */
final class StravaActivityIngestTest extends WP_UnitTestCase {

    private string $p;
    private ActivityRepository $activities;
    private ConnectionRepository $connections;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->p           = $wpdb->prefix;
        $this->activities  = new ActivityRepository();
        $this->connections = new ConnectionRepository();
    }

    private function samplePayload(): array {
        return [
            'id'                   => 555,
            'name'                 => 'Morning Run',
            'sport_type'           => 'Run',
            'start_date'           => '2026-06-28T06:30:00Z',
            'distance'             => 5025.4,
            'moving_time'          => 1500,
            'elapsed_time'         => 1620,
            'average_speed'        => 3.35,
            'total_elevation_gain' => 42.0,
            // The fields Gate 1 forbids — present in the payload, must be dropped.
            'average_heartrate'    => 152.0,
            'max_heartrate'        => 178.0,
            'has_heartrate'        => true,
        ];
    }

    // ---- Gate 1 ---------------------------------------------------------

    public function test_map_drops_all_heart_rate_fields(): void {
        $mapped = ActivityIngestService::mapNonHr( $this->samplePayload() );

        $this->assertArrayNotHasKey( 'average_heartrate', $mapped );
        $this->assertArrayNotHasKey( 'max_heartrate', $mapped );
        $this->assertArrayNotHasKey( 'has_heartrate', $mapped );

        // …and the allowed fields do map.
        $this->assertSame( '555', $mapped['external_id'] );
        $this->assertSame( 'Run', $mapped['activity_type'] );
        $this->assertSame( 5025.4, $mapped['distance_m'] );
        $this->assertSame( 1500, $mapped['moving_time_s'] );
        $this->assertSame( '2026-06-28 06:30:00', $mapped['started_at'] );
    }

    // ---- ingest / upsert ------------------------------------------------

    public function test_ingest_upserts_a_non_hr_activity(): void {
        $player_id = $this->connectedPlayer();
        $svc       = $this->serviceFor( $this->samplePayload() );

        $this->assertTrue( $svc->ingest( $player_id, 555 ) );

        $row = $this->activities->findByExternalId( $player_id, 'strava', '555' );
        $this->assertNotNull( $row );
        $this->assertSame( 'Morning Run', (string) $row->name );

        // The stored table has no heart-rate column at all.
        global $wpdb;
        $cols = $wpdb->get_col( "DESCRIBE {$this->p}tt_player_activities" );
        foreach ( $cols as $c ) {
            $this->assertStringNotContainsStringIgnoringCase( 'heart', (string) $c, 'no heart-rate column exists' );
        }
    }

    public function test_ingest_is_idempotent_by_external_id(): void {
        $player_id = $this->connectedPlayer();
        $this->serviceFor( $this->samplePayload() )->ingest( $player_id, 555 );

        $renamed = $this->samplePayload();
        $renamed['name'] = 'Evening Run';
        $this->serviceFor( $renamed )->ingest( $player_id, 555 );

        $rows = $this->activities->listForPlayer( $player_id );
        $this->assertCount( 1, $rows, 'a re-pushed activity updates in place, not duplicated' );
        $this->assertSame( 'Evening Run', (string) $rows[0]->name );
    }

    public function test_ingest_skips_when_player_not_connected(): void {
        $player_id = $this->insertPlayer(); // no connection row
        $svc       = $this->serviceFor( $this->samplePayload() );

        $this->assertFalse( $svc->ingest( $player_id, 555 ) );
        $this->assertCount( 0, $this->activities->listForPlayer( $player_id ) );
    }

    // ---- archive on delete / deauth -------------------------------------

    public function test_delete_soft_archives_the_activity(): void {
        $player_id = $this->connectedPlayer();
        $svc       = $this->serviceFor( $this->samplePayload() );
        $svc->ingest( $player_id, 555 );

        $this->assertTrue( $svc->delete( $player_id, 555 ) );
        $this->assertCount( 0, $this->activities->listForPlayer( $player_id ), 'archived rows drop out of the list' );
        $this->assertNotNull(
            $this->activities->findByExternalId( $player_id, 'strava', '555' ),
            'the row still exists (soft-archive, not hard delete)'
        );
    }

    public function test_archive_all_archives_every_activity_for_a_player(): void {
        $player_id = $this->connectedPlayer();
        $this->serviceFor( $this->samplePayload() )->ingest( $player_id, 555 );
        $second = $this->samplePayload();
        $second['id'] = 556;
        $this->serviceFor( $second )->ingest( $player_id, 556 );

        $svc = $this->serviceFor( $this->samplePayload() );
        $this->assertSame( 2, $svc->archiveAll( $player_id ) );
        $this->assertCount( 0, $this->activities->listForPlayer( $player_id ) );
    }

    // ---- REST read surface ---------------------------------------------

    public function test_activities_endpoint_returns_rows_without_secrets_or_hr(): void {
        $player_id = $this->connectedPlayer();
        $this->serviceFor( $this->samplePayload() )->ingest( $player_id, 555 );

        $req = new WP_REST_Request( 'GET' );
        $req->set_param( 'id', $player_id );
        $data = StravaRestController::activities( $req )->get_data();

        $this->assertTrue( $data['success'] );
        $this->assertCount( 1, $data['data']['activities'] );
        $row = $data['data']['activities'][0];
        $this->assertSame( 'Morning Run', $row['name'] );
        $this->assertArrayNotHasKey( 'average_heartrate', $row );
        $this->assertArrayNotHasKey( 'access_token', $row );
    }

    // ---- helpers --------------------------------------------------------

    /** A connected player whose token is valid (far from expiry → no refresh). */
    private function connectedPlayer(): int {
        $player_id = $this->insertPlayer();
        $this->connections->connect( $player_id, [
            'athlete_id'    => 42,
            'access_token'  => 'valid-access',
            'refresh_token' => 'valid-refresh',
            'expires_at'    => time() + 5 * HOUR_IN_SECONDS,
        ] );
        return $player_id;
    }

    /** A service whose fetch returns the given activity body. */
    private function serviceFor( array $body ): ActivityIngestService {
        $fetcher = static function () use ( $body ) {
            return [ 'ok' => true, 'body' => $body ];
        };
        return new ActivityIngestService(
            $this->activities,
            $this->connections,
            new TokenRefreshService( $this->connections ),
            $fetcher
        );
    }

    private function insertPlayer(): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_players", [
            'club_id'    => CurrentClub::id(),
            'first_name' => 'Runner',
            'last_name'  => 'Player',
            'status'     => 'active',
            'wp_user_id' => 0,
        ] );
        return (int) $wpdb->insert_id;
    }
}
