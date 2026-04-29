<?php
/**
 * bin/contract-test.php (#0052 PR-C) — minimal contract test for the
 * REST surface against the OpenAPI document.
 *
 * Boots WordPress (assumes it's run from a WP install where
 * `wp-load.php` is reachable two directories up by default — override
 * with the WP_LOAD env var). For each known GET endpoint that takes
 * no required path params, calls the endpoint via `rest_do_request()`
 * and validates the response shape against the standard envelope:
 *
 *   { success: bool, data: any, errors: list }
 *
 * Reports per-endpoint pass/fail. Not blocking on every PR — meant to
 * be run manually before a release or via a future CI hook.
 *
 * Usage:
 *
 *   wp-cli:    wp eval-file bin/contract-test.php
 *   raw php:   WP_LOAD=/path/to/wp-load.php php bin/contract-test.php
 */

if ( php_sapi_name() !== 'cli' && ! defined( 'WP_CLI' ) ) {
    fwrite( STDERR, "contract-test.php must be run from the CLI.\n" );
    exit( 1 );
}

// Boot WordPress if not already booted (wp-cli boots it before us).
if ( ! defined( 'ABSPATH' ) ) {
    $wp_load = getenv( 'WP_LOAD' ) ?: dirname( __DIR__, 2 ) . '/wp-load.php';
    if ( ! file_exists( $wp_load ) ) {
        fwrite( STDERR, "Could not find wp-load.php; set WP_LOAD env var.\n" );
        exit( 1 );
    }
    require $wp_load;
}

if ( ! function_exists( 'rest_do_request' ) ) {
    fwrite( STDERR, "rest_do_request unavailable — REST API not loaded.\n" );
    exit( 1 );
}

$endpoints = [
    [ 'GET', '/talenttrack/v1/lookups',                                ],
    [ 'GET', '/talenttrack/v1/audit-log',                              ],
    [ 'GET', '/talenttrack/v1/journey/event-types',                    ],
    [ 'GET', '/talenttrack/v1/journey/cohort-transitions',             ],
    [ 'GET', '/talenttrack/v1/seasons',                                ],
    [ 'GET', '/talenttrack/v1/eval-categories',                        ],
    [ 'GET', '/talenttrack/v1/custom-fields',                          ],
    [ 'GET', '/talenttrack/v1/functional-roles',                       ],
    [ 'GET', '/talenttrack/v1/teams',                                  ],
    [ 'GET', '/talenttrack/v1/players',                                ],
    [ 'GET', '/talenttrack/v1/people',                                 ],
    [ 'GET', '/talenttrack/v1/activities',                             ],
    [ 'GET', '/talenttrack/v1/evaluations',                            ],
    [ 'GET', '/talenttrack/v1/goals',                                  ],
    [ 'GET', '/talenttrack/v1/pdp-files',                              ],
    [ 'GET', '/talenttrack/v1/invitations',                            ],
    [ 'GET', '/talenttrack/v1/docs',                                   ],
    [ 'GET', '/talenttrack/v1/config',                                 ],
];

$pass = 0;
$fail = 0;
$skipped = 0;
$failures = [];

foreach ( $endpoints as [ $method, $path ] ) {
    $req = new \WP_REST_Request( $method, $path );
    $resp = rest_do_request( $req );
    $status = $resp->get_status();
    $body = $resp->get_data();

    // 401/403 are expected when running unauthenticated; these still
    // confirm the route is registered. Treat as skipped, not failed.
    if ( in_array( $status, [ 401, 403 ], true ) ) {
        $skipped++;
        printf( "  SKIP  %s %s  (%d auth required)\n", $method, $path, $status );
        continue;
    }

    if ( $status >= 400 ) {
        $fail++;
        $failures[] = sprintf( '%s %s — HTTP %d', $method, $path, $status );
        printf( "  FAIL  %s %s  (HTTP %d)\n", $method, $path, $status );
        continue;
    }

    if ( ! is_array( $body )
         || ! array_key_exists( 'success', $body )
         || ! array_key_exists( 'data', $body )
         || ! array_key_exists( 'errors', $body )
    ) {
        $fail++;
        $failures[] = sprintf( '%s %s — envelope shape mismatch', $method, $path );
        printf( "  FAIL  %s %s  (envelope shape)\n", $method, $path );
        continue;
    }

    if ( $body['success'] !== true ) {
        $fail++;
        $failures[] = sprintf( '%s %s — success=false', $method, $path );
        printf( "  FAIL  %s %s  (success=false: %s)\n", $method, $path, json_encode( $body['errors'] ) );
        continue;
    }

    $pass++;
    printf( "  PASS  %s %s\n", $method, $path );
}

printf( "\n%d passed · %d failed · %d skipped (auth)\n", $pass, $fail, $skipped );

if ( $fail > 0 ) {
    fwrite( STDERR, "\nFailures:\n" );
    foreach ( $failures as $f ) fwrite( STDERR, "  - {$f}\n" );
    exit( 1 );
}
exit( 0 );
