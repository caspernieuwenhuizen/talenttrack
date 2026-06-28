<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Strava\ConnectionRepository;
use TT\Modules\Strava\TokenRefreshService;

/**
 * #2057 (epic #2002) — Strava token refresh service.
 *
 * Strava rotates the refresh token on every refresh and kills the old one
 * immediately, so the invariant under test is: a refresh persists BOTH the
 * new access token and the new refresh token atomically, and a rejected
 * grant flips the connection to `revoked` rather than retrying forever.
 *
 * The upstream HTTP call is injected (the `$refresher` seam) so the sweep +
 * lazy paths are exercised without touching the network.
 */
final class StravaTokenRefreshServiceTest extends WP_UnitTestCase {

    private string $p;
    private ConnectionRepository $repo;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->p    = $wpdb->prefix;
        $this->repo = new ConnectionRepository();
    }

    private function okRefresher( string $access = 'fresh-access', string $refresh = 'fresh-refresh' ): callable {
        return static function () use ( $access, $refresh ) {
            return [
                'ok'            => true,
                'access_token'  => $access,
                'refresh_token' => $refresh,
                'expires_at'    => time() + 6 * HOUR_IN_SECONDS,
            ];
        };
    }

    private function connect( int $player_id, int $expires_in ): void {
        $this->repo->connect( $player_id, [
            'athlete_id'    => 1,
            'access_token'  => 'old-access',
            'refresh_token' => 'old-refresh',
            'expires_at'    => time() + $expires_in,
        ] );
    }

    public function test_refresh_rotates_both_tokens_atomically(): void {
        $this->connect( 10, 600 );
        $svc = new TokenRefreshService( $this->repo, $this->okRefresher() );

        $this->assertTrue( $svc->refreshConnection( 10 ) );
        $this->assertSame( 'fresh-access', $this->repo->getAccessToken( 10 ) );
        $this->assertSame( 'fresh-refresh', $this->repo->getRefreshToken( 10 ), 'the rotated refresh token is persisted' );
    }

    public function test_rejected_grant_marks_connection_revoked(): void {
        $this->connect( 11, 600 );
        $reject = static function () {
            return [ 'ok' => false, 'http_code' => 400, 'error_code' => 'token_http_400' ];
        };
        $svc = new TokenRefreshService( $this->repo, $reject );

        $this->assertFalse( $svc->refreshConnection( 11 ) );
        $this->assertSame( 'revoked', (string) $this->repo->findByPlayerId( 11 )->status );
    }

    public function test_transient_error_leaves_connection_connected(): void {
        $this->connect( 12, 600 );
        $boom = static function () {
            return [ 'ok' => false, 'http_code' => 503, 'error_code' => 'token_http_503' ];
        };
        $svc = new TokenRefreshService( $this->repo, $boom );

        $this->assertFalse( $svc->refreshConnection( 12 ) );
        $this->assertSame( 'connected', (string) $this->repo->findByPlayerId( 12 )->status,
            'a 5xx is transient — the connection is left to retry next tick' );
    }

    public function test_sweep_refreshes_only_due_connections(): void {
        $this->connect( 20, 600 );        // due (within the 2h margin)
        $this->connect( 21, 999_999 );    // not due (expires far in the future)
        $svc = new TokenRefreshService( $this->repo, $this->okRefresher() );

        $count = $svc->refreshDueForCurrentClub();

        $this->assertSame( 1, $count, 'only the near-expiry connection is refreshed' );
        $this->assertSame( 'fresh-access', $this->repo->getAccessToken( 20 ) );
        $this->assertSame( 'old-access', $this->repo->getAccessToken( 21 ), 'the not-due token is untouched' );
    }

    public function test_valid_access_token_refreshes_on_demand_when_near_expiry(): void {
        $this->connect( 30, 600 ); // within margin → lazy refresh fires
        $svc = new TokenRefreshService( $this->repo, $this->okRefresher( 'lazy-access', 'lazy-refresh' ) );

        $this->assertSame( 'lazy-access', $svc->validAccessToken( 30 ) );
    }

    public function test_valid_access_token_returns_fresh_token_without_refresh_when_not_near_expiry(): void {
        $this->connect( 31, 5 * HOUR_IN_SECONDS ); // outside the 2h margin
        // A refresher that would explode proves it is never called.
        $svc = new TokenRefreshService( $this->repo, static function () {
            throw new \RuntimeException( 'refresh should not be called for a fresh token' );
        } );

        $this->assertSame( 'old-access', $svc->validAccessToken( 31 ) );
    }

    public function test_valid_access_token_empty_for_disconnected(): void {
        $this->connect( 40, 600 );
        $this->repo->disconnect( 40 );
        $svc = new TokenRefreshService( $this->repo, $this->okRefresher() );

        $this->assertSame( '', $svc->validAccessToken( 40 ), 'a disconnected player yields no token' );
    }
}
