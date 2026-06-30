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

    protected static function enqueueAssets(): void {
        parent::enqueueAssets();
        wp_enqueue_style(
            'tt-frontend-teammate',
            TT_PLUGIN_URL . 'assets/css/frontend-teammate.css',
            [ 'tt-frontend-app-chrome' ],
            TT_VERSION
        );
    }

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
        <div class="tt-mate">
            <div class="tt-mate-head">
                <div class="tt-mate-photo">
                    <?php if ( $photo_url ) : ?>
                        <img src="<?php echo esc_url( $photo_url ); ?>" alt="" />
                    <?php else : ?>
                        <span class="tt-mate-initials"><?php echo esc_html( $initials !== '' ? $initials : '?' ); ?></span>
                    <?php endif; ?>
                </div>
                <div class="tt-mate-id">
                    <h2 class="tt-mate-name"><?php echo esc_html( $display_name ); ?></h2>
                    <?php if ( $team ) : ?>
                        <div class="tt-mate-team">
                            <?php echo esc_html( (string) $team->name ); ?>
                            <?php if ( ! empty( $team->age_group ) ) : ?>
                                — <?php echo esc_html( \TT\Infrastructure\Query\LookupTranslator::byTypeAndName( 'age_group', (string) $team->age_group ) ); ?>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="tt-mate-details">
                <h3 class="tt-mate-details-heading"><?php esc_html_e( 'Playing details', 'talenttrack' ); ?></h3>
                <dl class="tt-mate-dl">
                    <?php if ( $positions ) : ?>
                        <dt class="tt-mate-dt"><?php esc_html_e( 'Positions', 'talenttrack' ); ?></dt>
                        <dd class="tt-mate-dd"><?php echo esc_html( implode( ', ', array_map( [ \TT\Infrastructure\Query\LabelTranslator::class, 'positionLabel' ], array_map( 'strval', $positions ) ) ) ); ?></dd><?php /* #2155 long position descriptions */ ?>
                    <?php endif; ?>
                    <?php if ( ! empty( $mate->jersey_number ) ) : ?>
                        <dt class="tt-mate-dt"><?php esc_html_e( 'Jersey #', 'talenttrack' ); ?></dt>
                        <dd class="tt-mate-dd">#<?php echo esc_html( (string) $mate->jersey_number ); ?></dd>
                    <?php endif; ?>
                    <?php if ( ! empty( $mate->preferred_foot ) ) : ?>
                        <dt class="tt-mate-dt"><?php esc_html_e( 'Preferred foot', 'talenttrack' ); ?></dt>
                        <dd class="tt-mate-dd"><?php echo esc_html( __( (string) $mate->preferred_foot, 'talenttrack' ) ); ?></dd>
                    <?php endif; ?>
                    <?php
                    // #1353 — height/weight removed from the teammate
                    // view. Body measurements of minors are coach data,
                    // not peer data: weight visible to teammates is a
                    // body-image landmine for 13-17-year-olds. Staff
                    // views keep both fields via their own cap gating.
                    ?>
                </dl>
                <p class="tt-mate-note">
                    <?php esc_html_e( 'Teammate details are read-only. Individual ratings, evaluations, and goals stay private.', 'talenttrack' ); ?>
                </p>
            </div>
        </div>
        <?php
    }
}
