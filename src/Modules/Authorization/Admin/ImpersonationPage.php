<?php
namespace TT\Modules\Authorization\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * ImpersonationPage — small admin surface for the impersonation
 * feature shipped in #0071 child 5. Until v3.80.0 the service +
 * banner + admin-post handler were all in place but no UI invoked
 * them, so the cap holder had no way to actually start a session
 * other than crafting a POST by hand. This page closes that gap.
 *
 * Surface: Users → Impersonate (visible to administrators and
 * `tt_club_admin`; identical cap to the underlying service).
 *
 * Limitations:
 *   - The picker is the standard wp_dropdown_users without async
 *     search. Fine for the dozens-of-users scale TalentTrack
 *     installs run at; an autocomplete can land later if installs
 *     grow.
 *   - End-impersonation is handled by the existing banner; no
 *     dedicated end button on this page.
 */
final class ImpersonationPage {

    public static function init(): void {
        add_action( 'admin_menu', [ self::class, 'register' ], 30 );
    }

    public static function register(): void {
        if ( ! current_user_can( 'tt_impersonate_users' ) ) return;
        add_submenu_page(
            'talenttrack',
            __( 'Impersonate user', 'talenttrack' ),
            __( 'Impersonate user', 'talenttrack' ),
            'tt_impersonate_users',
            'tt-impersonate',
            [ self::class, 'render' ]
        );
    }

    public static function render(): void {
        if ( ! current_user_can( 'tt_impersonate_users' ) ) {
            wp_die( esc_html__( 'You do not have permission to impersonate users.', 'talenttrack' ) );
        }
        $action_url = admin_url( 'admin-post.php' );
        $return_to  = home_url( '/' );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Impersonate user', 'talenttrack' ); ?></h1>
            <p>
                <?php esc_html_e( 'Sign in as another user to view what they see. Every impersonation is audit-logged with your account, the target, and your reason. Use the banner that appears on every page to switch back. Cannot impersonate other administrators.', 'talenttrack' ); ?>
            </p>
            <form method="post" action="<?php echo esc_url( $action_url ); ?>" style="max-width:560px; margin-top:18px;">
                <?php wp_nonce_field( 'tt_impersonation_start', '_tt_impersonation_nonce' ); ?>
                <input type="hidden" name="action" value="tt_impersonation_start" />
                <input type="hidden" name="return_to" value="<?php echo esc_attr( $return_to ); ?>" />

                <table class="form-table">
                    <tr>
                        <th><label for="tt-imp-target"><?php esc_html_e( 'Target user', 'talenttrack' ); ?></label></th>
                        <td>
                            <?php
                            wp_dropdown_users( [
                                'name'              => 'target_user_id',
                                'id'                => 'tt-imp-target',
                                'show_option_none'  => __( '— Select a user —', 'talenttrack' ),
                                'option_none_value' => '0',
                                'orderby'           => 'display_name',
                            ] );
                            ?>
                            <p class="description">
                                <?php esc_html_e( 'Pick the user whose dashboard you want to view as. Excludes administrators (no admin-on-admin).', 'talenttrack' ); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="tt-imp-reason"><?php esc_html_e( 'Reason', 'talenttrack' ); ?></label></th>
                        <td>
                            <input type="text" id="tt-imp-reason" name="reason" class="regular-text" maxlength="200" placeholder="<?php esc_attr_e( 'Optional. Captured in the audit log.', 'talenttrack' ); ?>" />
                            <p class="description">
                                <?php esc_html_e( 'A short note (e.g. "Debugging report-export error for parent X"). Kept in the audit log alongside the start/end timestamps.', 'talenttrack' ); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <?php submit_button( __( 'Start impersonation', 'talenttrack' ) ); ?>
            </form>
        </div>
        <?php
    }
}
