<?php
namespace TT\Modules\AdminCenterClient;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Broadcasts (#3499) — operator notices the control plane sends, and what
 * each user still needs to see.
 *
 * Until now the only way to tell an academy anything was an email to
 * whatever `admin_email` its install holds. Every verified ping answer now
 * carries
 *
 *     "broadcasts": [ { "id": 7, "body": "…", "severity": "warning",
 *                       "dismissable": true, "ends_at": "2026-09-20 20:00:00" } ]
 *
 * always an array, empty when nothing applies.
 *
 * - **Replaced, never merged.** Each verified answer overwrites the cache,
 *   which is what makes an ended broadcast disappear: the control plane
 *   stopped sending it.
 * - **Retired on time without us.** A cached broadcast past `ends_at` (UTC)
 *   is not shown, so an install that cannot reach the control plane does
 *   not keep showing yesterday's maintenance notice.
 * - **Dismissed per user, by id.** A new broadcast has a new id, so an
 *   earlier dismissal never hides it.
 * - **Plain text.** `body` is operator-authored and always rendered escaped.
 */
final class Broadcasts {

    public const OPTION   = 'tt_broadcasts';
    public const USER_META = 'tt_dismissed_broadcasts';

    public const SEVERITY_INFO    = 'info';
    public const SEVERITY_WARNING = 'warning';

    /**
     * The broadcasts a verified answer carries, or null when it carries no
     * usable `broadcasts` array — which leaves the cache alone.
     *
     * @param array<string,mixed> $decoded
     * @return list<array{id:int, body:string, severity:string, dismissable:bool, ends_at:string}>|null
     */
    public static function fromResponse( array $decoded ): ?array {
        $list = $decoded['broadcasts'] ?? null;
        if ( ! is_array( $list ) ) return null;

        $out = [];
        foreach ( $list as $item ) {
            if ( ! is_array( $item ) ) continue;
            $id   = is_int( $item['id'] ?? null ) ? $item['id'] : 0;
            $body = is_string( $item['body'] ?? null ) ? trim( $item['body'] ) : '';
            if ( $id <= 0 || $body === '' ) continue;

            $severity = ( $item['severity'] ?? '' ) === self::SEVERITY_WARNING ? self::SEVERITY_WARNING : self::SEVERITY_INFO;
            $ends_at  = is_string( $item['ends_at'] ?? null ) && preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $item['ends_at'] )
                ? $item['ends_at']
                : '';

            $out[] = [
                'id'          => $id,
                'body'        => mb_substr( $body, 0, 2000 ),
                'severity'    => $severity,
                // Only an explicit false makes a broadcast undismissable.
                'dismissable' => ( $item['dismissable'] ?? true ) !== false,
                'ends_at'     => $ends_at,
            ];
        }
        return $out;
    }

    /** @param list<array{id:int, body:string, severity:string, dismissable:bool, ends_at:string}> $broadcasts */
    public static function store( array $broadcasts ): void {
        update_option( self::OPTION, (string) wp_json_encode( $broadcasts ), false );
    }

    /**
     * Cached broadcasts that have not ended.
     *
     * @return list<array{id:int, body:string, severity:string, dismissable:bool, ends_at:string}>
     */
    public static function active( ?int $now = null ): array {
        $raw     = get_option( self::OPTION, '' );
        $decoded = is_string( $raw ) && $raw !== '' ? json_decode( $raw, true ) : null;
        $list    = self::fromResponse( [ 'broadcasts' => is_array( $decoded ) ? $decoded : [] ] ) ?? [];

        $now = $now ?? time();
        return array_values( array_filter( $list, static function ( array $b ) use ( $now ): bool {
            if ( $b['ends_at'] === '' ) return true;
            $ends = strtotime( $b['ends_at'] . ' UTC' );
            return $ends === false || $ends > $now;
        } ) );
    }

    /**
     * What `$user_id` still needs to see: active, and not dismissed by them —
     * unless the broadcast cannot be dismissed.
     *
     * @return list<array{id:int, body:string, severity:string, dismissable:bool, ends_at:string}>
     */
    public static function forUser( int $user_id, ?int $now = null ): array {
        $dismissed = self::dismissedIds( $user_id );
        return array_values( array_filter( self::active( $now ), static function ( array $b ) use ( $dismissed ): bool {
            return ! $b['dismissable'] || ! in_array( $b['id'], $dismissed, true );
        } ) );
    }

    /**
     * Hide an active, dismissable broadcast for one user. False when there is
     * no such broadcast to hide.
     */
    public static function dismiss( int $user_id, int $broadcast_id ): bool {
        if ( $user_id <= 0 || $broadcast_id <= 0 ) return false;

        foreach ( self::active() as $b ) {
            if ( $b['id'] !== $broadcast_id ) continue;
            if ( ! $b['dismissable'] ) return false;

            // Only ids still being broadcast are kept, so the list cannot
            // grow without bound over years of notices.
            $live = array_map( static fn( array $x ): int => $x['id'], self::active() );
            $ids  = array_values( array_unique( array_intersect( array_merge( self::dismissedIds( $user_id ), [ $broadcast_id ] ), $live ) ) );
            update_user_meta( $user_id, self::USER_META, $ids );
            return true;
        }
        return false;
    }

    /** @return list<int> */
    private static function dismissedIds( int $user_id ): array {
        $ids = get_user_meta( $user_id, self::USER_META, true );
        return is_array( $ids ) ? array_values( array_map( 'intval', $ids ) ) : [];
    }
}
