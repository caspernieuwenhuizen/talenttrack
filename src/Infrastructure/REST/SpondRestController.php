<?php
namespace TT\Infrastructure\REST;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Logging\Logger;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Spond\CredentialsManager;
use TT\Modules\Spond\SpondClient;
use TT\Modules\Spond\SpondSync;

/**
 * SpondRestController (#0031, extended #1936) — REST surface for the
 * Spond integration.
 *
 *   POST /teams/{id}/spond/sync       — sync one team (existing, #0031)
 *   POST /spond/credentials           — save email + password (#1936)
 *   POST /spond/test                  — live login check (#1936)
 *   DELETE /spond/credentials         — disconnect / clear (#1936)
 *   POST /spond/base-url              — override / revert API base URL (#1936)
 *
 * The per-team sync stays gated on `tt_edit_teams` (it edits team rows).
 * Credential + base-url mutations gate on `tt_edit_spond_credentials`
 * (the matrix `spond_integration → change` cap) — never a role-string
 * compare, never `__return_true`.
 *
 * Controllers stay thin: the encryption, keep-on-blank password, live
 * login, and override-write logic all live in `CredentialsManager` /
 * `SpondClient` / `QueryHelpers`. The frontend Spond view
 * (`?tt_view=spond`, FrontendSpondView) and the wp-admin page call the
 * same domain layer, so a future SaaS frontend gets identical behaviour.
 *
 * Secrets never round-trip: the password is accepted on save/test and
 * stored encrypted, but is never returned in any response. Test only
 * reports ok / a non-sensitive error message.
 */
final class SpondRestController {

    private const NS = 'talenttrack/v1';

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    public static function register(): void {
        register_rest_route( self::NS, '/teams/(?P<id>\d+)/spond/sync', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'syncTeam' ],
            'permission_callback' => [ __CLASS__, 'canEdit' ],
        ] );

        register_rest_route( self::NS, '/spond/credentials', [
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'saveCredentials' ],
                'permission_callback' => [ __CLASS__, 'canEditCredentials' ],
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [ __CLASS__, 'clearCredentials' ],
                'permission_callback' => [ __CLASS__, 'canEditCredentials' ],
            ],
        ] );

        register_rest_route( self::NS, '/spond/test', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'testConnection' ],
            'permission_callback' => [ __CLASS__, 'canEditCredentials' ],
        ] );

        register_rest_route( self::NS, '/spond/base-url', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'saveBaseUrl' ],
            'permission_callback' => [ __CLASS__, 'canEditCredentials' ],
        ] );
    }

    public static function canEdit(): bool {
        return current_user_can( 'tt_edit_teams' );
    }

    public static function canEditCredentials(): bool {
        return current_user_can( 'tt_edit_spond_credentials' );
    }

    public static function syncTeam( \WP_REST_Request $r ): \WP_REST_Response {
        $team_id = (int) $r['id'];
        if ( $team_id <= 0 ) {
            return RestResponse::error( 'bad_team_id', __( 'Team id is required.', 'talenttrack' ), 400 );
        }
        $result = SpondSync::syncTeam( $team_id );
        return RestResponse::success( $result );
    }

    /**
     * Save (or rotate) the per-club Spond credentials. A blank password
     * keeps the stored one — mirrors the wp-admin "leave blank to keep"
     * behaviour. The password is encrypted at rest by CredentialsManager
     * and never echoed back; the response only reports the email + the
     * connected flag.
     */
    public static function saveCredentials( \WP_REST_Request $r ): \WP_REST_Response {
        $email    = sanitize_email( (string) ( $r->get_param( 'email' ) ?? '' ) );
        $password = trim( (string) ( $r->get_param( 'password' ) ?? '' ) );

        if ( $email === '' ) {
            return RestResponse::error(
                'email_required',
                __( 'A Spond email address is required.', 'talenttrack' ),
                422
            );
        }

        if ( $password === '' && CredentialsManager::hasCredentials() ) {
            $password = CredentialsManager::getPassword();
        }

        if ( $password === '' ) {
            return RestResponse::error(
                'password_required',
                __( 'A Spond password is required.', 'talenttrack' ),
                422
            );
        }

        CredentialsManager::save( $email, $password );
        Logger::info( 'rest.spond.credentials_saved', [ 'user' => get_current_user_id() ] );

        return RestResponse::success( [
            'email'     => CredentialsManager::getEmail(),
            'connected' => CredentialsManager::hasCredentials(),
        ] );
    }

    /**
     * Live login check against Spond via SpondClient. Uses the posted
     * email + password when present, otherwise the stored credentials.
     * On success caches the token so the next sync skips a login. The
     * response never contains the password or the token — only ok /
     * a non-sensitive error message.
     */
    public static function testConnection( \WP_REST_Request $r ): \WP_REST_Response {
        $email = CredentialsManager::getEmail();
        $posted_email = sanitize_email( (string) ( $r->get_param( 'email' ) ?? '' ) );
        if ( $posted_email !== '' ) {
            $email = $posted_email;
        }

        $password = trim( (string) ( $r->get_param( 'password' ) ?? '' ) );
        if ( $password === '' ) {
            $password = CredentialsManager::getPassword();
        }

        $result = SpondClient::login( $email, $password );

        if ( ! empty( $result['ok'] ) ) {
            CredentialsManager::cacheToken( (string) $result['token'] );
            Logger::info( 'rest.spond.test_ok', [ 'user' => get_current_user_id() ] );
            return RestResponse::success( [ 'ok' => true ] );
        }

        Logger::warning( 'rest.spond.test_failed', [
            'user' => get_current_user_id(),
            'code' => (string) ( $result['error_code'] ?? 'login_failed' ),
        ] );
        return RestResponse::error(
            (string) ( $result['error_code'] ?? 'login_failed' ),
            (string) ( $result['error_message'] ?? __( 'Spond login failed.', 'talenttrack' ) ),
            422
        );
    }

    /**
     * Disconnect — clear the stored credentials + cached token. Per-team
     * group selections are kept on file (they live on tt_teams), so a
     * reconnect resumes syncing without re-picking groups.
     */
    public static function clearCredentials( \WP_REST_Request $r ): \WP_REST_Response {
        CredentialsManager::clear();
        Logger::info( 'rest.spond.credentials_cleared', [ 'user' => get_current_user_id() ] );
        return RestResponse::success( [ 'connected' => false ] );
    }

    /**
     * Override / revert the Spond API base URL. An empty value clears
     * the override and reverts to SpondClient::DEFAULT_BASE_URL. A
     * non-empty value must look like an http(s) URL.
     */
    public static function saveBaseUrl( \WP_REST_Request $r ): \WP_REST_Response {
        $raw       = trim( (string) ( $r->get_param( 'api_base_url' ) ?? '' ) );
        $sanitised = $raw === '' ? '' : esc_url_raw( $raw );

        if ( $sanitised !== '' && ! preg_match( '#^https?://[^\s]+$#i', $sanitised ) ) {
            return RestResponse::error(
                'invalid_url',
                __( 'That URL does not look like a valid http(s) endpoint.', 'talenttrack' ),
                422
            );
        }

        QueryHelpers::set_config( 'spond.api_base_url', $sanitised );
        Logger::info( 'rest.spond.base_url_saved', [
            'user'    => get_current_user_id(),
            'cleared' => $sanitised === '',
        ] );

        return RestResponse::success( [
            'base_url'   => SpondClient::baseUrl(),
            'is_default' => $sanitised === '',
        ] );
    }
}
