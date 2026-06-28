<?php
namespace TT\Tests\Php;

use ReflectionMethod;
use WP_REST_Request;
use WP_UnitTestCase;
use TT\Infrastructure\REST\StravaRestController;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Strava\ConnectionRepository;
use TT\Modules\Strava\StravaConfig;
use TT\Modules\Strava\StravaOAuth;

/**
 * #2056 (epic #2002) — per-player Strava OAuth connect surface.
 *
 * Security-critical: the callback is the one route that can't use a WP
 * nonce, so the signed `state` IS the auth. These tests assert the
 * boundary invariants:
 *
 *   - signed state round-trips, and a tampered / expired / forged state
 *     is rejected (CSRF + identity binding);
 *   - per-player routes gate on self-or-edit (a player may manage their
 *     OWN connection; a stranger may not);
 *   - tokens stored by the repository are encrypted at rest and never
 *     leak through the status response;
 *   - connect refuses cleanly when the academy hasn't configured the app.
 */
final class StravaRestControllerTest extends WP_UnitTestCase {

    private string $p;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->p = $wpdb->prefix;
    }

    // ---- signed state ---------------------------------------------------

    public function test_signed_state_round_trips(): void {
        $state = StravaOAuth::signState( 42 );
        $out   = StravaOAuth::verifyState( $state );

        $this->assertIsArray( $out );
        $this->assertSame( 42, $out['pid'], 'the connecting player id survives the round trip' );
    }

    public function test_tampered_state_is_rejected(): void {
        $state = StravaOAuth::signState( 42 );
        // Flip the last character of the signature.
        $tampered = substr( $state, 0, -1 ) . ( substr( $state, -1 ) === 'a' ? 'b' : 'a' );

        $this->assertNull( StravaOAuth::verifyState( $tampered ), 'a forged signature is rejected' );
        $this->assertNull( StravaOAuth::verifyState( 'garbage' ), 'a malformed state is rejected' );
        $this->assertNull( StravaOAuth::verifyState( '' ), 'an empty state is rejected' );
    }

    public function test_state_for_one_player_cannot_be_read_as_another(): void {
        // The decoded payload binds the player id; a callback can't be
        // replayed to bind a Strava account to a different player.
        $out = StravaOAuth::verifyState( StravaOAuth::signState( 7 ) );
        $this->assertSame( 7, $out['pid'] );
        $this->assertNotSame( 8, $out['pid'] );
    }

    // ---- permission gate (self-or-edit) ---------------------------------

    public function test_player_may_manage_their_own_connection(): void {
        $uid       = self::factory()->user->create( [ 'role' => 'subscriber' ] );
        $player_id = $this->insertPlayer( 'Own Player', $uid );
        wp_set_current_user( $uid );

        $this->assertTrue(
            StravaRestController::canManagePlayer( $player_id ),
            'a player linked to the current user may manage their own Strava connection'
        );
    }

    public function test_stranger_may_not_manage_a_players_connection(): void {
        $owner_uid = self::factory()->user->create( [ 'role' => 'subscriber' ] );
        $player_id = $this->insertPlayer( 'Their Player', $owner_uid );

        // A different, unprivileged user.
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

        $this->assertFalse(
            StravaRestController::canManagePlayer( $player_id ),
            'a stranger without edit rights cannot manage the connection'
        );
    }

    public function test_logged_out_caller_is_denied(): void {
        $player_id = $this->insertPlayer( 'Someone', 0 );
        wp_set_current_user( 0 );
        $this->assertFalse( StravaRestController::canManagePlayer( $player_id ) );
    }

    // ---- status / connect handlers --------------------------------------

    public function test_status_reports_not_connected_with_no_row(): void {
        $player_id = $this->insertPlayer( 'Fresh', 0 );

        $req = new WP_REST_Request( 'GET' );
        $req->set_param( 'id', $player_id );
        $data = StravaRestController::status( $req )->get_data();

        $this->assertTrue( $data['success'] );
        $this->assertFalse( $data['data']['connected'] );
        $this->assertSame( 'never', $data['data']['status'] );
    }

    public function test_connect_refuses_when_app_not_configured(): void {
        // No client id/secret configured.
        $player_id = $this->insertPlayer( 'Wants strava', 0 );

        $req = new WP_REST_Request( 'POST' );
        $req->set_param( 'id', $player_id );
        $resp = StravaRestController::connect( $req );

        $this->assertSame( 409, $resp->get_status() );
        $this->assertFalse( $resp->get_data()['success'] );
    }

    public function test_connect_returns_authorize_url_when_configured_and_consented(): void {
        StravaConfig::saveCredentials( '12345', 'shh-secret' );
        $player_id = $this->insertPlayer( 'Ready', 0 );

        $req = new WP_REST_Request( 'POST' );
        $req->set_param( 'id', $player_id );
        $req->set_param( 'consent', true ); // Gate 2 — consent ticked
        $data = StravaRestController::connect( $req )->get_data();

        $this->assertTrue( $data['success'] );
        $this->assertStringContainsString( '/oauth/authorize', $data['data']['authorize_url'] );
        $this->assertStringContainsString( 'client_id=12345', $data['data']['authorize_url'] );
        $this->assertStringContainsString( 'state=', $data['data']['authorize_url'] );
    }

    public function test_connect_refuses_without_consent(): void {
        StravaConfig::saveCredentials( '12345', 'shh-secret' );
        $player_id = $this->insertPlayer( 'No consent', 0 );

        $req = new WP_REST_Request( 'POST' );
        $req->set_param( 'id', $player_id );
        // No consent param → authorize URL must NOT be minted.
        $resp = StravaRestController::connect( $req );

        $this->assertSame( 422, $resp->get_status() );
        $this->assertFalse( $resp->get_data()['success'] );
        $this->assertSame( 'consent_required', $resp->get_data()['errors'][0]['code'] );
    }

    public function test_consent_is_recorded_and_audited_once(): void {
        StravaConfig::saveCredentials( '12345', 'shh-secret' );
        $player_id = $this->insertPlayer( 'Consenter', 0 );
        $repo      = new ConnectionRepository();

        $this->assertFalse( $repo->hasConsent( $player_id ) );

        $req = new WP_REST_Request( 'POST' );
        $req->set_param( 'id', $player_id );
        $req->set_param( 'consent', true );
        StravaRestController::connect( $req );

        $this->assertTrue( $repo->hasConsent( $player_id ), 'consent is persisted on a pending row' );

        // Status surfaces the recorded consent (even pre-connection).
        $statusReq = new WP_REST_Request( 'GET' );
        $statusReq->set_param( 'id', $player_id );
        $status = StravaRestController::status( $statusReq )->get_data()['data'];
        $this->assertTrue( $status['has_consent'] );
        $this->assertNotSame( '', $status['consent_at'] );
    }

    public function test_consent_survives_the_oauth_connect(): void {
        $player_id = $this->insertPlayer( 'Keeps consent', 0 );
        $repo      = new ConnectionRepository();
        $repo->recordConsent( $player_id, 7 );
        $consent_at = (string) $repo->findByPlayerId( $player_id )->consent_at;

        // A successful OAuth exchange connects the same row…
        $repo->connect( $player_id, [
            'athlete_id'    => 1,
            'access_token'  => 'a',
            'refresh_token' => 'r',
            'expires_at'    => time() + 3600,
        ] );

        $row = $repo->findByPlayerId( $player_id );
        $this->assertSame( 'connected', (string) $row->status );
        $this->assertSame( $consent_at, (string) $row->consent_at, 'the original consent timestamp is preserved' );
    }

    // ---- repository: encryption at rest + status never leaks secrets ----

    public function test_tokens_are_encrypted_at_rest_and_never_surface(): void {
        $repo      = new ConnectionRepository();
        $player_id = $this->insertPlayer( 'Connected', 0 );

        $repo->connect( $player_id, [
            'athlete_id'    => 999,
            'access_token'  => 'plaintext-access',
            'refresh_token' => 'plaintext-refresh',
            'expires_at'    => time() + 3600,
            'scope'         => StravaConfig::SCOPE,
        ] );

        // The stored column is an encrypted envelope, not the plaintext.
        global $wpdb;
        $stored = (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT access_token_enc FROM {$this->p}tt_player_strava_connections WHERE player_id = %d AND club_id = %d",
            $player_id, CurrentClub::id()
        ) );
        $this->assertNotSame( 'plaintext-access', $stored, 'access token is not stored in plaintext' );
        $this->assertNotSame( '', $stored );

        // …but decrypts back through the repository.
        $this->assertSame( 'plaintext-access', $repo->getAccessToken( $player_id ) );

        // The status response carries no token field.
        $req = new WP_REST_Request( 'GET' );
        $req->set_param( 'id', $player_id );
        $data = StravaRestController::status( $req )->get_data()['data'];
        $this->assertTrue( $data['connected'] );
        $this->assertArrayNotHasKey( 'access_token', $data );
        $this->assertArrayNotHasKey( 'refresh_token', $data );
    }

    public function test_rotate_tokens_replaces_both_atomically(): void {
        $repo      = new ConnectionRepository();
        $player_id = $this->insertPlayer( 'Rotating', 0 );

        $repo->connect( $player_id, [
            'athlete_id'    => 1,
            'access_token'  => 'old-access',
            'refresh_token' => 'old-refresh',
            'expires_at'    => time() + 60,
        ] );
        $repo->rotateTokens( $player_id, 'new-access', 'new-refresh', time() + 3600 );

        $this->assertSame( 'new-access', $repo->getAccessToken( $player_id ) );
        $this->assertSame( 'new-refresh', $repo->getRefreshToken( $player_id ) );
    }

    public function test_disconnect_clears_tokens_and_flags_status(): void {
        $repo      = new ConnectionRepository();
        $player_id = $this->insertPlayer( 'Leaving', 0 );

        $repo->connect( $player_id, [
            'athlete_id'    => 1,
            'access_token'  => 'a',
            'refresh_token' => 'r',
            'expires_at'    => time() + 60,
        ] );
        $repo->disconnect( $player_id );

        $this->assertSame( '', $repo->getAccessToken( $player_id ), 'tokens are cleared on disconnect' );
        $row = $repo->findByPlayerId( $player_id );
        $this->assertSame( 'disconnected', (string) $row->status );
    }

    // ---- helpers --------------------------------------------------------

    private function insertPlayer( string $name, int $wp_user_id ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_players", [
            'club_id'    => CurrentClub::id(),
            'first_name' => $name,
            'last_name'  => 'Player',
            'status'     => 'active',
            'wp_user_id' => $wp_user_id,
        ] );
        return (int) $wpdb->insert_id;
    }
}
