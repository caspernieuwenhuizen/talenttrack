<?php
namespace TT\Modules\Translations\Engines;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * GoogleTranslateEngine — adapter for Google Cloud Translation v3
 * (#0025).
 *
 * Auth: a service-account JSON pasted into Configuration. The
 * adapter exchanges the JWT for an access token via the Google
 * OAuth2 endpoint, caches it for the typical 1h TTL via a
 * transient, then calls the v3 API with the token in
 * `Authorization: Bearer …`.
 *
 * The OAuth2 dance is implemented inline so the plugin doesn't take
 * a hard dependency on `google/auth`. If a club already has the
 * Composer package available (e.g. via another plugin), that's
 * fine — we still hand-roll the JWT to avoid version skew.
 */
final class GoogleTranslateEngine implements TranslationEngineInterface {

    private const TOKEN_TRANSIENT = 'tt_translations_gcp_token';
    private const SCOPE           = 'https://www.googleapis.com/auth/cloud-translation';

    private string $service_account_json;
    private int    $timeout;

    public function __construct( string $service_account_json, int $timeout = 8 ) {
        $this->service_account_json = $service_account_json;
        $this->timeout              = max( 2, $timeout );
    }

    public function name(): string {
        return 'google';
    }

    public function pricePer1000Chars(): float {
        // Approximate Cloud Translation v3 retail pricing.
        return 0.018;
    }

    public function translate( string $source, string $source_lang, string $target_lang ): string {
        if ( $source === '' ) return $source;
        $sa     = $this->serviceAccount();
        $token  = $this->accessToken( $sa );
        $body   = [
            'contents'           => [ $source ],
            'targetLanguageCode' => $target_lang,
            'mimeType'           => 'text/plain',
        ];
        if ( $source_lang !== '' ) {
            $body['sourceLanguageCode'] = $source_lang;
        }
        $endpoint = sprintf(
            'https://translation.googleapis.com/v3/projects/%s/locations/global:translateText',
            rawurlencode( (string) $sa['project_id'] )
        );
        $resp = $this->postJson( $endpoint, $body, $token );
        $translations = $resp['translations'] ?? [];
        if ( empty( $translations[0]['translatedText'] ) ) {
            throw new TranslationEngineException( 'Google returned no translation.', TranslationEngineException::CODE_MALFORMED );
        }
        return (string) $translations[0]['translatedText'];
    }

    public function detect( string $source ): array {
        if ( $source === '' ) return [ 'lang' => '', 'confidence' => 0.0 ];
        $sa       = $this->serviceAccount();
        $token    = $this->accessToken( $sa );
        $endpoint = sprintf(
            'https://translation.googleapis.com/v3/projects/%s/locations/global:detectLanguage',
            rawurlencode( (string) $sa['project_id'] )
        );
        $resp = $this->postJson( $endpoint, [
            'content'  => mb_substr( $source, 0, 200 ),
            'mimeType' => 'text/plain',
        ], $token );
        $best = $resp['languages'][0] ?? null;
        if ( ! is_array( $best ) || empty( $best['languageCode'] ) ) {
            return [ 'lang' => '', 'confidence' => 0.0 ];
        }
        return [
            'lang'       => strtolower( (string) $best['languageCode'] ),
            'confidence' => (float) ( $best['confidence'] ?? 0.0 ),
        ];
    }

    /**
     * @return array{project_id:string, client_email:string, private_key:string, token_uri:string}
     */
    private function serviceAccount(): array {
        $decoded = json_decode( $this->service_account_json, true );
        if ( ! is_array( $decoded )
             || empty( $decoded['client_email'] )
             || empty( $decoded['private_key'] )
             || empty( $decoded['project_id'] )
        ) {
            throw new TranslationEngineException( 'Google service-account JSON is missing required fields.', TranslationEngineException::CODE_AUTH );
        }
        return [
            'project_id'   => (string) $decoded['project_id'],
            'client_email' => (string) $decoded['client_email'],
            'private_key'  => (string) $decoded['private_key'],
            'token_uri'    => (string) ( $decoded['token_uri'] ?? 'https://oauth2.googleapis.com/token' ),
        ];
    }

    /**
     * @param array{project_id:string, client_email:string, private_key:string, token_uri:string} $sa
     */
    private function accessToken( array $sa ): string {
        $cached = get_transient( self::TOKEN_TRANSIENT );
        if ( is_string( $cached ) && $cached !== '' ) return $cached;

        $now    = time();
        $header = self::base64url( (string) wp_json_encode( [ 'alg' => 'RS256', 'typ' => 'JWT' ] ) );
        $claim  = self::base64url( (string) wp_json_encode( [
            'iss'   => $sa['client_email'],
            'scope' => self::SCOPE,
            'aud'   => $sa['token_uri'],
            'iat'   => $now,
            'exp'   => $now + 3600,
        ] ) );
        $signing_input = $header . '.' . $claim;
        if ( ! openssl_sign( $signing_input, $signature, $sa['private_key'], OPENSSL_ALGO_SHA256 ) ) {
            throw new TranslationEngineException( 'Failed to sign Google JWT.', TranslationEngineException::CODE_AUTH );
        }
        $jwt = $signing_input . '.' . self::base64url( $signature );

        $resp = wp_remote_post( $sa['token_uri'], [
            'timeout' => $this->timeout,
            'headers' => [ 'Content-Type' => 'application/x-www-form-urlencoded' ],
            'body'    => http_build_query( [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
            ] ),
        ] );
        if ( is_wp_error( $resp ) ) {
            throw new TranslationEngineException(
                'Google token endpoint transport error: ' . $resp->get_error_message(),
                TranslationEngineException::CODE_NETWORK
            );
        }
        $status = (int) wp_remote_retrieve_response_code( $resp );
        $raw    = (string) wp_remote_retrieve_body( $resp );
        if ( $status < 200 || $status >= 300 ) {
            throw new TranslationEngineException( 'Google token HTTP ' . $status . ': ' . substr( $raw, 0, 200 ), TranslationEngineException::CODE_AUTH );
        }
        $decoded = json_decode( $raw, true );
        $token   = is_array( $decoded ) ? (string) ( $decoded['access_token'] ?? '' ) : '';
        if ( $token === '' ) {
            throw new TranslationEngineException( 'Google token response missing access_token.', TranslationEngineException::CODE_MALFORMED );
        }
        $ttl = is_array( $decoded ) ? max( 60, (int) ( $decoded['expires_in'] ?? 3600 ) - 60 ) : 3300;
        set_transient( self::TOKEN_TRANSIENT, $token, $ttl );
        return $token;
    }

    /**
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    private function postJson( string $endpoint, array $body, string $token ): array {
        $resp = wp_remote_post( $endpoint, [
            'timeout' => $this->timeout,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode( $body ),
        ] );
        if ( is_wp_error( $resp ) ) {
            throw new TranslationEngineException(
                'Google API transport error: ' . $resp->get_error_message(),
                TranslationEngineException::CODE_NETWORK
            );
        }
        $status = (int) wp_remote_retrieve_response_code( $resp );
        $raw    = (string) wp_remote_retrieve_body( $resp );
        if ( $status === 401 || $status === 403 ) {
            // Token might have expired in flight — drop transient so the next call refreshes.
            delete_transient( self::TOKEN_TRANSIENT );
            throw new TranslationEngineException( 'Google API auth rejected.', TranslationEngineException::CODE_AUTH );
        }
        if ( $status === 429 ) {
            throw new TranslationEngineException( 'Google API rate limit hit.', TranslationEngineException::CODE_RATE );
        }
        if ( $status < 200 || $status >= 300 ) {
            throw new TranslationEngineException( 'Google API HTTP ' . $status . ': ' . substr( $raw, 0, 200 ), TranslationEngineException::CODE_UNKNOWN );
        }
        $decoded = json_decode( $raw, true );
        if ( ! is_array( $decoded ) ) {
            throw new TranslationEngineException( 'Google API returned non-JSON.', TranslationEngineException::CODE_MALFORMED );
        }
        return $decoded;
    }

    private static function base64url( string $bytes ): string {
        return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
    }
}
