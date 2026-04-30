<?php
namespace TT\Modules\AdminCenterClient;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Logging\Logger;

/**
 * Sender — fire-and-forget HTTPS POST to the Admin Center
 * (#0065 / TTA #0001).
 *
 * Failure modes are quiet by design: a daily phone-home that throws
 * because DNS is down would be worse than no phone-home at all,
 * since it could destabilise the install. So:
 *
 *   - Network failure / 5xx  → silent, retry next tick.
 *   - 4xx                    → warn once per 24h ("our schema drifted").
 *   - 2xx                    → done.
 *
 * The receiver URL is filterable; the default points at the
 * production mothership. Tests / dev installs override via the
 * `tt_admin_center_url` filter.
 */
final class Sender {

    public const DEFAULT_URL = 'https://ops.talenttrack.app/wp-json/ttac/v1/ingest';
    public const TIMEOUT     = 10;
    public const SIGNATURE_HEADER = 'X-TTAC-Signature';

    private const WARN_THROTTLE_OPTION = 'tt_admin_center_last_warn';

    public static function send( string $trigger ): void {
        $payload = PayloadBuilder::build( $trigger );

        $install_id = (string) ( $payload['install_id'] ?? '' );
        $site_url   = (string) ( $payload['site_url']   ?? '' );

        $body = Signer::canonicalize( $payload );
        $sig  = hash_hmac( 'sha256', $body, Signer::deriveSecret( $install_id, $site_url ) );

        $url = self::endpoint();

        $response = wp_remote_post( $url, [
            'timeout'     => self::TIMEOUT,
            'redirection' => 0,
            'blocking'    => true,
            'headers'     => [
                'Content-Type'              => 'application/json',
                self::SIGNATURE_HEADER      => 'sha256=' . $sig,
            ],
            'body'        => $body,
            'data_format' => 'body',
            'user-agent'  => 'TalentTrack/' . ( defined( 'TT_VERSION' ) ? TT_VERSION : '' ) . '; +' . $site_url,
        ] );

        if ( is_wp_error( $response ) ) {
            return;
        }

        $code = (int) wp_remote_retrieve_response_code( $response );

        if ( $code >= 400 && $code < 500 ) {
            self::maybeWarn( $code );
        }
    }

    public static function endpoint(): string {
        $default = defined( 'TT_ADMIN_CENTER_URL' ) ? (string) TT_ADMIN_CENTER_URL : self::DEFAULT_URL;
        return (string) apply_filters( 'tt_admin_center_url', $default );
    }

    private static function maybeWarn( int $code ): void {
        $last = (int) get_option( self::WARN_THROTTLE_OPTION, 0 );
        $now  = time();
        if ( $now - $last < DAY_IN_SECONDS ) {
            return;
        }
        update_option( self::WARN_THROTTLE_OPTION, $now, false );

        $logger = new Logger();
        $logger->warning( 'admin_center.rejected', [ 'http_code' => $code ] );
    }
}
