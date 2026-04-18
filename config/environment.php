<?php
/**
 * Environment Configuration
 *
 * Phase 3: This file is now a simple snapshot. Actual environment detection
 * is provided by TT\Infrastructure\Environment\EnvironmentService which
 * reads WordPress's native WP_ENVIRONMENT_TYPE.
 *
 * Available environments: production, staging, development, local.
 * Set WP_ENVIRONMENT_TYPE in wp-config.php to override.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$env = function_exists( 'wp_get_environment_type' )
    ? wp_get_environment_type()
    : ( defined( 'WP_ENVIRONMENT_TYPE' ) ? WP_ENVIRONMENT_TYPE : 'production' );

return [
    'environment' => $env,
    'debug'       => $env !== 'production',
];
