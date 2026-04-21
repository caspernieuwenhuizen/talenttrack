<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;

/**
 * FrontendMyProfileView — the "My profile" tile destination.
 *
 * v3.0.0 slice 3. Shows the player's personal details in a clean
 * read-friendly layout with a link to the WordPress profile editor
 * (where they can change password, email, display name).
 *
 * Actual editing of player-record fields (team, position, jersey,
 * etc.) is a coach action — players don't edit those themselves.
 * So this view is read-focused with a narrow set of actionable
 * edits via WP profile.
 */
class FrontendMyProfileView extends FrontendViewBase {

    public static function render( object $player ): void {
        self::enqueueAssets();
        self::renderHeader( __( 'My profile', 'talenttrack' ) );

        $user = wp_get_current_user();
        $wp_profile_url = get_edit_profile_url( (int) $user->ID );
        $team = $player->team_id ? QueryHelpers::get_team( (int) $player->team_id ) : null;
        $pos = json_decode( (string) $player->preferred_positions, true );

        ?>
        <div style="max-width:640px;">
            <?php if ( ! empty( $player->photo_url ) ) : ?>
                <div style="display:flex; justify-content:center; margin-bottom:24px;">
                    <img src="<?php echo esc_url( (string) $player->photo_url ); ?>"
                         alt="" style="width:140px; height:140px; object-fit:cover; border-radius:50%; border:3px solid #e5e7ea;" />
                </div>
            <?php endif; ?>

            <div style="background:#fff; border:1px solid #e5e7ea; border-radius:10px; padding:20px 24px; margin-bottom:20px;">
                <h3 style="margin-top:0; font-size:18px;"><?php esc_html_e( 'Playing details', 'talenttrack' ); ?></h3>
                <dl style="display:grid; grid-template-columns:140px 1fr; gap:8px 16px; margin:0;">
                    <dt style="color:#666; font-size:13px;"><?php esc_html_e( 'Name', 'talenttrack' ); ?></dt>
                    <dd style="margin:0; font-weight:500;"><?php echo esc_html( QueryHelpers::player_display_name( $player ) ); ?></dd>

                    <?php if ( $team ) : ?>
                        <dt style="color:#666; font-size:13px;"><?php esc_html_e( 'Team', 'talenttrack' ); ?></dt>
                        <dd style="margin:0;"><?php echo esc_html( (string) $team->name ); ?></dd>
                    <?php endif; ?>

                    <?php if ( ! empty( $team->age_group ?? '' ) ) : ?>
                        <dt style="color:#666; font-size:13px;"><?php esc_html_e( 'Age group', 'talenttrack' ); ?></dt>
                        <dd style="margin:0;"><?php echo esc_html( (string) $team->age_group ); ?></dd>
                    <?php endif; ?>

                    <?php if ( is_array( $pos ) && $pos ) : ?>
                        <dt style="color:#666; font-size:13px;"><?php esc_html_e( 'Positions', 'talenttrack' ); ?></dt>
                        <dd style="margin:0;"><?php echo esc_html( implode( ', ', $pos ) ); ?></dd>
                    <?php endif; ?>

                    <?php if ( $player->preferred_foot ) : ?>
                        <dt style="color:#666; font-size:13px;"><?php esc_html_e( 'Preferred foot', 'talenttrack' ); ?></dt>
                        <dd style="margin:0;"><?php echo esc_html( (string) $player->preferred_foot ); ?></dd>
                    <?php endif; ?>

                    <?php if ( $player->jersey_number ) : ?>
                        <dt style="color:#666; font-size:13px;"><?php esc_html_e( 'Jersey #', 'talenttrack' ); ?></dt>
                        <dd style="margin:0;">#<?php echo esc_html( (string) $player->jersey_number ); ?></dd>
                    <?php endif; ?>

                    <?php if ( $player->height_cm ) : ?>
                        <dt style="color:#666; font-size:13px;"><?php esc_html_e( 'Height', 'talenttrack' ); ?></dt>
                        <dd style="margin:0;"><?php echo esc_html( (string) $player->height_cm ); ?> cm</dd>
                    <?php endif; ?>

                    <?php if ( $player->weight_kg ) : ?>
                        <dt style="color:#666; font-size:13px;"><?php esc_html_e( 'Weight', 'talenttrack' ); ?></dt>
                        <dd style="margin:0;"><?php echo esc_html( (string) $player->weight_kg ); ?> kg</dd>
                    <?php endif; ?>

                    <?php if ( $player->date_of_birth ) : ?>
                        <dt style="color:#666; font-size:13px;"><?php esc_html_e( 'Date of birth', 'talenttrack' ); ?></dt>
                        <dd style="margin:0;"><?php echo esc_html( (string) $player->date_of_birth ); ?></dd>
                    <?php endif; ?>
                </dl>
                <p style="color:#888; font-size:12px; margin:16px 0 0; font-style:italic;">
                    <?php esc_html_e( 'Playing details are maintained by your coach. Contact them for corrections.', 'talenttrack' ); ?>
                </p>
            </div>

            <div style="background:#fff; border:1px solid #e5e7ea; border-radius:10px; padding:20px 24px;">
                <h3 style="margin-top:0; font-size:18px;"><?php esc_html_e( 'Account', 'talenttrack' ); ?></h3>
                <dl style="display:grid; grid-template-columns:140px 1fr; gap:8px 16px; margin:0 0 14px;">
                    <dt style="color:#666; font-size:13px;"><?php esc_html_e( 'Display name', 'talenttrack' ); ?></dt>
                    <dd style="margin:0;"><?php echo esc_html( (string) $user->display_name ); ?></dd>

                    <dt style="color:#666; font-size:13px;"><?php esc_html_e( 'Email', 'talenttrack' ); ?></dt>
                    <dd style="margin:0;"><?php echo esc_html( (string) $user->user_email ); ?></dd>
                </dl>
                <a href="<?php echo esc_url( $wp_profile_url ); ?>" style="display:inline-block; padding:8px 16px; background:#2271b1; color:#fff; border-radius:4px; text-decoration:none; font-size:14px;">
                    <?php esc_html_e( 'Edit account settings', 'talenttrack' ); ?>
                </a>
            </div>
        </div>
        <?php
    }
}
