<?php
namespace TT\Modules\Spond;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * SpondClient (#0031) — fetches a Spond team iCal feed.
 *
 * Returns either the raw body or a `SpondFetchError` describing the
 * failure. Network + HTTP errors stay distinct from parse errors so
 * the sync loop can surface a useful message in the team-form notice.
 */
final class SpondClient {

    private const TIMEOUT_SECONDS = 10;

    /**
     * @return array{ok:bool, body:string, error_code?:string, error_message?:string, http_code?:int}
     */
    public static function fetch( string $ical_url ): array {
        if ( $ical_url === '' ) {
            return self::error( 'empty_url', __( 'Spond iCal URL is empty.', 'talenttrack' ) );
        }

        $response = wp_remote_get( $ical_url, [
            'timeout'    => self::TIMEOUT_SECONDS,
            'user-agent' => 'TalentTrack/' . ( defined( 'TT_VERSION' ) ? TT_VERSION : 'dev' ),
            'headers'    => [ 'Accept' => 'text/calendar, text/plain' ],
        ] );

        if ( is_wp_error( $response ) ) {
            return self::error(
                'network',
                sprintf( __( 'Could not reach Spond (%s).', 'talenttrack' ), $response->get_error_message() )
            );
        }

        $http_code = (int) wp_remote_retrieve_response_code( $response );
        if ( $http_code === 401 || $http_code === 403 ) {
            return self::error( 'unauthorized', __( 'Spond URL was rejected — has it been revoked?', 'talenttrack' ), $http_code );
        }
        if ( $http_code === 404 ) {
            return self::error( 'not_found', __( 'Spond URL returned 404 — has the team been deleted?', 'talenttrack' ), $http_code );
        }
        if ( $http_code !== 200 ) {
            return self::error( 'bad_status', sprintf( __( 'Spond returned HTTP %d.', 'talenttrack' ), $http_code ), $http_code );
        }

        $body = (string) wp_remote_retrieve_body( $response );
        $type = (string) wp_remote_retrieve_header( $response, 'content-type' );

        if ( $body === '' ) {
            return self::error( 'empty_body', __( 'Spond returned an empty calendar.', 'talenttrack' ), $http_code );
        }
        if ( $type !== '' && stripos( $type, 'text/calendar' ) === false && stripos( $type, 'text/plain' ) === false ) {
            return self::error( 'bad_content_type', __( 'Spond returned a non-calendar response.', 'talenttrack' ), $http_code );
        }
        if ( strpos( $body, 'BEGIN:VCALENDAR' ) === false ) {
            return self::error( 'not_ical', __( 'Response did not look like an iCal feed.', 'talenttrack' ), $http_code );
        }

        return [ 'ok' => true, 'body' => $body, 'http_code' => $http_code ];
    }

    /**
     * @return array{ok:bool, body:string, error_code:string, error_message:string, http_code?:int}
     */
    private static function error( string $code, string $message, int $http_code = 0 ): array {
        return [
            'ok'            => false,
            'body'          => '',
            'error_code'    => $code,
            'error_message' => $message,
            'http_code'     => $http_code,
        ];
    }
}
