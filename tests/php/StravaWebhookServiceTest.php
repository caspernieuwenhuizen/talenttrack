<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Strava\ActivityIngestService;
use TT\Modules\Strava\ActivityRepository;
use TT\Modules\Strava\ConnectionRepository;
use TT\Modules\Strava\StravaConfig;
use TT\Modules\Strava\TokenRefreshService;
use TT\Modules\Strava\WebhookService;

/**
 * #2059 (epic #2002) — Strava webhook router.
 *
 * Asserts the boundary behaviour against the real schema: the handshake
 * only echoes the challenge for the right verify token, and an event is
 * routed to the correct ingest action for the resolved player — including
 * the deauthorization path that must stop sync and archive imported data.
 *
 * The ingest is real but its upstream fetch is injected (the fetcher seam),
 * so routing + persistence are verified end-to-end without HTTP.
 */
final class StravaWebhookServiceTest extends WP_UnitTestCase {

    private string $p;
    private ConnectionRepository $connections;
    private ActivityRepository $activities;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->p           = $wpdb->prefix;
        $this->connections = new ConnectionRepository();
        $this->activities  = new ActivityRepository();
    }

    /** A real ingest service whose fetch returns the given activity body. */
    private function ingestReturning( array $body ): ActivityIngestService {
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

    private function webhook( array $body ): WebhookService {
        return new WebhookService( $this->connections, $this->ingestReturning( $body ) );
    }

    private function activityBody( int $id ): array {
        return [
            'id'          => $id,
            'name'        => 'Run ' . $id,
            'sport_type'  => 'Run',
            'start_date'  => '2026-06-28T06:30:00Z',
            'distance'    => 5000.0,
            'moving_time' => 1500,
        ];
    }

    private function connectedPlayer( int $athlete_id ): int {
        $player_id = $this->insertPlayer();
        $this->connections->connect( $player_id, [
            'athlete_id'    => $athlete_id,
            'access_token'  => 'a',
            'refresh_token' => 'r',
            'expires_at'    => time() + 5 * HOUR_IN_SECONDS,
        ] );
        return $player_id;
    }

    // ---- handshake ------------------------------------------------------

    public function test_handshake_echoes_challenge_for_valid_token(): void {
        $out = ( new WebhookService() )->handshake( [
            'hub_mode'         => 'subscribe',
            'hub_verify_token' => StravaConfig::webhookVerifyToken(),
            'hub_challenge'    => 'abc123',
        ] );
        $this->assertSame( [ 'hub.challenge' => 'abc123' ], $out );
    }

    public function test_handshake_rejects_wrong_token_and_mode(): void {
        $svc = new WebhookService();
        $this->assertNull( $svc->handshake( [
            'hub_mode' => 'subscribe', 'hub_verify_token' => 'wrong', 'hub_challenge' => 'x',
        ] ) );
        $this->assertNull( $svc->handshake( [
            'hub_mode' => 'unsubscribe', 'hub_verify_token' => StravaConfig::webhookVerifyToken(), 'hub_challenge' => 'x',
        ] ) );
    }

    // ---- event routing --------------------------------------------------

    public function test_activity_create_imports_the_activity(): void {
        $player_id = $this->connectedPlayer( 7001 );

        $this->webhook( $this->activityBody( 9001 ) )->handleEvent( [
            'object_type' => 'activity',
            'aspect_type' => 'create',
            'object_id'   => 9001,
            'owner_id'    => 7001,
        ] );

        $row = $this->activities->findByExternalId( $player_id, 'strava', '9001' );
        $this->assertNotNull( $row, 'a create push imports the activity for the resolved player' );
        $this->assertSame( 'Run 9001', (string) $row->name );
    }

    public function test_activity_delete_archives_the_activity(): void {
        $player_id = $this->connectedPlayer( 7002 );
        // Seed it via a create event first.
        $this->webhook( $this->activityBody( 9002 ) )->handleEvent( [
            'object_type' => 'activity', 'aspect_type' => 'create', 'object_id' => 9002, 'owner_id' => 7002,
        ] );

        $this->webhook( $this->activityBody( 9002 ) )->handleEvent( [
            'object_type' => 'activity', 'aspect_type' => 'delete', 'object_id' => 9002, 'owner_id' => 7002,
        ] );

        $this->assertCount( 0, $this->activities->listForPlayer( $player_id ), 'a delete push archives the activity' );
    }

    public function test_deauthorization_disconnects_and_archives(): void {
        $player_id = $this->connectedPlayer( 7003 );
        $this->webhook( $this->activityBody( 9003 ) )->handleEvent( [
            'object_type' => 'activity', 'aspect_type' => 'create', 'object_id' => 9003, 'owner_id' => 7003,
        ] );

        $this->webhook( $this->activityBody( 9003 ) )->handleEvent( [
            'object_type' => 'athlete',
            'aspect_type' => 'update',
            'object_id'   => 7003,
            'owner_id'    => 7003,
            'updates'     => [ 'authorized' => 'false' ],
        ] );

        $row = $this->connections->findByPlayerId( $player_id );
        $this->assertSame( 'revoked', (string) $row->status, 'deauth revokes the connection' );
        $this->assertSame( '', $this->connections->getAccessToken( $player_id ), 'deauth clears the tokens' );
        $this->assertCount( 0, $this->activities->listForPlayer( $player_id ), 'deauth archives imported activities' );
    }

    public function test_event_for_unknown_athlete_is_a_noop(): void {
        $other = $this->connectedPlayer( 7004 );
        $this->webhook( $this->activityBody( 1 ) )->handleEvent( [
            'object_type' => 'activity', 'aspect_type' => 'create', 'object_id' => 1, 'owner_id' => 999999,
        ] );
        $this->assertCount( 0, $this->activities->listForPlayer( $other ), 'an unmapped athlete imports nothing' );
    }

    private function insertPlayer(): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_players", [
            'club_id'    => CurrentClub::id(),
            'first_name' => 'Hooked',
            'last_name'  => 'Player',
            'status'     => 'active',
            'wp_user_id' => 0,
        ] );
        return (int) $wpdb->insert_id;
    }
}
