<?php
namespace TT\Modules\Strava;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * StravaOAuth (#2056) — authorize-URL builder + signed `state` helper.
 *
 * The OAuth callback (`GET /strava/callback`) is the one TalentTrack
 * REST route that cannot use `X-WP-Nonce`: Strava redirects the browser
 * to it, not our JS. It authenticates instead by verifying a signed
 * `state` minted here at connect-initiation. The state binds:
 *
 *   - the connecting player id (so a replayed callback can't bind a
 *     Strava account to a *different* player),
 *   - the club id (tenancy), and
 *   - a timestamp + nonce (so a leaked state expires and isn't
 *     indefinitely replayable).
 *
 * Signature is HMAC-SHA256 keyed on `wp_salt('secure_auth')`; the
 * comparison is constant-time (`hash_equals`). Rotating the salt
 * invalidates outstanding states — acceptable, they live 15 minutes.
 */
final class StravaOAuth {

    /** Outstanding-state lifetime. A connect→consent round trip is seconds. */
    public const STATE_TTL = 900; // 15 minutes

    public static function authorizeUrl( int $player_id ): string {
        $args = [
            'client_id'       => StravaConfig::clientId(),
            'redirect_uri'    => StravaConfig::redirectUri(),
            'response_type'   => 'code',
            'approval_prompt' => 'auto',
            'scope'           => StravaConfig::SCOPE,
            'state'           => self::signState( $player_id ),
        ];
        return StravaConfig::oauthBaseUrl() . '/authorize?' . http_build_query( $args );
    }

    public static function signState( int $player_id ): string {
        $payload = [
            'pid'  => $player_id,
            'club' => CurrentClub::id(),
            'ts'   => time(),
            'n'    => wp_generate_password( 12, false, false ),
        ];
        $body = self::b64url( (string) wp_json_encode( $payload ) );
        $sig  = self::b64url( hash_hmac( 'sha256', $body, self::secret(), true ) );
        return $body . '.' . $sig;
    }

    /**
     * Verify a returned state. Returns the decoded payload
     * (`['pid'=>int,'club'=>int]`) on success, or null when the
     * signature is forged, the state is malformed, or it has expired.
     *
     * @return array{pid:int,club:int}|null
     */
    public static function verifyState( string $state ): ?array {
        if ( $state === '' || substr_count( $state, '.' ) !== 1 ) {
            return null;
        }
        [ $body, $sig ] = explode( '.', $state, 2 );

        $expected = self::b64url( hash_hmac( 'sha256', $body, self::secret(), true ) );
        if ( ! hash_equals( $expected, $sig ) ) {
            return null;
        }

        $json = self::b64url_decode( $body );
        $data = json_decode( $json, true );
        if ( ! is_array( $data ) || ! isset( $data['pid'], $data['ts'] ) ) {
            return null;
        }

        if ( ( time() - (int) $data['ts'] ) > self::STATE_TTL ) {
            return null;
        }

        return [
            'pid'  => (int) $data['pid'],
            'club' => (int) ( $data['club'] ?? 1 ),
        ];
    }

    private static function secret(): string {
        return wp_salt( 'secure_auth' ) . '|tt-strava-oauth-state';
    }

    private static function b64url( string $raw ): string {
        return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' );
    }

    private static function b64url_decode( string $enc ): string {
        return (string) base64_decode( strtr( $enc, '-_', '+/' ) );
    }
}
