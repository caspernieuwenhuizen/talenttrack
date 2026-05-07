<?php
namespace TT\Modules\Comms\RateLimit;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * RateLimiter (#0066) — per-sender soft rate cap.
 *
 * Per spec § "Cross-cutting concerns": "A coach can't accidentally
 * email the whole club 8 times in a row." The default cap (50 sends
 * per sender per hour) is the operator-grade safety net, not a
 * tight quota; the operational types bypass.
 *
 * Implementation: WordPress transient keyed by sender_user_id +
 * hour-bucket. Each `record()` increments; each `wouldExceed()` reads
 * the current count and answers based on the threshold. Bucket TTL is
 * 1 hour so old buckets evict naturally.
 *
 * Per-club override via `tt_config['comms_rate_limit_per_hour']`
 * integer; default 50. Operational message types bypass the check.
 */
final class RateLimiter {

    private const DEFAULT_THRESHOLD = 50;

    public function wouldExceed( int $senderUserId, string $messageType ): bool {
        if ( $senderUserId <= 0 ) return false;  // system sends are uncapped
        if ( \TT\Modules\Comms\Domain\MessageType::isOperational( $messageType ) ) return false;
        $count = (int) get_transient( $this->bucketKey( $senderUserId ) );
        return $count >= $this->threshold();
    }

    public function record( int $senderUserId ): void {
        if ( $senderUserId <= 0 ) return;
        $key   = $this->bucketKey( $senderUserId );
        $count = (int) get_transient( $key );
        set_transient( $key, $count + 1, HOUR_IN_SECONDS );
    }

    private function bucketKey( int $senderUserId ): string {
        return 'tt_comms_rl_' . $senderUserId . '_' . gmdate( 'YmdH' );
    }

    private function threshold(): int {
        if ( ! class_exists( '\\TT\\Infrastructure\\Query\\QueryHelpers' ) ) return self::DEFAULT_THRESHOLD;
        $configured = (int) \TT\Infrastructure\Query\QueryHelpers::get_config( 'comms_rate_limit_per_hour', (string) self::DEFAULT_THRESHOLD );
        return $configured > 0 ? $configured : self::DEFAULT_THRESHOLD;
    }
}
