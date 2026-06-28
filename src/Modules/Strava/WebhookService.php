<?php
namespace TT\Modules\Strava;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Audit\AuditService;
use TT\Infrastructure\Logging\Logger;

/**
 * WebhookService (#2059, epic #2002) — Strava push webhook router.
 *
 * Strava allows exactly one push subscription per application (club-wide,
 * covering every authorized athlete). Sync is push-driven, NOT polled —
 * looping all players to poll would blow the rate budget (100 req/15min,
 * 1000/day).
 *
 * Two surfaces, both on the public `/strava/webhook` route:
 *
 *   - GET handshake — when the subscription is (re)created Strava GETs the
 *     callback with `hub.mode=subscribe` + our `hub.verify_token` + a
 *     `hub.challenge`; we must echo the challenge within 2 seconds AFTER
 *     verifying the token. `handshake()` does only that — no DB work — so
 *     it always answers inside the window.
 *   - POST event — a thin pointer (`object_type`, `aspect_type`,
 *     `object_id`, `owner_id`). We resolve the athlete to a connection,
 *     pin its club, then route: activity create/update → ingest,
 *     activity delete → soft-archive, athlete deauthorize → disconnect +
 *     archive every imported activity.
 *
 * The event fires unauthenticated and the athlete id is global, so the
 * resolver is cross-club and the work runs with `tt_current_club_id`
 * pinned to the resolved connection's club (mirrors AutoPurgeCron).
 */
final class WebhookService {

    /** @var ConnectionRepository */
    private $connections;

    /** @var ActivityIngestService */
    private $ingest;

    public function __construct( ?ConnectionRepository $connections = null, ?ActivityIngestService $ingest = null ) {
        $this->connections = $connections ?? new ConnectionRepository();
        $this->ingest      = $ingest ?? new ActivityIngestService();
    }

    /**
     * GET subscription-validation handshake. Returns the echo body when
     * the verify token matches, null otherwise (caller → 403). Constant
     * time compare; no DB writes so it stays well under Strava's 2s limit.
     *
     * @param array<string,mixed> $params
     * @return array{'hub.challenge':string}|null
     */
    public function handshake( array $params ): ?array {
        $mode      = (string) ( $params['hub_mode'] ?? '' );
        $token     = (string) ( $params['hub_verify_token'] ?? '' );
        $challenge = (string) ( $params['hub_challenge'] ?? '' );

        if ( $mode !== 'subscribe' ) return null;
        if ( ! hash_equals( StravaConfig::webhookVerifyToken(), $token ) ) return null;

        return [ 'hub.challenge' => $challenge ];
    }

    /**
     * Route one push event. Unknown / unmapped events are a quiet no-op —
     * Strava expects a fast 200 regardless, so we never throw here.
     *
     * @param array<string,mixed> $payload
     */
    public function handleEvent( array $payload ): void {
        $object_type = (string) ( $payload['object_type'] ?? '' );
        $aspect      = (string) ( $payload['aspect_type'] ?? '' );
        $owner_id    = (int) ( $payload['owner_id'] ?? 0 );
        $object_id   = (int) ( $payload['object_id'] ?? 0 );

        if ( $owner_id <= 0 ) return;

        $conn = $this->connections->findByAthleteIdAnyClub( $owner_id );
        if ( ! $conn ) {
            Logger::info( 'strava.webhook.unmapped_athlete', [ 'athlete_id' => $owner_id ] );
            return;
        }

        $player_id = (int) $conn->player_id;
        $club_id   = (int) $conn->club_id;

        $this->withClub( $club_id, function () use ( $object_type, $aspect, $object_id, $payload, $player_id ) {
            // Athlete deauthorization — `updates.authorized === "false"`.
            if ( $object_type === 'athlete'
                && $this->isDeauth( $payload ) ) {
                $this->onDeauth( $player_id );
                return;
            }

            if ( $object_type !== 'activity' || $object_id <= 0 ) {
                return;
            }

            if ( $aspect === 'create' || $aspect === 'update' ) {
                $this->ingest->ingest( $player_id, $object_id );
            } elseif ( $aspect === 'delete' ) {
                $this->ingest->delete( $player_id, $object_id );
            }
        } );
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function isDeauth( array $payload ): bool {
        $updates = $payload['updates'] ?? [];
        if ( ! is_array( $updates ) ) return false;
        // Strava sends authorized as the string "false".
        return isset( $updates['authorized'] )
            && in_array( (string) $updates['authorized'], [ 'false', '0' ], true );
    }

    /**
     * Athlete revoked our access from Strava's side: stop syncing, clear
     * the stored tokens, archive the imported activities, and audit it.
     */
    private function onDeauth( int $player_id ): void {
        $this->connections->disconnect( $player_id, 'revoked' );
        $archived = $this->ingest->archiveAll( $player_id );

        ( new AuditService() )->record(
            'player_strava.deauthorized',
            'player_strava_connection',
            $player_id,
            [ 'player_id' => $player_id, 'archived_activities' => $archived, 'source' => 'webhook' ]
        );
        Logger::info( 'strava.webhook.deauthorized', [ 'player_id' => $player_id, 'archived' => $archived ] );
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
