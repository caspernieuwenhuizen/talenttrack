<?php
namespace TT\Modules\Push;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * PushSubscriptionsRepository — CRUD for tt_push_subscriptions (#0042).
 *
 * One row per (user, device endpoint). Endpoints rotate when the
 * browser refreshes the subscription; the unique key on `endpoint`
 * makes register-or-touch idempotent. Stale rows (last_seen_at older
 * than 90 days, or HTTP-410 from the push service) are pruned by
 * `Cron\PrunePushSubscriptions` and `WebPushSender::send()` respectively.
 *
 * Returned rows are sanitized — secrets (`p256dh`, `auth_secret`) only
 * leave the repo when the dispatcher needs them; REST surfaces should
 * project `id`, `user_agent`, `created_at`, `last_seen_at` only.
 */
class PushSubscriptionsRepository {

    private function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'tt_push_subscriptions';
    }

    /**
     * Insert or refresh by endpoint. Returns the row id (always > 0
     * on success). Idempotent: a re-subscription with the same
     * endpoint updates the keys + bumps `last_seen_at`.
     *
     * @param array{
     *   endpoint:string,
     *   p256dh:string,
     *   auth:string,
     *   user_agent?:string
     * } $data
     */
    public function upsert( int $user_id, array $data ): int {
        if ( $user_id <= 0 ) return 0;
        $endpoint = (string) ( $data['endpoint'] ?? '' );
        $p256dh   = (string) ( $data['p256dh']   ?? '' );
        $auth     = (string) ( $data['auth']     ?? '' );
        if ( $endpoint === '' || $p256dh === '' || $auth === '' ) return 0;

        global $wpdb;
        $existing = $wpdb->get_row( $wpdb->prepare(
            "SELECT id FROM {$this->table()} WHERE endpoint = %s LIMIT 1",
            $endpoint
        ) );

        if ( $existing ) {
            $wpdb->update(
                $this->table(),
                [
                    'user_id'      => $user_id,
                    'p256dh'       => $p256dh,
                    'auth_secret'  => $auth,
                    'user_agent'   => isset( $data['user_agent'] ) ? substr( (string) $data['user_agent'], 0, 255 ) : null,
                    'last_seen_at' => current_time( 'mysql' ),
                ],
                [ 'id' => (int) $existing->id ]
            );
            return (int) $existing->id;
        }

        $ok = $wpdb->insert( $this->table(), [
            'club_id'      => CurrentClub::id(),
            'uuid'         => function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : null,
            'user_id'      => $user_id,
            'endpoint'     => $endpoint,
            'p256dh'       => $p256dh,
            'auth_secret'  => $auth,
            'user_agent'   => isset( $data['user_agent'] ) ? substr( (string) $data['user_agent'], 0, 255 ) : null,
            'created_at'   => current_time( 'mysql' ),
            'last_seen_at' => current_time( 'mysql' ),
        ] );
        return $ok ? (int) $wpdb->insert_id : 0;
    }

    /**
     * Active subscriptions for a user — `last_seen_at` within the
     * inactivity window (90 days). Used by the dispatcher chain to
     * decide whether to fan out a push at all.
     *
     * @return list<array{id:int,endpoint:string,p256dh:string,auth_secret:string,user_agent:?string,last_seen_at:string}>
     */
    public function activeForUser( int $user_id, int $window_days = 90 ): array {
        if ( $user_id <= 0 ) return [];
        global $wpdb;
        $cutoff = gmdate( 'Y-m-d H:i:s', time() - $window_days * 86400 );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, endpoint, p256dh, auth_secret, user_agent, last_seen_at
               FROM {$this->table()}
              WHERE user_id = %d AND club_id = %d AND last_seen_at >= %s
              ORDER BY last_seen_at DESC",
            $user_id, CurrentClub::id(), $cutoff
        ), ARRAY_A );
        return is_array( $rows ) ? array_map( static function ( array $r ): array {
            return [
                'id'           => (int) $r['id'],
                'endpoint'     => (string) $r['endpoint'],
                'p256dh'       => (string) $r['p256dh'],
                'auth_secret'  => (string) $r['auth_secret'],
                'user_agent'   => $r['user_agent'] !== null ? (string) $r['user_agent'] : null,
                'last_seen_at' => (string) $r['last_seen_at'],
            ];
        }, $rows ) : [];
    }

    /**
     * Public-safe listing for the "manage your devices" surface.
     * Strips the encryption keys; returns one row per device the user
     * can revoke from settings.
     *
     * @return list<array{id:int,user_agent:?string,created_at:string,last_seen_at:string}>
     */
    public function listForUser( int $user_id ): array {
        if ( $user_id <= 0 ) return [];
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, user_agent, created_at, last_seen_at
               FROM {$this->table()}
              WHERE user_id = %d AND club_id = %d
              ORDER BY last_seen_at DESC",
            $user_id, CurrentClub::id()
        ), ARRAY_A );
        return is_array( $rows ) ? array_map( static function ( array $r ): array {
            return [
                'id'           => (int) $r['id'],
                'user_agent'   => $r['user_agent'] !== null ? (string) $r['user_agent'] : null,
                'created_at'   => (string) $r['created_at'],
                'last_seen_at' => (string) $r['last_seen_at'],
            ];
        }, $rows ) : [];
    }

    public function findOwnedById( int $id, int $user_id ): ?array {
        if ( $id <= 0 || $user_id <= 0 ) return null;
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, user_id, endpoint FROM {$this->table()}
              WHERE id = %d AND user_id = %d AND club_id = %d LIMIT 1",
            $id, $user_id, CurrentClub::id()
        ), ARRAY_A );
        return is_array( $row ) ? [
            'id'       => (int) $row['id'],
            'user_id'  => (int) $row['user_id'],
            'endpoint' => (string) $row['endpoint'],
        ] : null;
    }

    public function deleteById( int $id ): bool {
        if ( $id <= 0 ) return false;
        global $wpdb;
        return (bool) $wpdb->delete( $this->table(), [ 'id' => $id ] );
    }

    public function deleteByEndpoint( string $endpoint ): bool {
        if ( $endpoint === '' ) return false;
        global $wpdb;
        return (bool) $wpdb->delete( $this->table(), [ 'endpoint' => $endpoint ] );
    }

    public function touch( int $id ): void {
        if ( $id <= 0 ) return;
        global $wpdb;
        $wpdb->update(
            $this->table(),
            [ 'last_seen_at' => current_time( 'mysql' ) ],
            [ 'id' => $id ]
        );
    }

    /**
     * Delete subscriptions whose `last_seen_at` is older than the cutoff.
     * Returns the number of deleted rows. Called daily by
     * `Cron\PrunePushSubscriptions`.
     */
    public function pruneOlderThan( int $window_days = 90 ): int {
        global $wpdb;
        $cutoff = gmdate( 'Y-m-d H:i:s', time() - $window_days * 86400 );
        return (int) $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$this->table()} WHERE last_seen_at < %s",
            $cutoff
        ) );
    }
}
