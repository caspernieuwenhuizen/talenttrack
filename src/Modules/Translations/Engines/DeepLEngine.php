<?php
namespace TT\Modules\Translations\Engines;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * DeepLEngine — REST adapter for DeepL's v2 translation + detection
 * API (#0025).
 *
 * Two API hosts:
 *   - api-free.deepl.com  (free tier, 500k chars/month)
 *   - api.deepl.com       (paid tier)
 *
 * The auth key's suffix tells us which host to use: keys ending in
 * `:fx` are free-tier and route to api-free; everything else routes
 * to the paid host.
 */
final class DeepLEngine implements TranslationEngineInterface {

    private string $api_key;
    private int    $timeout;

    public function __construct( string $api_key, int $timeout = 8 ) {
        $this->api_key = trim( $api_key );
        $this->timeout = max( 2, $timeout );
    }

    public function name(): string {
        return 'deepl';
    }

    public function pricePer1000Chars(): float {
        // Approximate paid-tier rate; free tier is €0 up to 500k chars/month.
        return 0.020;
    }

    public function translate( string $source, string $source_lang, string $target_lang ): string {
        if ( $source === '' ) return $source;
        $body = [
            'text'        => [ $source ],
            'target_lang' => strtoupper( $target_lang ),
        ];
        if ( $source_lang !== '' ) {
            $body['source_lang'] = strtoupper( $source_lang );
        }
        $resp = $this->request( '/v2/translate', $body );
        $translations = $resp['translations'] ?? null;
        if ( ! is_array( $translations ) || empty( $translations ) || ! isset( $translations[0]['text'] ) ) {
            throw new TranslationEngineException( 'DeepL returned no translation.', TranslationEngineException::CODE_MALFORMED );
        }
        return (string) $translations[0]['text'];
    }

    public function detect( string $source ): array {
        if ( $source === '' ) return [ 'lang' => '', 'confidence' => 0.0 ];
        // DeepL doesn't expose a dedicated detect endpoint; the
        // translate response carries `detected_source_language` when
        // source_lang is omitted. Translate to a low-cost target
        // (the source's likely English equivalent) just to learn the
        // detection. This costs chars but it's bounded — the caller
        // caches the result on the source-meta row.
        $body = [
            'text'        => [ mb_substr( $source, 0, 200 ) ],
            'target_lang' => 'EN',
        ];
        $resp = $this->request( '/v2/translate', $body );
        $detected = $resp['translations'][0]['detected_source_language'] ?? '';
        $detected = is_string( $detected ) ? strtolower( $detected ) : '';
        return [
            'lang'       => $detected,
            'confidence' => $detected !== '' ? 0.85 : 0.0,
        ];
    }

    /**
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    private function request( string $path, array $body ): array {
        if ( $this->api_key === '' ) {
            throw new TranslationEngineException( 'DeepL API key is not configured.', TranslationEngineException::CODE_AUTH );
        }
        $host = ( substr( $this->api_key, -3 ) === ':fx' ) ? 'https://api-free.deepl.com' : 'https://api.deepl.com';
        $args = [
            'method'  => 'POST',
            'timeout' => $this->timeout,
            'headers' => [
                'Authorization' => 'DeepL-Auth-Key ' . $this->api_key,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode( $body ),
        ];
        $resp = wp_remote_request( $host . $path, $args );
        if ( is_wp_error( $resp ) ) {
            throw new TranslationEngineException(
                'DeepL transport error: ' . $resp->get_error_message(),
                TranslationEngineException::CODE_NETWORK
            );
        }
        $status = (int) wp_remote_retrieve_response_code( $resp );
        $raw    = (string) wp_remote_retrieve_body( $resp );
        if ( $status === 403 || $status === 401 ) {
            throw new TranslationEngineException( 'DeepL auth rejected.', TranslationEngineException::CODE_AUTH );
        }
        if ( $status === 429 ) {
            throw new TranslationEngineException( 'DeepL rate limit hit.', TranslationEngineException::CODE_RATE );
        }
        if ( $status === 456 ) {
            throw new TranslationEngineException( 'DeepL quota exceeded.', TranslationEngineException::CODE_QUOTA );
        }
        if ( $status < 200 || $status >= 300 ) {
            throw new TranslationEngineException( 'DeepL HTTP ' . $status . ': ' . substr( $raw, 0, 200 ), TranslationEngineException::CODE_UNKNOWN );
        }
        $decoded = json_decode( $raw, true );
        if ( ! is_array( $decoded ) ) {
            throw new TranslationEngineException( 'DeepL returned non-JSON response.', TranslationEngineException::CODE_MALFORMED );
        }
        return $decoded;
    }
}
