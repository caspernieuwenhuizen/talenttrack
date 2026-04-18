<?php
namespace TT\Infrastructure\Environment;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * EnvironmentService — environment detection.
 *
 * Honours WordPress's native WP_ENVIRONMENT_TYPE (since WP 5.5):
 *   - 'production'  (default when unset)
 *   - 'staging'
 *   - 'development'
 *   - 'local'
 *
 * Plugin code should use isProduction() / isStaging() / isDevelopment() /
 * isLocal() rather than testing constants directly. This gives us one
 * place to change behaviour if we ever add a TT-specific environment
 * override via config or a TT_ENV constant.
 */
class EnvironmentService {

    public const PRODUCTION  = 'production';
    public const STAGING     = 'staging';
    public const DEVELOPMENT = 'development';
    public const LOCAL       = 'local';

    public function current(): string {
        if ( function_exists( 'wp_get_environment_type' ) ) {
            return (string) wp_get_environment_type();
        }
        return defined( 'WP_ENVIRONMENT_TYPE' ) ? (string) WP_ENVIRONMENT_TYPE : self::PRODUCTION;
    }

    public function isProduction(): bool {
        return $this->current() === self::PRODUCTION;
    }

    public function isStaging(): bool {
        return $this->current() === self::STAGING;
    }

    public function isDevelopment(): bool {
        return $this->current() === self::DEVELOPMENT;
    }

    public function isLocal(): bool {
        return $this->current() === self::LOCAL;
    }

    /**
     * True for any non-production environment.
     */
    public function isNonProduction(): bool {
        return ! $this->isProduction();
    }
}
