<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Strava\ActivityIngestService;
use TT\Modules\Strava\ConnectionRepository;
use TT\Modules\Strava\StravaConfig;
use TT\Modules\Strava\WebhookService;

/**
 * #2059 (epic #2002) — Strava webhook router.
 *
 * Asserts the boundary behaviour: the handshake only echoes the challenge
 * for the right verify token, and an event is routed to the correct ingest
 * action for the resolved player — including the deauthorization path that
 * must stop sync and archive the imported data.
 *
 * The ingest is a spy (a subclass recording calls) so routing is verified
 * without HTTP; the connection lookup runs against the real schema.
 */
final class StravaWebhookServiceTest extends WP_UnitTestCase {

    private string $p;
    private ConnectionRepository $connections;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->p           = $wpdb->prefix;
        $this->connections = new ConnectionRepository();
    }

    private function spyIngest(): ActivityIngestService {
        return new class extends ActivityIngestService {
            /** @var array<int,array<mixed>> */
            public array $calls = [];
            public function ingest( int $player_id, int $activity_id ): bool {
                $this->calls[] = [ 'ingest', $player_id, $activity_id ];
                return true;
            }
            public function delete( int $player_id, int $activity_id ): bool {
                $this->calls[] = [ 'delete', $player_id, $activity_id ];
                return true;
            }
            public function archiveAll( int $player_id ): int {
                $this->calls[] = [ 'archiveAll', $player_id ];
                return 2;
            }
        };
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
        $token = StravaConfig::webhookVerifyToken();
        $out   = ( new WebhookService() )->handshake( [
            'hub_mode'         => 'subscribe',
            'hub_verify_token' => $token,
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

    public function test_activity_create_routes_to_ingest(): void {
        $player_id = $this->connectedPlayer( 7001 );
        $spy       = $this->spyIngest();

        ( new WebhookService( $this->connections, $spy ) )->handleEvent( [
            'object_type' => 'activity',
            'aspect_type' => 'create',
            'object_id'   => 9001,
            'owner_id'    => 7001,
        ] );

        $this->assertSame( [ [ 'ingest', $player_id, 9001 ] ], $spy->calls );
    }

    public function test_activity_delete_routes_to_archive(): void {
        $player_id = $this->connectedPlayer( 7002 );
        $spy       = $this->spyIngest();

        ( new WebhookService( $this->connections, $spy ) )->handleEvent( [
            'object_type' => 'activity',
            'aspect_type' => 'delete',
            'object_id'   => 9002,
            'owner_id'    => 7002,
        ] );

        $this->assertSame( [ [ 'delete', $player_id, 9002 ] ], $spy->calls );
    }

    public function test_deauthorization_disconnects_and_archives(): void {
        $player_id = $this->connectedPlayer( 7003 );
        $spy       = $this->spyIngest();

        ( new WebhookService( $this->connections, $spy ) )->handleEvent( [
            'object_type' => 'athlete',
            'aspect_type' => 'update',
            'object_id'   => 7003,
            'owner_id'    => 7003,
            'updates'     => [ 'authorized' => 'false' ],
        ] );

        // Connection revoked + tokens cleared…
        $row = $this->connections->findByPlayerId( $player_id );
        $this->assertSame( 'revoked', (string) $row->status );
        $this->assertSame( '', $this->connections->getAccessToken( $player_id ) );
        // …and every imported activity archived.
        $this->assertContains( [ 'archiveAll', $player_id ], $spy->calls );
    }

    public function test_event_for_unknown_athlete_is_a_noop(): void {
        $spy = $this->spyIngest();
        ( new WebhookService( $this->connections, $spy ) )->handleEvent( [
            'object_type' => 'activity',
            'aspect_type' => 'create',
            'object_id'   => 1,
            'owner_id'    => 999999, // no connection
        ] );
        $this->assertSame( [], $spy->calls, 'an unmapped athlete produces no ingest work' );
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
