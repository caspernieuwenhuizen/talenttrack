<?php
namespace TT\Modules\Strava;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Logging\Logger;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Workflow\Dispatchers\CronDispatcher;

/**
 * TokenRefreshService (#2057, epic #2002) — keeps per-player Strava
 * access tokens fresh.
 *
 * Strava access tokens expire after 6 hours and the refresh token
 * ROTATES on every refresh (the old one dies immediately). Two paths
 * keep a player connected:
 *
 *   1. Proactive sweep — subscribes to the workflow engine heartbeat
 *      (`tt_workflow_cron_tick`, the one chokepoint a future SaaS
 *      scheduler replaces; CLAUDE.md §4 — NOT an ad-hoc wp_cron), and
 *      refreshes any connection whose token is within `REFRESH_MARGIN`
 *      of expiry. The tick is hourly and the margin is 2h, so a token is
 *      always refreshed well before it can expire.
 *   2. Lazy refresh — `validAccessToken()` refreshes on demand right
 *      before an API call (#2058 ingest), so a token is never used stale
 *      even if a tick was missed.
 *
 * Either way the rotated refresh token is persisted atomically via
 * `ConnectionRepository::rotateTokens()`. A hard auth failure (Strava
 * rejects the refresh token) flips the connection to `revoked` so the
 * UI can prompt a reconnect rather than retrying a dead grant forever.
 *
 * The heartbeat fires unauthenticated, so the sweep pins each tenant's
 * `club_id` in turn (mirroring AutoPurgeCron) — a club's tokens are only
 * ever read and written within that club.
 */
final class TokenRefreshService {

    /**
     * Refresh when a token expires within this window. The heartbeat is
     * hourly and tokens live 6h, so a 2h margin guarantees an hourly tick
     * always catches a token before it expires.
     */
    public const REFRESH_MARGIN = 2 * HOUR_IN_SECONDS;

    /** @var ConnectionRepository */
    private $repo;

    /**
     * Seam for the upstream refresh call. Defaults to the live Strava
     * client; tests inject a fake so the sweep is exercised without HTTP.
     *
     * @var callable(string):array<string,mixed>
     */
    private $refresher;

    /**
     * @param callable(string):array<string,mixed>|null $refresher
     */
    public function __construct( ?ConnectionRepository $repo = null, ?callable $refresher = null ) {
        $this->repo      = $repo ?? new ConnectionRepository();
        $this->refresher = $refresher ?? [ StravaClient::class, 'refreshToken' ];
    }

    /**
     * Subscribe to the workflow engine heartbeat. No own schedule — the
     * engine keeps `tt_workflow_cron_tick` registered, so the refresh
     * piggybacks on that single chokepoint (CLAUDE.md §4).
     */
    public static function init(): void {
        add_action( CronDispatcher::TICK_HOOK, [ self::class, 'onTick' ], 20 );
    }

    public static function onTick(): void {
        ( new self() )->refreshAllClubs();
    }

    /**
     * Refresh every club's due connections. The tick is unauthenticated,
     * so each club is swept with `tt_current_club_id` pinned to it.
     *
     * @return array<int,int> club_id => count refreshed
     */
    public function refreshAllClubs(): array {
        $out = [];
        foreach ( $this->clubIds() as $club_id ) {
            $out[ $club_id ] = $this->withClub( $club_id, function () {
                return $this->refreshDueForCurrentClub();
            } );
        }
        return $out;
    }

    /**
     * Refresh every connection in the current club whose token is within
     * the margin of expiry. Returns the number successfully refreshed.
     */
    public function refreshDueForCurrentClub(): int {
        $cutoff = gmdate( 'Y-m-d H:i:s', time() + self::REFRESH_MARGIN );

        global $wpdb;
        $sql = "SELECT player_id FROM {$wpdb->prefix}tt_player_strava_connections
                WHERE status = 'connected'
                  AND token_expires_at IS NOT NULL
                  AND token_expires_at < %s
                  AND " . QueryHelpers::clubScopeWhere() . "
                ORDER BY token_expires_at ASC";
        $ids = $wpdb->get_col( $wpdb->prepare( $sql, $cutoff ) );

        $refreshed = 0;
        foreach ( array_map( 'intval', is_array( $ids ) ? $ids : [] ) as $player_id ) {
            if ( $this->refreshConnection( $player_id ) ) {
                $refreshed++;
            }
        }
        return $refreshed;
    }

    /**
     * Refresh one player's tokens. Returns false (and flips the
     * connection to `revoked`) when Strava rejects the refresh token;
     * a transient transport / 5xx error leaves the connection alone to
     * retry on the next tick.
     */
    public function refreshConnection( int $player_id ): bool {
        $refresh = $this->repo->getRefreshToken( $player_id );
        if ( $refresh === '' ) {
            $this->repo->markStatus( $player_id, 'revoked' );
            return false;
        }

        $res = ( $this->refresher )( $refresh );

        if ( empty( $res['ok'] ) ) {
            $http = (int) ( $res['http_code'] ?? 0 );
            // 400/401 — Strava rejected the grant; the refresh token is
            // dead. Mark revoked so the UI prompts a reconnect.
            if ( $http === 400 || $http === 401 ) {
                $this->repo->markStatus( $player_id, 'revoked' );
                Logger::warning( 'strava.refresh.revoked', [ 'player_id' => $player_id, 'http_code' => $http ] );
            } else {
                Logger::warning( 'strava.refresh.transient_error', [
                    'player_id' => $player_id,
                    'code'      => (string) ( $res['error_code'] ?? 'unknown' ),
                ] );
            }
            return false;
        }

        $this->repo->rotateTokens(
            $player_id,
            (string) ( $res['access_token'] ?? '' ),
            (string) ( $res['refresh_token'] ?? '' ),
            (int) ( $res['expires_at'] ?? 0 )
        );
        return true;
    }

    /**
     * A currently-valid access token for a player, refreshed on demand if
     * it is within the margin of (or past) expiry. Returns '' when the
     * player is not connected or a refresh failed — callers must treat
     * '' as "skip, not connected" (#2058).
     */
    public function validAccessToken( int $player_id ): string {
        $row = $this->repo->findByPlayerId( $player_id );
        if ( ! $row || (string) $row->status !== 'connected' ) {
            return '';
        }

        $expires = $row->token_expires_at ? (int) strtotime( (string) $row->token_expires_at . ' UTC' ) : 0;
        if ( $expires - time() <= self::REFRESH_MARGIN ) {
            if ( ! $this->refreshConnection( $player_id ) ) {
                return '';
            }
        }
        return $this->repo->getAccessToken( $player_id );
    }

    // ---- tenancy helpers (mirror AutoPurgeCron) -------------------------

    /**
     * Every club that has config rows, club 1 always included.
     *
     * @return int[]
     */
    private function clubIds(): array {
        global $wpdb;
        $ids = $wpdb->get_col( "SELECT DISTINCT club_id FROM {$wpdb->prefix}tt_config" );
        $ids = array_values( array_unique( array_map( 'intval', is_array( $ids ) ? $ids : [] ) ) );
        if ( ! in_array( 1, $ids, true ) ) {
            $ids[] = 1;
        }
        return array_filter( $ids, static function ( $id ) { return $id > 0; } );
    }

    /**
     * Run $fn with `tt_current_club_id` pinned to $club_id.
     *
     * @template T
     * @param callable():T $fn
     * @return T
     */
    private function withClub( int $club_id, callable $fn ) {
        $filter = static function () use ( $club_id ) { return $club_id; };
        add_filter( 'tt_current_club_id', $filter, 9999 );
        try {
            return $fn();
        } finally {
            remove_filter( 'tt_current_club_id', $filter, 9999 );
        }
    }
}
