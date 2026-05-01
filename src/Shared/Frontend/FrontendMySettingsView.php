<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * FrontendMySettingsView — TT-rendered account settings (#0061 round 3).
 *
 * Replaces the old "Edit profile" link in the dashboard user menu that
 * used to bounce the user out to `wp-admin/profile.php`. This surface
 * is intentionally narrow:
 *
 *   - Display name
 *   - First / last name
 *   - Email
 *   - Password (change-only — confirm-current required)
 *
 * Out of scope: application passwords, color schemes, admin colour
 * palettes, biographical info — those are wp-admin features that
 * confuse end users on the frontend.
 *
 * Saves through the existing WP user APIs (`wp_update_user` for
 * profile fields, `check_password_reset_key` is not used because
 * this is an authenticated change-password flow).
 */
class FrontendMySettingsView extends FrontendViewBase {

    public static function render( object $player ): void {
        self::enqueueAssets();
        self::renderHeader( __( 'My settings', 'talenttrack' ) );

        $user_id = get_current_user_id();
        if ( $user_id <= 0 ) {
            FrontendBackButton::render();
            echo '<p class="tt-notice">' . esc_html__( 'You need to be logged in to manage your settings.', 'talenttrack' ) . '</p>';
            return;
        }

        $messages = self::handlePost( $user_id );
        $user = get_userdata( $user_id );
        if ( ! $user ) {
            FrontendBackButton::render();
            echo '<p class="tt-notice">' . esc_html__( 'Could not load your account.', 'talenttrack' ) . '</p>';
            return;
        }

        if ( $messages['success'] !== '' ) {
            echo '<div class="tt-notice tt-notice-success">' . esc_html( $messages['success'] ) . '</div>';
        }
        foreach ( $messages['errors'] as $err ) {
            echo '<div class="tt-notice tt-notice-error">' . esc_html( $err ) . '</div>';
        }

        ?>
        <div style="max-width:560px;">
            <form method="post" class="tt-form" style="margin-bottom:24px;">
                <?php wp_nonce_field( 'tt_my_settings_profile', 'tt_my_settings_profile_nonce' ); ?>
                <input type="hidden" name="tt_my_settings_action" value="update_profile" />

                <h3 style="margin:0 0 12px;"><?php esc_html_e( 'Profile', 'talenttrack' ); ?></h3>

                <div class="tt-grid tt-grid-2">
                    <div class="tt-field">
                        <label class="tt-field-label" for="tt-ms-first-name"><?php esc_html_e( 'First name', 'talenttrack' ); ?></label>
                        <input type="text" id="tt-ms-first-name" name="first_name" class="tt-input" autocomplete="given-name" value="<?php echo esc_attr( (string) $user->first_name ); ?>" />
                    </div>
                    <div class="tt-field">
                        <label class="tt-field-label" for="tt-ms-last-name"><?php esc_html_e( 'Last name', 'talenttrack' ); ?></label>
                        <input type="text" id="tt-ms-last-name" name="last_name" class="tt-input" autocomplete="family-name" value="<?php echo esc_attr( (string) $user->last_name ); ?>" />
                    </div>
                </div>

                <div class="tt-field">
                    <label class="tt-field-label" for="tt-ms-display"><?php esc_html_e( 'Display name', 'talenttrack' ); ?></label>
                    <input type="text" id="tt-ms-display" name="display_name" class="tt-input" autocomplete="nickname" value="<?php echo esc_attr( (string) $user->display_name ); ?>" />
                    <p class="tt-field-hint"><?php esc_html_e( 'How your name appears to coaches and teammates.', 'talenttrack' ); ?></p>
                </div>

                <div class="tt-field">
                    <label class="tt-field-label tt-field-required" for="tt-ms-email"><?php esc_html_e( 'Email', 'talenttrack' ); ?></label>
                    <input type="email" inputmode="email" id="tt-ms-email" name="user_email" class="tt-input" autocomplete="email" required value="<?php echo esc_attr( (string) $user->user_email ); ?>" />
                </div>

                <div class="tt-form-actions">
                    <button type="submit" class="tt-btn tt-btn-primary"><?php esc_html_e( 'Save profile', 'talenttrack' ); ?></button>
                </div>
            </form>

            <form method="post" class="tt-form">
                <?php wp_nonce_field( 'tt_my_settings_password', 'tt_my_settings_password_nonce' ); ?>
                <input type="hidden" name="tt_my_settings_action" value="change_password" />

                <h3 style="margin:0 0 12px;"><?php esc_html_e( 'Change password', 'talenttrack' ); ?></h3>

                <div class="tt-field">
                    <label class="tt-field-label tt-field-required" for="tt-ms-current"><?php esc_html_e( 'Current password', 'talenttrack' ); ?></label>
                    <input type="password" id="tt-ms-current" name="current_password" class="tt-input" autocomplete="current-password" required />
                </div>
                <div class="tt-grid tt-grid-2">
                    <div class="tt-field">
                        <label class="tt-field-label tt-field-required" for="tt-ms-new"><?php esc_html_e( 'New password', 'talenttrack' ); ?></label>
                        <input type="password" id="tt-ms-new" name="new_password" class="tt-input" autocomplete="new-password" minlength="8" required />
                    </div>
                    <div class="tt-field">
                        <label class="tt-field-label tt-field-required" for="tt-ms-confirm"><?php esc_html_e( 'Confirm new password', 'talenttrack' ); ?></label>
                        <input type="password" id="tt-ms-confirm" name="confirm_password" class="tt-input" autocomplete="new-password" minlength="8" required />
                    </div>
                </div>
                <p class="tt-field-hint" style="margin:0 0 12px;"><?php esc_html_e( 'At least 8 characters. Saving will end any other active sessions.', 'talenttrack' ); ?></p>

                <div class="tt-form-actions">
                    <button type="submit" class="tt-btn tt-btn-primary"><?php esc_html_e( 'Change password', 'talenttrack' ); ?></button>
                </div>
            </form>
        </div>
        <?php
    }

    /**
     * Handle POST. Returns messages for the next render. Mirrors the
     * pattern used by FrontendActivitiesManageView::handlePost — keep
     * the surface narrow, hand off to WP's update APIs for the actual
     * mutation, and surface results inline rather than via redirects.
     *
     * @return array{success:string,errors:string[]}
     */
    private static function handlePost( int $user_id ): array {
        $out = [ 'success' => '', 'errors' => [] ];
        if ( $_SERVER['REQUEST_METHOD'] !== 'POST' ) return $out;
        $action = isset( $_POST['tt_my_settings_action'] ) ? sanitize_key( (string) $_POST['tt_my_settings_action'] ) : '';
        if ( $action === '' ) return $out;

        if ( $action === 'update_profile' ) {
            if ( ! isset( $_POST['tt_my_settings_profile_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['tt_my_settings_profile_nonce'] ) ), 'tt_my_settings_profile' ) ) {
                $out['errors'][] = __( 'Security check failed. Reload and try again.', 'talenttrack' );
                return $out;
            }
            $payload = [
                'ID'           => $user_id,
                'first_name'   => sanitize_text_field( wp_unslash( (string) ( $_POST['first_name']   ?? '' ) ) ),
                'last_name'    => sanitize_text_field( wp_unslash( (string) ( $_POST['last_name']    ?? '' ) ) ),
                'display_name' => sanitize_text_field( wp_unslash( (string) ( $_POST['display_name'] ?? '' ) ) ),
                'user_email'   => sanitize_email( wp_unslash( (string) ( $_POST['user_email']   ?? '' ) ) ),
            ];
            if ( $payload['user_email'] === '' || ! is_email( $payload['user_email'] ) ) {
                $out['errors'][] = __( 'Please enter a valid email address.', 'talenttrack' );
                return $out;
            }
            $res = wp_update_user( $payload );
            if ( is_wp_error( $res ) ) {
                $out['errors'][] = (string) $res->get_error_message();
                return $out;
            }
            $out['success'] = __( 'Profile saved.', 'talenttrack' );
            return $out;
        }

        if ( $action === 'change_password' ) {
            if ( ! isset( $_POST['tt_my_settings_password_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['tt_my_settings_password_nonce'] ) ), 'tt_my_settings_password' ) ) {
                $out['errors'][] = __( 'Security check failed. Reload and try again.', 'talenttrack' );
                return $out;
            }
            $current = (string) ( $_POST['current_password'] ?? '' );
            $new     = (string) ( $_POST['new_password']     ?? '' );
            $confirm = (string) ( $_POST['confirm_password'] ?? '' );
            if ( $current === '' || $new === '' ) {
                $out['errors'][] = __( 'Please fill in both your current and new password.', 'talenttrack' );
                return $out;
            }
            if ( strlen( $new ) < 8 ) {
                $out['errors'][] = __( 'New password must be at least 8 characters.', 'talenttrack' );
                return $out;
            }
            if ( $new !== $confirm ) {
                $out['errors'][] = __( 'New password and confirmation do not match.', 'talenttrack' );
                return $out;
            }
            $user = get_userdata( $user_id );
            if ( ! $user || ! wp_check_password( $current, $user->user_pass, $user_id ) ) {
                $out['errors'][] = __( 'Current password is incorrect.', 'talenttrack' );
                return $out;
            }
            wp_set_password( $new, $user_id );
            // wp_set_password logs the user out of every session including this one.
            // Re-authenticate the current session so the response renders as the
            // logged-in user. Best-effort — if wp_signon fails the user just sees
            // the login screen on the next request.
            wp_set_auth_cookie( $user_id, true );
            wp_set_current_user( $user_id );
            $out['success'] = __( 'Password changed. Other devices have been logged out.', 'talenttrack' );
            return $out;
        }

        return $out;
    }
}
