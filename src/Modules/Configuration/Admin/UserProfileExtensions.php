<?php
namespace TT\Modules\Configuration\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Evaluations\EvalDisplayMode;
use TT\Infrastructure\Identity\PhoneMeta;

/**
 * UserProfileExtensions — adds TalentTrack-specific user preferences
 * to the standard WP user-profile screen.
 *
 * Two surfaces:
 *
 *   - Per-coach evaluation-display preference (post-#0019). Override
 *     of the club-wide setting: "Use club default", "Detailed",
 *     or "Summary".
 *   - Mobile phone number (#0042). Stored encrypted via
 *     PhoneMeta + CredentialEncryption; consumed by the PWA push
 *     dispatcher chain. Editable on a user's own profile and by
 *     admins on other users.
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
        $phone        = PhoneMeta::get( $user->ID );
        $verified_at  = (string) get_user_meta( $user->ID, PhoneMeta::META_VERIFIED_AT, true );
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
            <tr>
                <th>
                    <label for="tt_phone">
                        <?php esc_html_e( 'Mobile phone', 'talenttrack' ); ?>
                    </label>
                </th>
                <td>
                    <input
                        type="tel"
                        name="tt_phone"
                        id="tt_phone"
                        class="regular-text"
                        inputmode="tel"
                        autocomplete="tel"
                        value="<?php echo esc_attr( $phone ); ?>"
                        placeholder="+31612345678"
                    />
                    <p class="description">
                        <?php esc_html_e( 'International format, e.g. +31612345678. Used for PWA push notifications.', 'talenttrack' ); ?>
                        <?php if ( $phone !== '' && $verified_at !== '' ) : ?>
                            <br><strong><?php esc_html_e( 'Verified via push.', 'talenttrack' ); ?></strong>
                        <?php elseif ( $phone !== '' ) : ?>
                            <br><em><?php esc_html_e( 'Not yet verified — install TalentTrack on your phone and accept the push prompt.', 'talenttrack' ); ?></em>
                        <?php endif; ?>
                    </p>
                </td>
            </tr>
        </table>
        <?php
    }

    public static function saveFields( int $user_id ): void {
        if ( ! current_user_can( 'edit_user', $user_id ) ) return;
        if ( isset( $_POST['tt_eval_display_mode'] ) ) {
            $mode = sanitize_key( (string) wp_unslash( $_POST['tt_eval_display_mode'] ) );
            EvalDisplayMode::setUserOverride( $user_id, $mode );
        }
        if ( isset( $_POST['tt_phone'] ) ) {
            $raw      = sanitize_text_field( (string) wp_unslash( $_POST['tt_phone'] ) );
            $existing = PhoneMeta::get( $user_id );
            $next     = PhoneMeta::normalize( $raw );
            if ( $next !== $existing ) {
                PhoneMeta::set( $user_id, $next );
            }
        }
    }
}
