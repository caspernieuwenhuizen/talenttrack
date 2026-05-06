<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;

/**
 * FrontendTeammateView — read-only teammate card seen by a player
 * clicking a teammate on their My Team view.
 *
 * Scope decisions (privacy-preserving):
 *   - Shows name, photo, team, age group, position(s), jersey, foot,
 *     height, weight — the same basics the player already sees on
 *     the My Team roster tiles.
 *   - Does NOT show evaluations, goals, ratings, sessions attended.
 *     Players only see their own performance data.
 *   - Access is team-gated: the requested teammate must be on the
 *     viewer's team. Out-of-team requests render a not-authorised
 *     message, not the card.
 */
class FrontendTeammateView extends FrontendViewBase {

    public static function render( object $viewer, int $teammate_id ): void {
        self::enqueueAssets();
        self::renderHeader( __( 'Teammate', 'talenttrack' ) );

        if ( $teammate_id <= 0 ) {
            echo '<p>' . esc_html__( 'No teammate selected.', 'talenttrack' ) . '</p>';
            return;
        }

        $viewer_team_id = isset( $viewer->team_id ) ? (int) $viewer->team_id : 0;
        $viewer_user_id = isset( $viewer->wp_user_id ) ? (int) $viewer->wp_user_id : (int) get_current_user_id();
        $mate = QueryHelpers::get_player( $teammate_id );
        // A player clicking their own rate card always lands on their
        // own teammate view — even if a team-scope mismatch would
        // otherwise produce a "not on your team" message. Without this
        // self-bypass, a player who is between teams (or whose team
        // is the trial-group pseudo-team) gets a confusing 403 when
        // tapping their own FIFA card.
        $is_self = $mate && (int) ( $mate->wp_user_id ?? 0 ) === $viewer_user_id && $viewer_user_id > 0;
        if ( ! $mate || ( ! $is_self && ( (int) $mate->team_id !== $viewer_team_id || $viewer_team_id <= 0 ) ) ) {
            echo '<p>' . esc_html__( 'This player is not on your team.', 'talenttrack' ) . '</p>';
            return;
        }

        $team = QueryHelpers::get_team( $viewer_team_id );
        $positions_raw = (string) ( $mate->preferred_positions ?? '' );
        $positions = $positions_raw !== '' ? json_decode( $positions_raw, true ) : [];
        $positions = is_array( $positions ) ? $positions : [];

        $photo_url = '';
        if ( ! empty( $mate->photo_url ) ) {
            $photo_url = (string) $mate->photo_url;
        }
        $initials = strtoupper(
            mb_substr( (string) ( $mate->first_name ?? '' ), 0, 1 )
            . mb_substr( (string) ( $mate->last_name ?? '' ), 0, 1 )
        );

        $display_name = QueryHelpers::player_display_name( $mate );

        ?>
        <div style="max-width:560px; margin:0 auto;">
            <div style="display:flex; gap:20px; align-items:center; background:#fff; border:1px solid #e5e7ea; border-radius:10px; padding:20px;">
                <div style="width:96px; height:96px; border-radius:50%; overflow:hidden; background:linear-gradient(135deg,#d0d3d8,#8a8d93); flex-shrink:0; display:flex; align-items:center; justify-content:center;">
                    <?php if ( $photo_url ) : ?>
                        <img src="<?php echo esc_url( $photo_url ); ?>" alt="" style="width:100%; height:100%; object-fit:cover;" />
                    <?php else : ?>
                        <span style="font-family:'Oswald',sans-serif; font-weight:700; font-size:30px; color:#fff;"><?php echo esc_html( $initials !== '' ? $initials : '?' ); ?></span>
                    <?php endif; ?>
                </div>
                <div style="flex:1;">
                    <h2 style="margin:0 0 4px; font-size:22px;"><?php echo esc_html( $display_name ); ?></h2>
                    <?php if ( $team ) : ?>
                        <div style="color:#666; font-size:14px;">
                            <?php echo esc_html( (string) $team->name ); ?>
                            <?php if ( ! empty( $team->age_group ) ) : ?>
                                — <?php echo esc_html( (string) $team->age_group ); ?>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div style="background:#fff; border:1px solid #e5e7ea; border-radius:10px; padding:16px 20px; margin-top:14px;">
                <h3 style="margin-top:0; font-size:16px;"><?php esc_html_e( 'Playing details', 'talenttrack' ); ?></h3>
                <dl style="display:grid; grid-template-columns:140px 1fr; gap:8px 16px; margin:0;">
                    <?php if ( $positions ) : ?>
                        <dt style="color:#666; font-size:13px;"><?php esc_html_e( 'Positions', 'talenttrack' ); ?></dt>
                        <dd style="margin:0;"><?php echo esc_html( implode( ', ', array_map( 'strval', $positions ) ) ); ?></dd>
                    <?php endif; ?>
                    <?php if ( ! empty( $mate->jersey_number ) ) : ?>
                        <dt style="color:#666; font-size:13px;"><?php esc_html_e( 'Jersey #', 'talenttrack' ); ?></dt>
                        <dd style="margin:0;">#<?php echo esc_html( (string) $mate->jersey_number ); ?></dd>
                    <?php endif; ?>
                    <?php if ( ! empty( $mate->preferred_foot ) ) : ?>
                        <dt style="color:#666; font-size:13px;"><?php esc_html_e( 'Preferred foot', 'talenttrack' ); ?></dt>
                        <dd style="margin:0;"><?php echo esc_html( __( (string) $mate->preferred_foot, 'talenttrack' ) ); ?></dd>
                    <?php endif; ?>
                    <?php if ( ! empty( $mate->height_cm ) ) : ?>
                        <dt style="color:#666; font-size:13px;"><?php esc_html_e( 'Height', 'talenttrack' ); ?></dt>
                        <dd style="margin:0;"><?php echo esc_html( (string) $mate->height_cm ); ?> cm</dd>
                    <?php endif; ?>
                    <?php if ( ! empty( $mate->weight_kg ) ) : ?>
                        <dt style="color:#666; font-size:13px;"><?php esc_html_e( 'Weight', 'talenttrack' ); ?></dt>
                        <dd style="margin:0;"><?php echo esc_html( (string) $mate->weight_kg ); ?> kg</dd>
                    <?php endif; ?>
                </dl>
                <p style="color:#888; font-size:12px; margin:16px 0 0; font-style:italic;">
                    <?php esc_html_e( 'Teammate details are read-only. Individual ratings, evaluations, and goals stay private.', 'talenttrack' ); ?>
                </p>
            </div>
        </div>
        <?php
    }
}
