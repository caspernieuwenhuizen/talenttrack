<?php
namespace TT\Modules\AdminCenterClient;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Signer — HMAC-SHA256 over the canonical payload JSON, used for the
 * phone-home protocol (#0065 / TTA #0001).
 *
 * Canonical JSON is a deterministic shape both ends can re-compute
 * from the parsed payload: keys recursively sorted, no whitespace,
 * UTF-8, slashes unescaped. The receiver's signature check has to
 * arrive at the same byte-for-byte string from the parsed body, so
 * any non-deterministic choice (key order, optional spaces, slash
 * escaping) is locked here.
 *
 * v1 secret derivation — locked in TTA #0001 — is
 *   hash('sha256', $install_id . '|' . $site_url)
 * The pipe separator avoids the ambiguity where two different
 * (install_id, site_url) pairs concatenate to the same string.
 * License-key-derived secret is deferred to billing-oversight; the
 * receiver will accept both shapes during a transition window.
 */
final class Signer {

    /**
     * Build the canonical JSON body the HMAC is computed over. Same
     * string the receiver must re-compute when verifying.
     */
    public static function canonicalize( array $payload ): string {
        self::ksortRecursive( $payload );
        $json = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        if ( ! is_string( $json ) ) {
            return '';
        }
        return $json;
    }

    /**
     * v1 secret = sha256( install_id . '|' . site_url ). Both values
     * appear in the payload itself, so the receiver re-derives the
     * secret from what arrives — nothing extra to store.
     */
    public static function deriveSecret( string $install_id, string $site_url ): string {
        return hash( 'sha256', $install_id . '|' . $site_url );
    }

    /**
     * HMAC-SHA256 hex digest, suitable for `X-TTAC-Signature: sha256=<hex>`.
     */
    public static function sign( array $payload, string $install_id, string $site_url ): string {
        $body   = self::canonicalize( $payload );
        $secret = self::deriveSecret( $install_id, $site_url );
        return hash_hmac( 'sha256', $body, $secret );
    }

    private static function ksortRecursive( array &$arr ): void {
        ksort( $arr );
        foreach ( $arr as &$v ) {
            if ( is_array( $v ) ) {
                self::ksortRecursive( $v );
            }
        }
    }
}
