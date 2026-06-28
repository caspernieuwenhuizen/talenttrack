<?php
namespace TT\Modules\Strava;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Logging\Logger;

/**
 * ActivityIngestService (#2058, epic #2002) — pulls a player's Strava
 * activity into their TalentTrack timeline.
 *
 * Triggered by the webhook (#2059): a push carries only a thin pointer
 * (athlete + activity id + aspect), so we fetch the full activity with
 * that player's freshly-refreshed token and upsert it by external id.
 * A delete / deauth push soft-archives instead.
 *
 * Gate 1 — distance / duration / pace only. `mapNonHr()` is an explicit
 * allowlist: it reads distance, moving + elapsed time, speed, elevation,
 * type, name and start time, and **never** touches `average_heartrate` /
 * `max_heartrate` / `has_heartrate`. Heart-rate data therefore cannot
 * enter the model, which is exactly why the Strava under-16 HR
 * restriction is a non-issue for the (mostly minor) academy cohort.
 */
final class ActivityIngestService {

    public const SOURCE = 'strava';

    /** @var ActivityRepository */
    private $activities;

    /** @var ConnectionRepository */
    private $connections;

    /** @var TokenRefreshService */
    private $tokens;

    /**
     * Seam for the upstream fetch — `callable(string $token, int $id): array`.
     * Defaults to the live client; tests inject a fake.
     *
     * @var callable(string,int):array<string,mixed>
     */
    private $fetcher;

    /**
     * @param callable(string,int):array<string,mixed>|null $fetcher
     */
    public function __construct(
        ?ActivityRepository $activities = null,
        ?ConnectionRepository $connections = null,
        ?TokenRefreshService $tokens = null,
        ?callable $fetcher = null
    ) {
        $this->activities  = $activities ?? new ActivityRepository();
        $this->connections = $connections ?? new ConnectionRepository();
        $this->tokens      = $tokens ?? new TokenRefreshService();
        $this->fetcher     = $fetcher ?? [ StravaClient::class, 'getActivity' ];
    }

    /**
     * Fetch + upsert one activity for a player. Returns false when the
     * player isn't connected (no valid token) or the fetch failed —
     * callers treat false as "skip", not "retry forever".
     */
    public function ingest( int $player_id, int $activity_id ): bool {
        $token = $this->tokens->validAccessToken( $player_id );
        if ( $token === '' ) {
            return false; // not connected / refresh failed
        }

        $res = ( $this->fetcher )( $token, $activity_id );
        if ( empty( $res['ok'] ) || ! isset( $res['body'] ) || ! is_array( $res['body'] ) ) {
            Logger::warning( 'strava.ingest.fetch_failed', [
                'player_id'   => $player_id,
                'activity_id' => $activity_id,
                'code'        => (string) ( $res['error_code'] ?? 'unknown' ),
            ] );
            return false;
        }

        $mapped = self::mapNonHr( $res['body'] );
        if ( $mapped['external_id'] === '' ) {
            return false;
        }

        $this->activities->upsert( $player_id, self::SOURCE, $mapped['external_id'], $mapped );
        $this->connections->touchSync( $player_id );
        Logger::info( 'strava.ingest.ok', [ 'player_id' => $player_id, 'activity_id' => $activity_id ] );
        return true;
    }

    /** Soft-archive one activity (Strava delete push). */
    public function delete( int $player_id, int $activity_id ): bool {
        return $this->activities->archiveByExternalId( $player_id, self::SOURCE, (string) $activity_id );
    }

    /** Soft-archive every imported activity for a player (deauth / disconnect). */
    public function archiveAll( int $player_id ): int {
        return $this->activities->archiveAllForPlayer( $player_id, self::SOURCE );
    }

    /**
     * Map a Strava activity summary to our non-HR column set. Explicit
     * allowlist — Gate 1. Any field not named here (heart rate, GPS
     * streams, power, cadence) is intentionally dropped.
     *
     * @param array<string,mixed> $body
     * @return array{external_id:string,activity_type:?string,name:?string,started_at:?string,distance_m:?float,moving_time_s:?int,elapsed_time_s:?int,average_speed_ms:?float,total_elevation_gain_m:?float}
     */
    public static function mapNonHr( array $body ): array {
        $start = isset( $body['start_date'] ) ? (string) $body['start_date'] : '';
        $started_at = $start !== '' ? gmdate( 'Y-m-d H:i:s', (int) strtotime( $start ) ) : null;

        // sport_type is the modern field; type is the legacy fallback.
        $type = $body['sport_type'] ?? ( $body['type'] ?? null );

        return [
            'external_id'            => isset( $body['id'] ) ? (string) $body['id'] : '',
            'activity_type'          => $type !== null ? (string) $type : null,
            'name'                   => isset( $body['name'] ) ? (string) $body['name'] : null,
            'started_at'             => $started_at,
            'distance_m'             => isset( $body['distance'] ) ? (float) $body['distance'] : null,
            'moving_time_s'          => isset( $body['moving_time'] ) ? (int) $body['moving_time'] : null,
            'elapsed_time_s'         => isset( $body['elapsed_time'] ) ? (int) $body['elapsed_time'] : null,
            'average_speed_ms'       => isset( $body['average_speed'] ) ? (float) $body['average_speed'] : null,
            'total_elevation_gain_m' => isset( $body['total_elevation_gain'] ) ? (float) $body['total_elevation_gain'] : null,
        ];
    }
}
