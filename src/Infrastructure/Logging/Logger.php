<?php
namespace TT\Infrastructure\Logging;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Environment\EnvironmentService;

/**
 * Logger — central logging service.
 *
 * Writes to WordPress's native error_log() which routes to:
 *   - wp-content/debug.log if WP_DEBUG_LOG is enabled
 *   - the server's PHP error log otherwise
 *
 * Message format:  [TalentTrack][level] message {context_json}
 *
 * Level filtering:
 *   - In production, debug-level messages are suppressed.
 *   - All other levels (info, warning, error) pass through.
 *
 * Usage:
 *   $logger->info('Player saved', ['id' => 42]);
 *   $logger->error('REST failure', ['endpoint' => '/players', 'code' => 500]);
 */
class Logger {

    public const LEVEL_DEBUG   = 'debug';
    public const LEVEL_INFO    = 'info';
    public const LEVEL_WARNING = 'warning';
    public const LEVEL_ERROR   = 'error';

    /** @var EnvironmentService|null */
    private $environment;

    public function __construct( ?EnvironmentService $environment = null ) {
        $this->environment = $environment;
    }

    /** @param array<string,mixed> $context */
    public function debug( string $message, array $context = [] ): void {
        $this->log( self::LEVEL_DEBUG, $message, $context );
    }

    /** @param array<string,mixed> $context */
    public function info( string $message, array $context = [] ): void {
        $this->log( self::LEVEL_INFO, $message, $context );
    }

    /** @param array<string,mixed> $context */
    public function warning( string $message, array $context = [] ): void {
        $this->log( self::LEVEL_WARNING, $message, $context );
    }

    /** @param array<string,mixed> $context */
    public function error( string $message, array $context = [] ): void {
        $this->log( self::LEVEL_ERROR, $message, $context );
    }

    /** @param array<string,mixed> $context */
    public function log( string $level, string $message, array $context = [] ): void {
        // Suppress debug in production unless WP_DEBUG is explicitly on.
        if ( $level === self::LEVEL_DEBUG && $this->environment && $this->environment->isProduction() ) {
            if ( ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
                return;
            }
        }

        $formatted = sprintf(
            '[TalentTrack][%s] %s%s',
            strtoupper( $level ),
            $message,
            empty( $context ) ? '' : ' ' . wp_json_encode( $context )
        );

        error_log( $formatted );
    }
}
