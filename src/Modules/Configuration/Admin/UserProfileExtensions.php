<?php
namespace TT\Modules\Configuration\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Evaluations\EvalDisplayMode;

/**
 * UserProfileExtensions — adds TalentTrack-specific user preferences
 * to the standard WP user-profile screen.
 *
 * F5 of the post-#0019 feature sprint. Per-coach override of the
 * club-wide evaluation display preference. The user can pick:
 *
 *   - "Use club default" (clears the override; reads from tt_config)
 *   - "Detailed"          (forces detailed for this user)
 *   - "Summary"           (forces summary for this user)
 *
 * Visible to any logged-in user on their own profile page; admins
 * can also see+edit it on other users' profiles via the WP user
 * editor.
 */
class UserProfileExtensions {

    public static function init(): void {
        add_action( 'show_user_profile', [ self::class, 'renderFields' ] );
        add_action( 'edit_user_profile', [ self::class, 'renderFields' ] );
        add_action( 'personal_options_update', [ self::class, 'saveFields' ] );
        add_action( 'edit_user_profile_update', [ self::class, 'saveFields' ] );
    }

    public static function renderFields( \WP_User $user ): void {
        $current = (string) get_user_meta( $user->ID, 'tt_eval_display_mode', true );
        $club_default = EvalDisplayMode::clubDefault();
        ?>
        <h2><?php esc_html_e( 'TalentTrack preferences', 'talenttrack' ); ?></h2>
        <table class="form-table">
            <tr>
                <th>
                    <label for="tt_eval_display_mode">
                        <?php esc_html_e( 'Evaluation display', 'talenttrack' ); ?>
                    </label>
                </th>
                <td>
                    <select name="tt_eval_display_mode" id="tt_eval_display_mode">
                        <option value="" <?php selected( $current, '' ); ?>>
                            <?php
                            printf(
                                /* translators: %s is the resolved club default mode (Detailed / Summary) */
                                esc_html__( 'Use club default (%s)', 'talenttrack' ),
                                $club_default === EvalDisplayMode::DETAILED
                                    ? esc_html__( 'Detailed', 'talenttrack' )
                                    : esc_html__( 'Summary', 'talenttrack' )
                            );
                            ?>
                        </option>
                        <option value="<?php echo esc_attr( EvalDisplayMode::DETAILED ); ?>" <?php selected( $current, EvalDisplayMode::DETAILED ); ?>>
                            <?php esc_html_e( 'Detailed — show every subcategory rating', 'talenttrack' ); ?>
                        </option>
                        <option value="<?php echo esc_attr( EvalDisplayMode::SUMMARY ); ?>" <?php selected( $current, EvalDisplayMode::SUMMARY ); ?>>
                            <?php esc_html_e( 'Summary — show only main categories', 'talenttrack' ); ?>
                        </option>
                    </select>
                </td>
            </tr>
        </table>
        <?php
    }

    public static function saveFields( int $user_id ): void {
        if ( ! current_user_can( 'edit_user', $user_id ) ) return;
        if ( ! isset( $_POST['tt_eval_display_mode'] ) ) return;
        $mode = sanitize_key( (string) wp_unslash( $_POST['tt_eval_display_mode'] ) );
        EvalDisplayMode::setUserOverride( $user_id, $mode );
    }
}
