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
 * Usage — both styles work:
 *
 *   Logger::error('REST failure', ['endpoint' => '/players', 'code' => 500]);
 *   $logger->info('Player saved', ['id' => 42]);
 *
 * The static-call style was introduced ad-hoc in REST controllers but the
 * matching static methods didn't exist — every call was instance-call-on-
 * class, silently deprecated on PHP 7.4 and a hard fatal on PHP 8.0+
 * (see v3.70.1 hotfix). The five public methods are now `static`, while
 * the constructor stays for DI; PHP allows calling static methods via
 * `$obj->method()` so injected loggers (`AuditService`'s `$this->logger`)
 * keep working unchanged.
 *
 * @see https://wiki.php.net/rfc/deprecate_dynamic_properties — PHP 8 fatal
 *      semantics around static-vs-instance methods.
 */
class Logger {

    public const LEVEL_DEBUG   = 'debug';
    public const LEVEL_INFO    = 'info';
    public const LEVEL_WARNING = 'warning';
    public const LEVEL_ERROR   = 'error';

    /** @var EnvironmentService|null */
    private static $environment;

    /** #1360 — guards against persist-path recursion into Logger. */
    private static bool $persisting = false;

    public function __construct( ?EnvironmentService $environment = null ) {
        if ( $environment !== null ) {
            self::$environment = $environment;
        }
    }

    /** @param array<string,mixed> $context */
    public static function debug( string $message, array $context = [] ): void {
        self::log( self::LEVEL_DEBUG, $message, $context );
    }

    /** @param array<string,mixed> $context */
    public static function info( string $message, array $context = [] ): void {
        self::log( self::LEVEL_INFO, $message, $context );
    }

    /** @param array<string,mixed> $context */
    public static function warning( string $message, array $context = [] ): void {
        self::log( self::LEVEL_WARNING, $message, $context );
    }

    /** @param array<string,mixed> $context */
    public static function error( string $message, array $context = [] ): void {
        self::log( self::LEVEL_ERROR, $message, $context );
    }

    /** @param array<string,mixed> $context */
    public static function log( string $level, string $message, array $context = [] ): void {
        // Suppress debug in production unless WP_DEBUG is explicitly on.
        if ( $level === self::LEVEL_DEBUG && self::$environment && self::$environment->isProduction() ) {
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

        // #1360 — errors + warnings additionally persist to
        // `tt_error_log` so operators can read them from wp-admin
        // (Error Log page) without server access. persist() never
        // throws and never calls back into Logger; the re-entrancy
        // flag is belt-and-braces against future hooks in the write
        // path.
        if ( $level === self::LEVEL_ERROR || $level === self::LEVEL_WARNING ) {
            if ( ! self::$persisting ) {
                self::$persisting = true;
                ErrorLogRepository::persist( $level, $message, $context );
                self::$persisting = false;
            }
        }
    }
}
