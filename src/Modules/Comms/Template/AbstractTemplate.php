<?php
namespace TT\Modules\Comms\Template;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Comms\Domain\CommsRequest;
use TT\Modules\Comms\Domain\Recipient;

/**
 * AbstractTemplate (#0066) — shared base for the 15 use-case templates.
 *
 * Centralises the per-club override lookup (per spec Q7 lean: top 5
 * templates editable per club via `tt_config['comms_template_<key>_<locale>_subject']`
 * / `_body`) and the locale-fallback chain (recipient locale →
 * request override → site locale → 'en_US').
 *
 * Subclasses implement:
 *   - `key()`, `label()`, `supportedChannels()`, `isEditable()`
 *     (TemplateInterface contract)
 *   - `defaultCopy( $channelKey, $locale ): array{0: subject, 1: body}`
 *     (the hardcoded shipped copy per channel × locale)
 *
 * The base `render()` resolves locale → looks up a per-club override
 * when the template is editable → falls back to `defaultCopy()` →
 * runs token substitution against the request payload.
 *
 * Token convention: `{first_name}` / `{player_name}` / `{coach_name}` /
 * `{activity_title}` / `{date}` / `{deep_link}`. Tokens that don't
 * exist in the payload render as empty strings (operator-side debug
 * prints `??token??` instead — left for a follow-up if a real
 * customer hits a missing-token case).
 */
abstract class AbstractTemplate implements TemplateInterface {

    public function isEditable(): bool { return false; }

    public function render( string $channelKey, CommsRequest $request, Recipient $recipient, string $locale ): array {
        $resolved_locale = self::resolveLocale( $recipient, $request, $locale );

        $copy = null;
        if ( $this->isEditable() ) {
            $copy = self::loadOverride( $this->key(), $channelKey, $resolved_locale );
        }
        if ( $copy === null ) {
            $copy = $this->defaultCopy( $channelKey, $resolved_locale );
        }

        [ $subject, $body ] = $copy;
        $payload = $request->payload + self::recipientTokens( $recipient );

        return [
            self::substitute( (string) $subject, $payload ),
            self::substitute( (string) $body,    $payload ),
        ];
    }

    /**
     * @return array{0: string, 1: string} subject + body for this channel × locale
     */
    abstract protected function defaultCopy( string $channelKey, string $locale ): array;

    private static function resolveLocale( Recipient $recipient, CommsRequest $request, string $given ): string {
        if ( $given !== '' ) return $given;
        if ( $recipient->preferredLocale !== '' ) return $recipient->preferredLocale;
        if ( $request->localeOverride !== null && $request->localeOverride !== '' ) return $request->localeOverride;
        return determine_locale();
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private static function loadOverride( string $template_key, string $channel_key, string $locale ): ?array {
        $base = "comms_template_{$template_key}_{$locale}_{$channel_key}";
        $subject = QueryHelpers::get_config( "{$base}_subject", '' );
        $body    = QueryHelpers::get_config( "{$base}_body",    '' );
        if ( $subject === '' && $body === '' ) return null;
        return [ (string) $subject, (string) $body ];
    }

    /**
     * @return array<string,scalar|null>
     */
    private static function recipientTokens( Recipient $recipient ): array {
        $tokens = [
            'recipient_first_name' => '',
            'recipient_full_name'  => '',
        ];
        if ( $recipient->userId > 0 ) {
            $u = get_userdata( $recipient->userId );
            if ( $u ) {
                $tokens['recipient_first_name'] = (string) ( $u->first_name ?: $u->display_name );
                $tokens['recipient_full_name']  = (string) ( $u->display_name ?: $u->user_login );
            }
        }
        return $tokens;
    }

    /**
     * @param array<string,scalar|null> $payload
     */
    private static function substitute( string $template, array $payload ): string {
        if ( $template === '' ) return '';
        return preg_replace_callback(
            '/\{([a-z_][a-z0-9_]*)\}/',
            static function ( $match ) use ( $payload ) {
                $key = $match[1];
                if ( ! array_key_exists( $key, $payload ) ) return '';
                $val = $payload[ $key ];
                return $val === null ? '' : (string) $val;
            },
            $template
        ) ?? $template;
    }

    /**
     * Convenience accessor for subclasses — picks the EN/NL variant
     * from a `[ 'en_US' => [s, b], 'nl_NL' => [s, b] ]` map.
     *
     * @param array<string,array{0:string,1:string}> $variants
     * @return array{0:string,1:string}
     */
    protected static function pickLocale( array $variants, string $locale ): array {
        if ( isset( $variants[ $locale ] ) ) return $variants[ $locale ];
        $base = explode( '_', $locale, 2 )[0];
        foreach ( $variants as $k => $v ) {
            if ( explode( '_', $k, 2 )[0] === $base ) return $v;
        }
        if ( isset( $variants['en_US'] ) ) return $variants['en_US'];
        return [ '', '' ];
    }
}
