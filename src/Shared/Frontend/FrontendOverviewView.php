<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\CustomFields\CustomFieldsRepository;
use TT\Infrastructure\CustomFields\CustomValuesRepository;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\CustomFields\Frontend\CustomFieldRenderer;

/**
 * FrontendOverviewView — the "My card" tile destination.
 *
 * v3.0.0 slice 3. Replaces the Overview tab of the legacy
 * PlayerDashboardView. Shows the player's FIFA-style card, any
 * populated custom fields, a recent-history radar chart, and a
 * print link.
 */
class FrontendOverviewView extends FrontendViewBase {

    public static function render( object $player ): void {
        self::enqueueAssets();
        self::renderHeader( __( 'My card', 'talenttrack' ) );

        $max = (float) QueryHelpers::get_config( 'rating_max', '5' );

        echo '<div class="tt-overview-grid" style="display:grid; grid-template-columns:minmax(0,1fr) auto; gap:30px; align-items:start;">';

        // Left column: details + radar
        echo '<div class="tt-overview-main">';

        // Player card — condensed list-of-attributes style
        self::renderPlayerDetails( $player );
        self::renderCustomFields( (int) $player->id );

        // Recent-history radar
        $radar = QueryHelpers::player_radar_datasets( (int) $player->id, 3 );
        if ( ! empty( $radar['datasets'] ) ) {
            echo '<div class="tt-radar-wrap" style="margin-top:20px;">';
            echo QueryHelpers::radar_chart_svg( $radar['labels'], $radar['datasets'], $max );
            echo '</div>';
        }
        echo '</div>';

        // Right column: FIFA card + print button
        echo '<div class="tt-overview-card" style="flex-shrink:0;">';
        $print_url = esc_url( add_query_arg( [ 'tt_print' => (int) $player->id ], remove_query_arg( [ 'tt_view' ] ) ) );
        echo '<div style="text-align:right; margin-bottom:8px;">';
        echo '<a href="' . $print_url . '" target="_blank" rel="noopener" style="display:inline-block; padding:6px 12px; border:1px solid #c3c4c7; border-radius:4px; background:#fff; color:#1a1d21; font-size:12px; text-decoration:none;">';
        echo esc_html__( '🖨 Print report', 'talenttrack' );
        echo '</a>';
        echo '</div>';
        \TT\Modules\Stats\Admin\PlayerCardView::renderCard( (int) $player->id, 'md', true );
        echo '</div>';

        echo '</div>';

        // Mobile: single column
        echo '<style>@media (max-width:820px){.tt-overview-grid{grid-template-columns:minmax(0,1fr) !important;} .tt-overview-card{display:flex; justify-content:center;}}</style>';
    }

    private static function renderPlayerDetails( object $player ): void {
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

    private static function renderCustomFields( int $player_id ): void {
        $fields = ( new CustomFieldsRepository() )->getActive( CustomFieldsRepository::ENTITY_PLAYER );
        if ( empty( $fields ) ) return;
        $values = ( new CustomValuesRepository() )->getByEntityKeyed( CustomFieldsRepository::ENTITY_PLAYER, $player_id );

        $has_any = false;
        foreach ( $fields as $f ) {
            $v = $values[ (string) $f->field_key ] ?? null;
            if ( $v !== null && $v !== '' ) { $has_any = true; break; }
        }
        if ( ! $has_any ) return;

        echo '<div class="tt-custom-fields" style="margin-top:16px;">';
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
