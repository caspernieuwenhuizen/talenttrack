<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Shared\Admin\MySessionsActionHandlers;

/**
 * FrontendMySessionsView (#0086 Workstream B Child 2) — `?tt_view=my-sessions`.
 *
 * Standard SaaS surface — every logged-in user manages their own active
 * sessions. Reads the WordPress `wp_user_meta` `session_tokens` array
 * directly via `WP_Session_Tokens::get_all()` so there's no parallel
 * storage to keep in sync.
 *
 * Audit-log events: `session_revoked` (one per single revoke OR one for
 * the bulk "revoke others" — distinguished via the payload's `mode` field).
 *
 * Mobile-first per CLAUDE.md §2: ≥48px touch targets, semantic HTML,
 * no hover-only affordances.
 */
class FrontendMySessionsView extends FrontendViewBase {

    public static function render( ?object $player = null ): void {
        self::enqueueAssets();
        \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard( __( 'My sessions', 'talenttrack' ) );
        self::renderHeader( __( 'My sessions', 'talenttrack' ) );

        $user_id = get_current_user_id();
        if ( $user_id <= 0 ) {
            FrontendBackButton::render();
            echo '<p class="tt-notice">' . esc_html__( 'You need to be logged in to manage your sessions.', 'talenttrack' ) . '</p>';
            return;
        }

        self::renderFlashMessage();

        $manager = \WP_Session_Tokens::get_instance( $user_id );
        /** @var array<string, array<string, mixed>> $sessions */
        $sessions = $manager->get_all();

        if ( empty( $sessions ) ) {
            echo '<p class="tt-notice">' . esc_html__( 'No active sessions.', 'talenttrack' ) . '</p>';
            return;
        }

        // The verifier of the cookie that authenticated this request —
        // used to mark "this is your current session" and to gate the
        // per-session revoke button so the user can't accidentally
        // log themselves out from this very page.
        $current_token = self::currentSessionTokenHash();

        ?>
        <p style="color: var(--tt-muted, #6a6d66); font-size: 13px; margin: 0 0 var(--tt-sp-3, 12px);">
            <?php esc_html_e( 'These are the devices and browsers currently signed in to your account. If you spot one you do not recognise, revoke it.', 'talenttrack' ); ?>
        </p>

        <?php if ( count( $sessions ) > 1 ) : ?>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom: var(--tt-sp-3, 12px);">
                <?php wp_nonce_field( MySessionsActionHandlers::ACTION_REVOKE_OTHERS, 'tt_my_sessions_nonce' ); ?>
                <input type="hidden" name="action" value="<?php echo esc_attr( MySessionsActionHandlers::ACTION_REVOKE_OTHERS ); ?>" />
                <button type="submit" class="tt-btn tt-btn-secondary" style="min-height: 48px;" onclick="return confirm('<?php echo esc_js( __( 'Revoke every other session? You will stay signed in here, but every other device will need to sign in again.', 'talenttrack' ) ); ?>');">
                    <?php esc_html_e( 'Revoke all other sessions', 'talenttrack' ); ?>
                </button>
            </form>
        <?php endif; ?>

        <ul class="tt-sessions" style="list-style: none; margin: 0; padding: 0; display: grid; gap: var(--tt-sp-2, 8px);">
            <?php foreach ( $sessions as $token => $session ) : ?>
                <?php self::renderSession( (string) $token, $session, $current_token ); ?>
            <?php endforeach; ?>
        </ul>
        <?php
    }

    /**
     * @param array<string, mixed> $session  WP session-tokens row.
     */
    private static function renderSession( string $token, array $session, string $current_token ): void {
        $is_current = ( $current_token !== '' && hash_equals( $current_token, $token ) );

        $login_ts   = isset( $session['login'] )      ? (int) $session['login']      : 0;
        $expire_ts  = isset( $session['expiration'] ) ? (int) $session['expiration'] : 0;
        $ip         = isset( $session['ip'] )  ? (string) $session['ip']  : '';
        $ua         = isset( $session['ua'] )  ? (string) $session['ua']  : '';

        $login_text = $login_ts > 0
            ? sprintf( __( 'Signed in %s', 'talenttrack' ), human_time_diff( $login_ts ) . ' ' . __( 'ago', 'talenttrack' ) )
            : __( 'Sign-in time unknown', 'talenttrack' );

        $expire_text = $expire_ts > 0
            ? sprintf( __( 'Expires in %s', 'talenttrack' ), human_time_diff( time(), $expire_ts ) )
            : '';

        ?>
        <li style="border: 1px solid var(--tt-line, #e0e0e0); border-radius: 4px; padding: var(--tt-sp-3, 12px); background: <?php echo $is_current ? 'var(--tt-bg-soft, #f6f7f8)' : '#fff'; ?>;">
            <div style="display: flex; flex-wrap: wrap; gap: var(--tt-sp-3, 12px); align-items: flex-start;">
                <div style="flex: 1 1 240px; min-width: 0;">
                    <div style="font-weight: 600; margin-bottom: 4px; word-break: break-word;">
                        <?php echo esc_html( self::deviceLabel( $ua ) ); ?>
                        <?php if ( $is_current ) : ?>
                            <span style="display: inline-block; margin-left: 6px; padding: 2px 8px; font-size: 11px; font-weight: 500; color: #fff; background: var(--tt-accent, #5b8def); border-radius: 999px; vertical-align: middle;">
                                <?php esc_html_e( 'This session', 'talenttrack' ); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    <div style="font-size: 13px; color: var(--tt-muted, #6a6d66); margin-bottom: 2px;">
                        <?php echo esc_html( $login_text ); ?>
                        <?php if ( $expire_text !== '' ) : ?>
                            · <?php echo esc_html( $expire_text ); ?>
                        <?php endif; ?>
                    </div>
                    <?php if ( $ip !== '' ) : ?>
                        <div style="font-size: 12px; color: var(--tt-muted, #6a6d66); font-family: monospace;">
                            <?php
                            /* translators: %s: IP address */
                            printf( esc_html__( 'IP %s', 'talenttrack' ), esc_html( $ip ) );
                            ?>
                        </div>
                    <?php endif; ?>
                </div>
                <?php if ( ! $is_current ) : ?>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="flex: 0 0 auto;">
                        <?php wp_nonce_field( MySessionsActionHandlers::ACTION_REVOKE_ONE, 'tt_my_sessions_nonce' ); ?>
                        <input type="hidden" name="action" value="<?php echo esc_attr( MySessionsActionHandlers::ACTION_REVOKE_ONE ); ?>" />
                        <input type="hidden" name="token" value="<?php echo esc_attr( $token ); ?>" />
                        <button type="submit" class="tt-btn tt-btn-secondary" style="min-height: 48px; min-width: 48px;">
                            <?php esc_html_e( 'Revoke', 'talenttrack' ); ?>
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </li>
        <?php
    }

    /**
     * Friendly device label derived from the User-Agent string. Best-
     * effort — UA strings are hostile in general, so we fall back to a
     * generic "Browser" rather than parading a useless "Mozilla/5.0
     * (Windows NT 10.0; …)" at the user.
     */
    private static function deviceLabel( string $ua ): string {
        if ( $ua === '' ) return __( 'Unknown device', 'talenttrack' );

        $ua_l = strtolower( $ua );

        $os = '';
        if      ( strpos( $ua_l, 'iphone' )       !== false ) $os = 'iPhone';
        elseif  ( strpos( $ua_l, 'ipad' )         !== false ) $os = 'iPad';
        elseif  ( strpos( $ua_l, 'android' )      !== false ) $os = 'Android';
        elseif  ( strpos( $ua_l, 'windows' )      !== false ) $os = 'Windows';
        elseif  ( strpos( $ua_l, 'mac os x' )     !== false ) $os = 'macOS';
        elseif  ( strpos( $ua_l, 'linux' )        !== false ) $os = 'Linux';

        $browser = '';
        // Order matters: Edge / Chrome both contain "chrome"; Edge / Edg first.
        if      ( strpos( $ua_l, 'edg/' )         !== false ) $browser = 'Edge';
        elseif  ( strpos( $ua_l, 'firefox' )      !== false ) $browser = 'Firefox';
        elseif  ( strpos( $ua_l, 'chrome' )       !== false ) $browser = 'Chrome';
        elseif  ( strpos( $ua_l, 'safari' )       !== false ) $browser = 'Safari';

        if ( $browser !== '' && $os !== '' ) return sprintf( '%s on %s', $browser, $os );
        if ( $browser !== '' ) return $browser;
        if ( $os !== '' ) return $os;
        return __( 'Unknown device', 'talenttrack' );
    }

    private static function currentSessionTokenHash(): string {
        $cookie = wp_parse_auth_cookie( '', 'logged_in' );
        if ( ! is_array( $cookie ) || empty( $cookie['token'] ) ) {
            return '';
        }
        return (string) $cookie['token'];
    }

    private static function renderFlashMessage(): void {
        $msg = isset( $_GET['tt_msg'] ) ? sanitize_key( (string) $_GET['tt_msg'] ) : '';
        if ( $msg === '' ) return;

        $messages = [
            'session_revoked'             => [ 'success', __( 'Session revoked.', 'talenttrack' ) ],
            'sessions_others_revoked'     => [ 'success', __( 'All other sessions revoked.', 'talenttrack' ) ],
            'session_revoke_invalid'      => [ 'error',   __( 'Could not identify the session to revoke. Try again.', 'talenttrack' ) ],
            'session_revoke_self_blocked' => [ 'error',   __( 'That is your current session — use the browser sign-out, or use "Revoke all other sessions" first.', 'talenttrack' ) ],
        ];
        if ( ! isset( $messages[ $msg ] ) ) return;
        [ $level, $text ] = $messages[ $msg ];
        $cls = $level === 'error' ? 'tt-notice tt-notice-error' : 'tt-notice tt-notice-success';
        echo '<div class="' . esc_attr( $cls ) . '" style="margin-bottom: var(--tt-sp-3, 12px);">' . esc_html( $text ) . '</div>';
    }
}
