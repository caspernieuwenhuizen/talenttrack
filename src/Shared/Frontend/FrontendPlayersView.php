<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\CustomFields\CustomFieldsRepository;
use TT\Infrastructure\CustomFields\CustomValuesRepository;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\CustomFields\Frontend\CustomFieldRenderer;

/**
 * FrontendPlayersView — the "Players" tile destination (coaching group).
 *
 * v3.0.0 slice 4. Two modes:
 *   - List: all players across the coach's teams (or all players for admins)
 *   - Detail: ?player_id=N shows the FIFA card + facts + radar for one player
 *
 * Tapping any card in the list drills into the detail view for that player.
 */
class FrontendPlayersView extends FrontendViewBase {

    public static function render( int $user_id, bool $is_admin ): void {
        self::enqueueAssets();

        $pid = isset( $_GET['player_id'] ) ? absint( $_GET['player_id'] ) : 0;
        if ( $pid > 0 && ( $player = QueryHelpers::get_player( $pid ) ) ) {
            self::renderDetail( $player );
            return;
        }

        self::renderHeader( __( 'Players', 'talenttrack' ) );
        self::renderList( $user_id, $is_admin );
    }

    private static function renderList( int $user_id, bool $is_admin ): void {
        $teams = $is_admin ? QueryHelpers::get_teams() : QueryHelpers::get_teams_for_coach( $user_id );
        if ( empty( $teams ) ) {
            echo '<p><em>' . esc_html__( 'No teams assigned — no players to show.', 'talenttrack' ) . '</em></p>';
            return;
        }

        foreach ( $teams as $team ) {
            $players = QueryHelpers::get_players( (int) $team->id );
            if ( empty( $players ) ) continue;

            echo '<section style="margin-bottom:30px;">';
            echo '<h2 style="margin:0 0 12px; font-size:16px;">'
                . esc_html( (string) $team->name ) . '</h2>';
            echo '<div class="tt-grid" style="display:grid; grid-template-columns:repeat(auto-fill, minmax(220px, 1fr)); gap:14px;">';
            foreach ( $players as $pl ) {
                self::renderPlayerTile( $pl );
            }
            echo '</div>';
            echo '</section>';
        }
    }

    private static function renderPlayerTile( object $player ): void {
        $detail_url = add_query_arg(
            [ 'tt_view' => 'players', 'player_id' => (int) $player->id ],
            remove_query_arg( [ 'tt_view', 'player_id' ] )
        );
        $pos = json_decode( (string) $player->preferred_positions, true );
        ?>
        <a href="<?php echo esc_url( $detail_url ); ?>" style="display:block; text-decoration:none; color:inherit;">
            <div class="tt-card" style="transition:transform 150ms ease, box-shadow 150ms ease;"
                 onmouseover="this.style.transform='translateY(-2px)';this.style.boxShadow='0 4px 12px rgba(0,0,0,0.08)';"
                 onmouseout="this.style.transform='';this.style.boxShadow='';">
                <?php if ( ! empty( $player->photo_url ) ) : ?>
                    <div class="tt-card-thumb"><img src="<?php echo esc_url( (string) $player->photo_url ); ?>" alt="" /></div>
                <?php endif; ?>
                <div class="tt-card-body">
                    <h3><?php echo esc_html( QueryHelpers::player_display_name( $player ) ); ?></h3>
                    <?php if ( is_array( $pos ) && $pos ) : ?>
                        <p><strong><?php esc_html_e( 'Pos:', 'talenttrack' ); ?></strong> <?php echo esc_html( implode( ', ', $pos ) ); ?></p>
                    <?php endif; ?>
                    <?php if ( $player->jersey_number ) : ?>
                        <p><strong>#</strong><?php echo esc_html( (string) $player->jersey_number ); ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </a>
        <?php
    }

    private static function renderDetail( object $player ): void {
        // Detail view has its own back button pointing at the Players list,
        // not the tile landing — more useful when drilling from card to card.
        $list_url = add_query_arg( 'tt_view', 'players', remove_query_arg( [ 'tt_view', 'player_id' ] ) );
        ?>
        <p class="tt-back-link" style="margin:6px 0 12px;">
            <a href="<?php echo esc_url( $list_url ); ?>" style="text-decoration:none; color:#555; font-size:14px;">
                <?php esc_html_e( '← Back to players', 'talenttrack' ); ?>
            </a>
        </p>
        <h1 class="tt-fview-title" style="margin:6px 0 18px; font-size:22px; color:#1a1d21;">
            <?php echo esc_html( QueryHelpers::player_display_name( $player ) ); ?>
        </h1>
        <?php
        $max = (float) QueryHelpers::get_config( 'rating_max', '5' );
        $print_url = esc_url( add_query_arg( [ 'tt_print' => (int) $player->id ], remove_query_arg( [ 'tt_view', 'player_id' ] ) ) );
        ?>
        <div style="margin-bottom:10px;">
            <a href="<?php echo $print_url; ?>" target="_blank" rel="noopener"
               style="display:inline-block; padding:6px 12px; border:1px solid #c3c4c7; border-radius:4px; background:#fff; color:#1a1d21; font-size:13px; text-decoration:none;">
                <?php esc_html_e( '🖨 Print report', 'talenttrack' ); ?>
            </a>
        </div>

        <div style="display:flex; gap:30px; flex-wrap:wrap; align-items:flex-start;">
            <div>
                <?php \TT\Modules\Stats\Admin\PlayerCardView::renderCard( (int) $player->id, 'md', true ); ?>
            </div>
            <div style="flex:1; min-width:280px;">
                <?php self::renderPlayerFacts( $player ); ?>
                <?php self::renderCustomFieldsBlock( (int) $player->id ); ?>
                <?php
                $r = QueryHelpers::player_radar_datasets( (int) $player->id, 3 );
                if ( ! empty( $r['datasets'] ) ) {
                    echo '<div class="tt-radar-wrap" style="margin-top:16px;">'
                        . QueryHelpers::radar_chart_svg( $r['labels'], $r['datasets'], $max )
                        . '</div>';
                }
                ?>
            </div>
        </div>
        <?php
    }

    private static function renderPlayerFacts( object $player ): void {
        $pos = json_decode( (string) $player->preferred_positions, true );
        $team = $player->team_id ? QueryHelpers::get_team( (int) $player->team_id ) : null;
        ?>
        <div class="tt-card">
            <?php if ( ! empty( $player->photo_url ) ) : ?>
                <div class="tt-card-thumb"><img src="<?php echo esc_url( (string) $player->photo_url ); ?>" alt="" /></div>
            <?php endif; ?>
            <div class="tt-card-body">
                <h3><?php echo esc_html( QueryHelpers::player_display_name( $player ) ); ?></h3>
                <?php if ( $team ) : ?>
                    <p><strong><?php esc_html_e( 'Team:', 'talenttrack' ); ?></strong> <?php echo esc_html( (string) $team->name ); ?></p>
                <?php endif; ?>
                <?php if ( is_array( $pos ) && $pos ) : ?>
                    <p><strong><?php esc_html_e( 'Pos:', 'talenttrack' ); ?></strong> <?php echo esc_html( implode( ', ', $pos ) ); ?></p>
                <?php endif; ?>
                <?php if ( $player->preferred_foot ) : ?>
                    <p><strong><?php esc_html_e( 'Foot:', 'talenttrack' ); ?></strong> <?php echo esc_html( (string) $player->preferred_foot ); ?></p>
                <?php endif; ?>
                <?php if ( $player->jersey_number ) : ?>
                    <p><strong>#</strong><?php echo esc_html( (string) $player->jersey_number ); ?></p>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    private static function renderCustomFieldsBlock( int $player_id ): void {
        $fields = ( new CustomFieldsRepository() )->getActive( CustomFieldsRepository::ENTITY_PLAYER );
        if ( empty( $fields ) ) return;
        $values = ( new CustomValuesRepository() )->getByEntityKeyed( CustomFieldsRepository::ENTITY_PLAYER, $player_id );

        $has_any = false;
        foreach ( $fields as $f ) {
            $v = $values[ (string) $f->field_key ] ?? null;
            if ( $v !== null && $v !== '' ) { $has_any = true; break; }
        }
        if ( ! $has_any ) return;

        echo '<div class="tt-custom-fields" style="margin-top:12px;">';
        echo '<h4>' . esc_html__( 'Additional Information', 'talenttrack' ) . '</h4>';
        echo '<dl class="tt-custom-fields-list">';
        foreach ( $fields as $f ) {
            $v = $values[ (string) $f->field_key ] ?? null;
            if ( $v === null || $v === '' ) continue;
            echo '<dt>' . esc_html( (string) $f->label ) . '</dt>';
            echo '<dd>' . CustomFieldRenderer::display( $f, $v ) . '</dd>';
        }
        echo '</dl>';
        echo '</div>';
    }
}
