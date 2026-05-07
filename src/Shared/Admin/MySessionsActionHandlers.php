<?php
namespace TT\Shared\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\Kernel;
use TT\Infrastructure\Audit\AuditService;

/**
 * MySessionsActionHandlers (#0086 Workstream B Child 2) — admin-post
 * endpoints powering the per-session and revoke-all-others actions on
 * `?tt_view=my-sessions`.
 *
 * Both actions act on the *current user's* sessions only — no operator
 * can revoke another user's sessions through this surface. That deliberate
 * narrowing keeps the surface usable by every logged-in user without a
 * separate admin-tier counterpart.
 *
 * Audit event keys: `session_revoked` (one row per per-token revoke OR
 * one row for the bulk "others" revoke). Payload distinguishes the two
 * via the `mode` field: `single` / `all_others`.
 */
final class MySessionsActionHandlers {

    public const ACTION_REVOKE_ONE      = 'tt_my_sessions_revoke';
    public const ACTION_REVOKE_OTHERS   = 'tt_my_sessions_revoke_others';

    public static function init(): void {
        add_action( 'admin_post_' . self::ACTION_REVOKE_ONE,    [ self::class, 'handleRevokeOne' ] );
        add_action( 'admin_post_' . self::ACTION_REVOKE_OTHERS, [ self::class, 'handleRevokeOthers' ] );
    }

    /**
     * Revoke a single session by its token. The token comes from the
     * keys of `WP_Session_Tokens::get_all()`, which are SHA-256 hashes
     * of the actual session cookie value — they are NOT the cookie
     * itself, so leaking one to a form POST is harmless.
     */
    public static function handleRevokeOne(): void {
        if ( ! is_user_logged_in() ) {
            wp_die( esc_html__( 'You must be logged in to manage your sessions.', 'talenttrack' ) );
        }
        check_admin_referer( self::ACTION_REVOKE_ONE, 'tt_my_sessions_nonce' );

        $token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['token'] ) ) : '';
        if ( $token === '' || ! preg_match( '/^[a-f0-9]{64}$/', $token ) ) {
            self::redirectBack( 'session_revoke_invalid' );
        }

        $user_id = get_current_user_id();
        $manager = \WP_Session_Tokens::get_instance( $user_id );
        // Don't let the user accidentally revoke the cookie they're
        // browsing with — the action loses meaning + the next click
        // would 401 them. Surface a friendly message instead.
        $current_token = self::currentSessionToken();
        if ( $current_token !== '' && hash_equals( $current_token, $token ) ) {
            self::redirectBack( 'session_revoke_self_blocked' );
        }

        $manager->destroy( $token );

        self::recordRevoke( $user_id, [
            'mode'  => 'single',
            'token' => substr( $token, 0, 12 ) . '…', // truncated token id for traceability
        ] );

        self::redirectBack( 'session_revoked' );
    }

    /**
     * Revoke every session except the one this request was made from.
     * Maps to `WP_Session_Tokens::destroy_others()` which keeps the
     * supplied token and destroys the rest.
     */
    public static function handleRevokeOthers(): void {
        if ( ! is_user_logged_in() ) {
            wp_die( esc_html__( 'You must be logged in to manage your sessions.', 'talenttrack' ) );
        }
        check_admin_referer( self::ACTION_REVOKE_OTHERS, 'tt_my_sessions_nonce' );

        $user_id = get_current_user_id();
        $manager = \WP_Session_Tokens::get_instance( $user_id );

        $current_token = self::currentSessionToken();
        if ( $current_token === '' ) {
            // No valid current cookie — fall back to a destroy-all so
            // the action still does something useful from the user's
            // perspective ("revoke other sessions, I'm logged in here").
            $manager->destroy_all();
        } else {
            $manager->destroy_others( $current_token );
        }

        self::recordRevoke( $user_id, [ 'mode' => 'all_others' ] );

        self::redirectBack( 'sessions_others_revoked' );
    }

    /**
     * Resolve the verifier (SHA-256 of the cookie token) for the
     * cookie that authenticated this request. Returns '' when no
     * cookie was found — e.g. the request was nonce-only or the user
     * is not logged in via cookie auth.
     */
    private static function currentSessionToken(): string {
        $cookie = wp_parse_auth_cookie( '', 'logged_in' );
        if ( ! is_array( $cookie ) || empty( $cookie['token'] ) ) {
            return '';
        }
        return (string) $cookie['token'];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function recordRevoke( int $user_id, array $payload ): void {
        try {
            /** @var AuditService $audit */
            $audit = Kernel::instance()->container()->get( 'audit' );
            $audit->record( 'session_revoked', 'session', $user_id, $payload );
        } catch ( \Throwable $e ) {
            // Audit failure never blocks the revoke — the destruction
            // already happened on the line above.
        }
    }

    private static function redirectBack( string $msg ): void {
        $referer = wp_get_referer();
        $base    = $referer ?: admin_url();
        $url     = add_query_arg( 'tt_msg', $msg, $base );
        wp_safe_redirect( $url );
        exit;
    }
}
