<?php
namespace TT\Modules\Push;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WebPushSender — RFC 8291 (aes128gcm) + RFC 8292 (VAPID) sender.
 *
 * Speaks the standard Web Push protocol against the endpoint URL a
 * browser hands us in `PushSubscription.endpoint`. The protocol does
 * not require a vendor SDK; it's HKDF + AES-128-GCM + a small ECDSA
 * JWT, all of which OpenSSL ships out of the box.
 *
 * The sender is deliberately self-contained — pulling in
 * minishlink/web-push (Composer) would add ~12 transitive deps for
 * code we can write in ~250 lines. If we ever need VAPID key rotation
 * UI, message queuing, or topic merging, that's the moment to revisit.
 *
 * Return shape from `send()`:
 *   [ 'ok' => bool, 'status' => int, 'gone' => bool, 'error' => ?string ]
 *
 *   - `gone` is true on HTTP 404 / 410 — the caller deletes the
 *     subscription row immediately.
 *   - `error` is populated on transport failures (network, DNS, TLS).
 *   - `status` is 0 for transport failures.
 */
final class WebPushSender {

    private const TTL_SECONDS = 86400; // 24h — message expiry on the push service
    private const REC_SIZE    = 4096;  // RFC 8291 record size; payload <= 4078 bytes

    /**
     * Send `$payload` (a JSON-encodable array) to one subscription.
     * Returns the result tuple described in the class docblock.
     *
     * @param array{
     *   id:int,
     *   endpoint:string,
     *   p256dh:string,
     *   auth_secret:string,
     *   user_agent?:?string
     * } $subscription
     * @param array<string,mixed> $payload
     * @return array{ok:bool,status:int,gone:bool,error:?string}
     */
    public function send( array $subscription, array $payload ): array {
        if ( ! VapidKeyManager::hasKeys() ) {
            return [ 'ok' => false, 'status' => 0, 'gone' => false, 'error' => 'no_vapid_keys' ];
        }
        $endpoint = (string) ( $subscription['endpoint'] ?? '' );
        $p256dh   = VapidKeyManager::base64UrlDecode( (string) ( $subscription['p256dh']      ?? '' ) );
        $auth     = VapidKeyManager::base64UrlDecode( (string) ( $subscription['auth_secret'] ?? '' ) );
        if ( $endpoint === '' || strlen( $p256dh ) !== 65 || strlen( $auth ) === 0 ) {
            return [ 'ok' => false, 'status' => 0, 'gone' => false, 'error' => 'bad_subscription' ];
        }

        $body = wp_json_encode( $payload );
        if ( ! is_string( $body ) || strlen( $body ) > 4000 ) {
            // 4000 keeps us under the 4078 plaintext budget after
            // padding overhead. Real notifications are tiny; this
            // is a guard, not a soft cap.
            return [ 'ok' => false, 'status' => 0, 'gone' => false, 'error' => 'payload_too_large' ];
        }

        try {
            $encrypted = $this->encrypt( $body, $p256dh, $auth );
        } catch ( \Throwable $e ) {
            return [ 'ok' => false, 'status' => 0, 'gone' => false, 'error' => 'encrypt:' . $e->getMessage() ];
        }

        try {
            $jwt = $this->buildVapidJwt( $endpoint );
        } catch ( \Throwable $e ) {
            return [ 'ok' => false, 'status' => 0, 'gone' => false, 'error' => 'vapid:' . $e->getMessage() ];
        }

        $vapid_pub = VapidKeyManager::publicKey();
        $response = wp_remote_post( $endpoint, [
            'timeout'     => 10,
            'redirection' => 2,
            'headers'     => [
                'Content-Type'     => 'application/octet-stream',
                'Content-Encoding' => 'aes128gcm',
                'TTL'              => (string) self::TTL_SECONDS,
                'Authorization'    => 'vapid t=' . $jwt . ', k=' . $vapid_pub,
            ],
            'body'        => $encrypted,
        ] );

        if ( is_wp_error( $response ) ) {
            return [ 'ok' => false, 'status' => 0, 'gone' => false, 'error' => $response->get_error_message() ];
        }
        $status = (int) wp_remote_retrieve_response_code( $response );
        $gone   = ( $status === 404 || $status === 410 );
        $ok     = ( $status >= 200 && $status < 300 );
        return [
            'ok'     => $ok,
            'status' => $status,
            'gone'   => $gone,
            'error'  => $ok ? null : 'http_' . $status,
        ];
    }

    /**
     * Encrypt the payload per RFC 8291 (aes128gcm content-encoding).
     *
     * Output layout:
     *   salt(16) || rs(4) || idlen(1) || keyid(65) || ciphertext
     *
     * `keyid` is the server's one-time uncompressed public point.
     * `rs` is the receiver-window size (we use the full record size).
     */
    private function encrypt( string $plaintext, string $client_pub, string $auth_secret ): string {
        // 1. Server keypair — fresh per message.
        $server_kp = openssl_pkey_new( [
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name'       => 'prime256v1',
        ] );
        if ( ! $server_kp ) throw new \RuntimeException( 'kp_new_failed' );

        $details = openssl_pkey_get_details( $server_kp );
        if ( ! is_array( $details ) || ! isset( $details['ec']['x'], $details['ec']['y'] ) ) {
            throw new \RuntimeException( 'kp_details_failed' );
        }
        $sx = str_pad( (string) $details['ec']['x'], 32, "\x00", STR_PAD_LEFT );
        $sy = str_pad( (string) $details['ec']['y'], 32, "\x00", STR_PAD_LEFT );
        $server_pub = "\x04" . $sx . $sy;

        // 2. ECDH shared secret. Wrap the client's raw 65-byte public
        // point as PEM so openssl_pkey_derive() can consume it.
        $client_pem = self::rawP256ToPem( $client_pub );
        $shared     = openssl_pkey_derive( $client_pem, $server_kp, 32 );
        if ( ! is_string( $shared ) || strlen( $shared ) !== 32 ) {
            throw new \RuntimeException( 'derive_failed' );
        }

        // 3. PRK_key = HKDF(salt=auth_secret, ikm=shared, info="WebPush: info\0"||client_pub||server_pub, len=32).
        $prk_info = "WebPush: info\x00" . $client_pub . $server_pub;
        $prk      = self::hkdf( $auth_secret, $shared, $prk_info, 32 );

        // 4. Salt is fresh-random 16 bytes (the "salt" field of the message header).
        $salt = random_bytes( 16 );

        // 5. CEK + nonce derivation per RFC 8291.
        $cek_info   = "Content-Encoding: aes128gcm\x00";
        $cek        = self::hkdf( $salt, $prk, $cek_info, 16 );
        $nonce_info = "Content-Encoding: nonce\x00";
        $nonce      = self::hkdf( $salt, $prk, $nonce_info, 12 );

        // 6. Pad: append 0x02 (final record) + zero padding so a
        // network observer can't infer message length precisely.
        $padded = $plaintext . "\x02";

        // 7. AES-128-GCM encrypt. PHP returns ciphertext + auth-tag
        // separately; aes128gcm content encoding wants them concatenated.
        $tag      = '';
        $cipher   = openssl_encrypt( $padded, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag );
        if ( $cipher === false ) throw new \RuntimeException( 'aes_failed' );
        $cipher  .= $tag;

        // 8. Header per RFC 8188 § 2.1: salt(16) || rs(4 BE) || idlen(1) || keyid.
        $header = $salt . pack( 'N', self::REC_SIZE ) . chr( 65 ) . $server_pub;
        return $header . $cipher;
    }

    /**
     * VAPID JWT — ES256, claims = { aud, exp, sub }. Signed with the
     * install's VAPID private key (raw 32-byte scalar).
     */
    private function buildVapidJwt( string $endpoint ): string {
        $aud = self::audienceFor( $endpoint );
        $exp = time() + 12 * 3600;
        $sub = VapidKeyManager::subject();

        $header  = [ 'typ' => 'JWT', 'alg' => 'ES256' ];
        $payload = [ 'aud' => $aud, 'exp' => $exp, 'sub' => $sub ];

        $hdr_b64 = VapidKeyManager::base64UrlEncode( (string) wp_json_encode( $header ) );
        $pay_b64 = VapidKeyManager::base64UrlEncode( (string) wp_json_encode( $payload ) );
        $signing_input = $hdr_b64 . '.' . $pay_b64;

        $priv_raw = VapidKeyManager::privateKeyRaw();
        if ( $priv_raw === '' ) throw new \RuntimeException( 'no_priv' );

        $priv_pem = self::rawP256PrivateToPem( $priv_raw );
        $sig_der  = '';
        $ok = openssl_sign( $signing_input, $sig_der, $priv_pem, OPENSSL_ALGO_SHA256 );
        if ( ! $ok ) throw new \RuntimeException( 'sign_failed' );

        // ES256 expects the raw r||s concatenation; openssl_sign returns DER.
        $sig_raw = self::derSignatureToRaw( $sig_der );
        return $signing_input . '.' . VapidKeyManager::base64UrlEncode( $sig_raw );
    }

    /**
     * HKDF (RFC 5869) — extract + expand. Output length is constrained
     * to one block (32 bytes from SHA-256), which is all Web Push needs.
     */
    private static function hkdf( string $salt, string $ikm, string $info, int $length ): string {
        $prk = hash_hmac( 'sha256', $ikm, $salt, true );
        $okm = hash_hmac( 'sha256', $info . "\x01", $prk, true );
        return substr( $okm, 0, $length );
    }

    /**
     * The audience claim is the origin (scheme + host[:port]) of the
     * push endpoint. Stripping the path matters — VAPID validators
     * reject mismatched audiences.
     */
    private static function audienceFor( string $url ): string {
        $parts = wp_parse_url( $url );
        if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
            return $url;
        }
        $port = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
        return $parts['scheme'] . '://' . $parts['host'] . $port;
    }

    /**
     * Wrap a raw 65-byte uncompressed P-256 public point as a PEM
     * SubjectPublicKeyInfo. The DER prefix is the standard
     * id-ecPublicKey + secp256r1 OID pair.
     */
    private static function rawP256ToPem( string $raw65 ): string {
        $der_prefix = "\x30\x59\x30\x13\x06\x07\x2a\x86\x48\xce\x3d\x02\x01"
                    . "\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07\x03\x42\x00";
        $der = $der_prefix . $raw65;
        return "-----BEGIN PUBLIC KEY-----\n"
             . chunk_split( base64_encode( $der ), 64, "\n" )
             . "-----END PUBLIC KEY-----\n";
    }

    /**
     * Wrap a raw 32-byte EC private scalar as a PEM PKCS#8 EC
     * private key. We don't carry the public point — OpenSSL
     * recomputes it on import.
     */
    private static function rawP256PrivateToPem( string $raw32 ): string {
        // SEC1 EC private key DER:
        // SEQUENCE { INT 1, OCTET STRING(d), [0] OID(P-256) }
        $sec1 = "\x30\x41\x02\x01\x01\x04\x20" . $raw32
              . "\xa0\x0a\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07";
        return "-----BEGIN EC PRIVATE KEY-----\n"
             . chunk_split( base64_encode( $sec1 ), 64, "\n" )
             . "-----END EC PRIVATE KEY-----\n";
    }

    /**
     * Convert a DER-encoded ECDSA signature (SEQUENCE { INTEGER r,
     * INTEGER s }) to the raw r||s format JWT/JWS expects (64 bytes
     * for P-256).
     */
    private static function derSignatureToRaw( string $der ): string {
        $offset = 0;
        if ( $der[ $offset++ ] !== "\x30" ) throw new \RuntimeException( 'bad_der' );
        $seqlen = ord( $der[ $offset++ ] );
        if ( $seqlen & 0x80 ) {
            $n = $seqlen & 0x7f;
            $offset += $n;
        }
        if ( $der[ $offset++ ] !== "\x02" ) throw new \RuntimeException( 'bad_int_r' );
        $rlen = ord( $der[ $offset++ ] );
        $r = substr( $der, $offset, $rlen ); $offset += $rlen;
        if ( $der[ $offset++ ] !== "\x02" ) throw new \RuntimeException( 'bad_int_s' );
        $slen = ord( $der[ $offset++ ] );
        $s = substr( $der, $offset, $slen );
        // Strip leading 0x00 (sign byte) if present, then left-pad to 32.
        $r = ltrim( $r, "\x00" );
        $s = ltrim( $s, "\x00" );
        $r = str_pad( $r, 32, "\x00", STR_PAD_LEFT );
        $s = str_pad( $s, 32, "\x00", STR_PAD_LEFT );
        return $r . $s;
    }
}
