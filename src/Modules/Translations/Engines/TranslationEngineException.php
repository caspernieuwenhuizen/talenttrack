<?php
namespace TT\Modules\Translations\Engines;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Thrown by an engine adapter when an API call fails (auth, rate
 * limit, network, malformed response). The layer catches this,
 * tries the configured fallback engine if any, and ultimately
 * returns the source string unchanged.
 */
final class TranslationEngineException extends \RuntimeException {

    public const CODE_AUTH      = 'auth';
    public const CODE_RATE      = 'rate_limit';
    public const CODE_QUOTA     = 'quota_exceeded';
    public const CODE_NETWORK   = 'network';
    public const CODE_MALFORMED = 'malformed_response';
    public const CODE_UNKNOWN   = 'unknown';

    private string $reason;

    public function __construct( string $message, string $reason = self::CODE_UNKNOWN, ?\Throwable $previous = null ) {
        parent::__construct( $message, 0, $previous );
        $this->reason = $reason;
    }

    public function reason(): string {
        return $this->reason;
    }

    public function isRecoverable(): bool {
        return in_array( $this->reason, [ self::CODE_RATE, self::CODE_NETWORK, self::CODE_UNKNOWN ], true );
    }
}
