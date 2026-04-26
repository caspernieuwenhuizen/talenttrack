<?php
namespace TT\Modules\Translations\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Translations\TranslationLayer;

/**
 * UserProfilePreference — per-user `tt_translation_pref` radio on
 * the wp-admin profile screen (#0025).
 *
 * Three values: translated (default) / original / side-by-side.
 * Stored as `user_meta` so it follows the user across the site
 * regardless of role or current team.
 *
 * The frontend "My account" view (when it exists) can render the
 * same field by calling `renderField( $user_id )` directly.
 */
final class UserProfilePreference {

    public static function init(): void {
        add_action( 'show_user_profile',          [ __CLASS__, 'renderProfile' ] );
        add_action( 'edit_user_profile',          [ __CLASS__, 'renderProfile' ] );
        add_action( 'personal_options_update',    [ __CLASS__, 'saveProfile' ] );
        add_action( 'edit_user_profile_update',   [ __CLASS__, 'saveProfile' ] );
    }

    public static function renderProfile( \WP_User $user ): void {
        // No point showing the field if the layer is disabled — the
        // preference would have no effect either way. Hide rather than
        // disable so the screen doesn't carry dead UI.
        if ( ! TranslationLayer::isEnabled() ) return;

        $current = TranslationLayer::userPreference( (int) $user->ID );
        ?>
        <h2><?php esc_html_e( 'TalentTrack — translations', 'talenttrack' ); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">
                    <label><?php esc_html_e( 'Translation display', 'talenttrack' ); ?></label>
                </th>
                <td>
                    <?php self::renderField( (int) $user->ID, $current ); ?>
                </td>
            </tr>
        </table>
        <?php
    }

    public static function renderField( int $user_id, ?string $current = null ): void {
        $current = $current ?? TranslationLayer::userPreference( $user_id );
        $options = [
            TranslationLayer::PREF_TRANSLATED   => [
                'label'       => __( 'Translated (default)', 'talenttrack' ),
                'description' => __( 'Show translated content; hide the source.', 'talenttrack' ),
            ],
            TranslationLayer::PREF_ORIGINAL     => [
                'label'       => __( 'Original', 'talenttrack' ),
                'description' => __( 'Never translate. Always show source text.', 'talenttrack' ),
            ],
            TranslationLayer::PREF_SIDE_BY_SIDE => [
                'label'       => __( 'Side-by-side', 'talenttrack' ),
                'description' => __( 'Show translated text followed by the source in parentheses. Useful for verifying accuracy.', 'talenttrack' ),
            ],
        ];
        ?>
        <fieldset>
            <?php foreach ( $options as $key => $opt ) : ?>
                <label style="display:block; margin-bottom:6px;">
                    <input type="radio" name="<?php echo esc_attr( TranslationLayer::USER_META_PREF ); ?>" value="<?php echo esc_attr( $key ); ?>" <?php checked( $current, $key ); ?> />
                    <strong><?php echo esc_html( $opt['label'] ); ?></strong>
                    <span class="description" style="margin-left:8px;"><?php echo esc_html( $opt['description'] ); ?></span>
                </label>
            <?php endforeach; ?>
        </fieldset>
        <?php
    }

    public static function saveProfile( int $user_id ): void {
        if ( ! current_user_can( 'edit_user', $user_id ) ) return;
        if ( ! isset( $_POST[ TranslationLayer::USER_META_PREF ] ) ) return;
        $value = sanitize_key( (string) wp_unslash( $_POST[ TranslationLayer::USER_META_PREF ] ) );
        $allowed = [
            TranslationLayer::PREF_TRANSLATED,
            TranslationLayer::PREF_ORIGINAL,
            TranslationLayer::PREF_SIDE_BY_SIDE,
        ];
        if ( ! in_array( $value, $allowed, true ) ) $value = TranslationLayer::PREF_TRANSLATED;
        update_user_meta( $user_id, TranslationLayer::USER_META_PREF, $value );
    }
}
