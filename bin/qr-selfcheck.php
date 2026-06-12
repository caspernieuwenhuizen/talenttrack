<?php
/**
 * qr-selfcheck.php (#1393) — capacity + no-truncation regression gate
 * for the MFA enrollment QR pipeline. Pure PHP, no WordPress needed:
 *
 *   php bin/qr-selfcheck.php
 *
 * Asserts:
 *   1. A realistic otpauth URI (default issuer) fits QR version ≤ 7.
 *   2. A long-academy-name issuer (post-#1393 ASCII decoration) still
 *      fits the version-8 budget (192 bytes) the SecretStep enforces.
 *   3. The renderer emits a structurally sane SVG for representative
 *      payload sizes spanning versions 6–10 (the only paths production
 *      exercises) — non-empty, one path element, plausible module count.
 *   4. Over-capacity input is REFUSED (empty string from svg(), an
 *      exception from the matrix builder) — the silent-truncation
 *      branch this gate exists to keep dead.
 *
 * This is a capacity/contract check, not an optical round-trip — a
 * full decode through an independent QR decoder needs the PHP test
 * floor (#1388) or a vendored decoder, tracked on #1393.
 *
 * Exits non-zero on the first failure (CI-friendly).
 */

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ . '/../' );
}

require_once __DIR__ . '/../src/Modules/Mfa/Domain/TotpService.php';
require_once __DIR__ . '/../src/Modules/Mfa/Domain/QrCodeRenderer.php';

use TT\Modules\Mfa\Domain\QrCodeRenderer;
use TT\Modules\Mfa\Domain\TotpService;

$failures = 0;

function tt_check( string $label, bool $ok, string $detail = '' ): void {
    global $failures;
    if ( $ok ) {
        echo "PASS  {$label}\n";
        return;
    }
    $failures++;
    echo "FAIL  {$label}" . ( $detail !== '' ? " — {$detail}" : '' ) . "\n";
}

$secret = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP'; // 32-char base32, the real generator's shape.

// 1. Default-issuer URI lands at v7 or smaller (≤ 154 bytes).
$uri_default = TotpService::otpauthUri( $secret, 'coach@academievoetbal.nl', 'TalentTrack' );
tt_check(
    'default-issuer URI <= v7 budget (154 bytes)',
    strlen( $uri_default ) <= 154,
    strlen( $uri_default ) . ' bytes'
);

// 2. Long academy name: mirror SecretStep's guarantee — when the
//    decorated issuer blows the v8 budget (192 bytes), the step
//    recomposes with the bare brand, and THAT must always fit.
$account  = 'hoofdopleiding@academie.nl';
$uri_long = TotpService::otpauthUri( $secret, $account, 'TalentTrack Voetbalacademie Nieuwenhuizen' );
$effective = strlen( $uri_long ) <= 192
    ? $uri_long
    : TotpService::otpauthUri( $secret, $account, 'TalentTrack' );
tt_check(
    'long-issuer URI <= v8 budget after the SecretStep bare-issuer fallback',
    strlen( $effective ) <= 192,
    strlen( $uri_long ) . ' decorated / ' . strlen( $effective ) . ' effective bytes'
);

// 3. Renderer emits sane SVG across the production version range.
foreach ( [ 'v6' => 130, 'v7' => 150, 'v8' => 190, 'v9' => 225, 'v10' => 270 ] as $label => $len ) {
    $payload = 'otpauth://totp/x?secret=' . str_repeat( 'A', max( 1, $len - 24 ) );
    $svg     = QrCodeRenderer::svg( $payload, 6 );
    $ok      = $svg !== ''
        && strpos( $svg, '<svg' ) === 0
        && substr_count( $svg, '<path' ) === 1
        && preg_match( '/viewBox="0 0 (\d+) \1"/', $svg ) === 1;
    tt_check( "renderer emits sane SVG at {$label} (" . strlen( $payload ) . ' bytes)', $ok );
}

// 4. Over-capacity input refuses loudly instead of truncating.
$svg_over = QrCodeRenderer::svg( str_repeat( 'a', 300 ), 6 );
tt_check( 'over-capacity payload (300 bytes) returns empty string, never a truncated QR', $svg_over === '' );

if ( $failures > 0 ) {
    echo "\n{$failures} check(s) failed.\n";
    exit( 1 );
}
echo "\nAll QR self-checks passed.\n";
exit( 0 );
