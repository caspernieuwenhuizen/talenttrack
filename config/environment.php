<?php
/**
 * Environment Configuration
 *
 * Phase 1 stub. Phase 3 will expand this into full dev/staging/production
 * handling driven by a TT_ENV constant or WP_ENVIRONMENT_TYPE.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$env = defined( 'WP_ENVIRONMENT_TYPE' ) ? WP_ENVIRONMENT_TYPE : 'production';

return [
    'environment' => $env,
    'debug'       => $env !== 'production',
];
