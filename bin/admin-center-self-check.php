<?php
/**
 * bin/admin-center-self-check.php (#0065 / TTA #0001)
 *
 * Three smoke tests for the Admin Center phone-home client. None
 * of them need WordPress; they stub the WP API calls
 * `PayloadBuilder` reaches for so the script runs in CI on a
 * vanilla PHP runner.
 *
 *   1. Shape          — PayloadBuilder::build() emits exactly the
 *                       keys + types declared in tests/fixtures/
 *                       admin-center-payload.schema.php.
 *   2. Privacy        — the serialized payload contains no
 *                       forbidden field name (player_name, etc.).
 *                       Walks recursively.
 *   3. Sign round-trip — Signer::sign() applied to the payload, then
 *                        re-derived with `install_id` + `site_url`
 *                        from the same payload, matches a manually
 *                        re-computed HMAC. Asserts canonicalisation
 *                        is deterministic.
 *   4. Response (#3486) — the control plane's signed answer: a
 *                        verified entitlement is extracted as sent;
 *                        a wrong, foreign or tampered signature, a
 *                        non-JSON body and an unrecognised tier are
 *                        never applied; an explicit free is.
 *
 * Exit 0 on success, 1 on any assertion failure.
 *
 * Usage:
 *   php bin/admin-center-self-check.php
 */

if ( php_sapi_name() !== 'cli' ) {
    fwrite( STDERR, "must be run from CLI\n" );
    exit( 1 );
}

require_once __DIR__ . '/admin-center-self-check.bootstrap.php';

require_once __DIR__ . '/../src/Modules/AdminCenterClient/InstallId.php';
require_once __DIR__ . '/../src/Modules/AdminCenterClient/Signer.php';
require_once __DIR__ . '/../src/Modules/AdminCenterClient/PayloadBuilder.php';
require_once __DIR__ . '/../src/Modules/License/EntitlementSourceInterface.php';
require_once __DIR__ . '/../src/Modules/License/CachedEntitlement.php';
require_once __DIR__ . '/../src/Modules/License/FeatureMap.php';
require_once __DIR__ . '/../src/Modules/AdminCenterClient/ControlPlaneResponse.php';

use TT\Modules\AdminCenterClient\ControlPlaneResponse;
use TT\Modules\AdminCenterClient\PayloadBuilder;
use TT\Modules\AdminCenterClient\Signer;

$failures = [];

function assertOrFail( bool $cond, string $msg ): void {
    global $failures;
    if ( ! $cond ) {
        $failures[] = $msg;
        fwrite( STDERR, "FAIL: $msg\n" );
    }
}

// ---- Build payload ---------------------------------------------------

$payload = PayloadBuilder::build( PayloadBuilder::TRIGGER_DAILY );

// ---- 1. Shape -------------------------------------------------------

$schema = require __DIR__ . '/../tests/fixtures/admin-center-payload.schema.php';

foreach ( $schema as $key => $type ) {
    if ( ! array_key_exists( $key, $payload ) ) {
        assertOrFail( false, "shape: missing key '$key'" );
        continue;
    }
    $value     = $payload[ $key ];
    $allowed   = explode( '|', $type );
    $valueType = is_int( $value ) ? 'integer'
        : ( is_float( $value ) ? 'number'
        : ( is_string( $value ) ? 'string'
        : ( is_bool( $value ) ? 'boolean'
        : ( is_array( $value ) ? 'array'
        : ( is_null( $value ) ? 'null' : gettype( $value ) ) ) ) ) );

    if ( $type === 'uuid' ) {
        $ok = is_string( $value ) && preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $value
        );
        assertOrFail( (bool) $ok, "shape: '$key' is not a UUID v4" );
        continue;
    }

    if ( in_array( 'number', $allowed, true ) && $valueType === 'integer' ) {
        $valueType = 'number';
    }
    assertOrFail(
        in_array( $valueType, $allowed, true ),
        "shape: '$key' expected {$type}, got {$valueType}"
    );
}

$extra_keys = array_diff( array_keys( $payload ), array_keys( $schema ) );
assertOrFail( empty( $extra_keys ), 'shape: unexpected extra top-level keys: ' . implode( ', ', $extra_keys ) );

// ---- 2. Privacy ------------------------------------------------------

$forbidden_keys = [
    'player_name', 'player_first_name', 'player_last_name',
    'first_name', 'last_name',
    'birthdate', 'date_of_birth',
    'photo', 'photo_url', 'avatar_url',
    'evaluations', 'evaluation_text', 'goal_text', 'attendance_log',
    'staff_name', 'coach_name', 'coach_email',
    'club_name',
    'spond_token', 'spond_credentials', 'spond_group_id',
    'message_body', 'recipient_list',
    'export_body', 'export_contents',
    'audit_log',
    'ip_address', 'remote_addr',
    'stack_trace', 'error_message',
];

function walkForbiddenKeys( $node, array $forbidden, string $path = '' ): array {
    $found = [];
    if ( is_array( $node ) ) {
        foreach ( $node as $k => $v ) {
            $childPath = $path === '' ? (string) $k : $path . '.' . $k;
            if ( in_array( strtolower( (string) $k ), $forbidden, true ) ) {
                $found[] = $childPath;
            }
            $found = array_merge( $found, walkForbiddenKeys( $v, $forbidden, $childPath ) );
        }
    }
    return $found;
}

$leaks = walkForbiddenKeys( $payload, $forbidden_keys );
assertOrFail( empty( $leaks ), 'privacy: forbidden keys detected: ' . implode( ', ', $leaks ) );

// #3494 — value signals are the block most tempted across the line
// ("which players have no evaluation" is a useful question the mothership
// may not answer). Exactly five club-wide counts, each an integer: no id,
// no name, no text can fit.
$signals = $payload['value_signals'] ?? null;
assertOrFail(
    is_array( $signals ) && array_keys( $signals ) === [ 'attendance_recorded_30d', 'minutes_recorded_30d', 'evaluations_recorded_30d', 'pdps_active', 'goals_active' ],
    'privacy: value_signals keys changed — add a key here deliberately, after checking it is a club-wide count'
);
foreach ( is_array( $signals ) ? $signals : [] as $k => $v ) {
    assertOrFail( is_int( $v ), "privacy: value_signals.$k is not an integer count" );
}

// ---- 3. Signing round-trip ------------------------------------------

$canonical = Signer::canonicalize( $payload );
$secret    = Signer::deriveSecret( $payload['install_id'], $payload['site_url'] );
$expected  = hash_hmac( 'sha256', $canonical, $secret );
$actual    = Signer::sign( $payload, $payload['install_id'], $payload['site_url'] );

assertOrFail(
    hash_equals( $expected, $actual ),
    "sign: round-trip mismatch (expected $expected, got $actual)"
);

assertOrFail(
    Signer::canonicalize( $payload ) === Signer::canonicalize( $payload ),
    'sign: canonicalize is non-deterministic'
);

$shuffled = $payload;
ksort( $shuffled );
$shuffled = array_reverse( $shuffled, true );
assertOrFail(
    Signer::canonicalize( $shuffled ) === $canonical,
    'sign: canonicalize is order-dependent (must be sort-stable)'
);

// ---- 4. Response path (#3486) ---------------------------------------
//
// The control plane's answer is the channel entitlement now arrives on.
// What an install applies from it is decided here, so the posture that
// matters most is machine-checked: a response that does not verify, or
// carries a tier this install does not know, is never applied — an
// install must not downgrade itself because something malformed came back.

$secret   = Signer::deriveSecret( $payload['install_id'], $payload['site_url'] );
$sign     = static function ( array $body ) use ( $secret ): array {
    $raw = json_encode( $body, JSON_UNESCAPED_SLASHES );
    return [ (string) $raw, 'sha256=' . hash_hmac( 'sha256', Signer::canonicalize( $body ), $secret ) ];
};

[ $raw, $header ] = $sign( [ 'ok' => true, 'entitlement' => [ 'tier' => 'standard', 'issued_at' => 1770000000 ], 'broadcasts' => [] ] );
$decoded = ControlPlaneResponse::decodeVerified( $raw, $header, $secret );
assertOrFail( is_array( $decoded ), 'response: a correctly signed body does not verify' );
assertOrFail(
    is_array( $decoded ) && ControlPlaneResponse::entitlementFrom( $decoded, 1770000100 ) === [ 'tier' => 'standard', 'fetched_at' => 1770000000 ],
    'response: a verified entitlement is not extracted as sent'
);

assertOrFail(
    ControlPlaneResponse::decodeVerified( $raw, 'sha256=' . str_repeat( '0', 64 ), $secret ) === null,
    'response: a body with a wrong signature verifies'
);
assertOrFail(
    ControlPlaneResponse::decodeVerified( $raw, $header, Signer::deriveSecret( 'another-install', $payload['site_url'] ) ) === null,
    'response: a body signed for another install verifies'
);
assertOrFail(
    ControlPlaneResponse::decodeVerified( str_replace( 'standard', 'pro', $raw ), $header, $secret ) === null,
    'response: a tampered body verifies'
);
assertOrFail( ControlPlaneResponse::decodeVerified( 'not json', $header, $secret ) === null, 'response: a non-JSON body verifies' );

[ $raw, $header ] = $sign( [ 'ok' => true, 'entitlement' => [ 'tier' => 'platinum', 'issued_at' => 1770000000 ] ] );
$decoded = ControlPlaneResponse::decodeVerified( $raw, $header, $secret );
assertOrFail(
    is_array( $decoded ) && ControlPlaneResponse::entitlementFrom( $decoded ) === null,
    'response: an unrecognised tier would be applied (it must be ignored, not normalised to free)'
);

[ $raw, $header ] = $sign( [ 'ok' => true, 'entitlement' => [ 'tier' => 'free', 'issued_at' => 1770000000 ] ] );
$decoded = ControlPlaneResponse::decodeVerified( $raw, $header, $secret );
assertOrFail(
    is_array( $decoded ) && ( ControlPlaneResponse::entitlementFrom( $decoded, 1770000100 )['tier'] ?? '' ) === 'free',
    'response: an explicit free (a revocation) is not applied'
);

[ $raw, $header ] = $sign( [ 'ok' => true ] );
$decoded = ControlPlaneResponse::decodeVerified( $raw, $header, $secret );
assertOrFail(
    is_array( $decoded ) && ControlPlaneResponse::entitlementFrom( $decoded ) === null,
    'response: a body with no entitlement key yields an entitlement'
);

// ---- Result ---------------------------------------------------------

if ( ! empty( $failures ) ) {
    fwrite( STDERR, "\n" . count( $failures ) . " failure(s)\n" );
    exit( 1 );
}
echo "admin-center-self-check: ok (shape + privacy + sign-round-trip + response)\n";
exit( 0 );
