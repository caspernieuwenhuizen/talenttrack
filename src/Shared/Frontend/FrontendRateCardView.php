<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Stats\PlayerStatsService;

/**
 * FrontendRateCardView — the "Rate cards" tile destination
 * (analytics group).
 *
 * v3.0.0 slice 5. Streamlined mobile-first version of the admin
 * PlayerRateCardsPage. Picker → selected player's rate card.
 *
 * Reuses PlayerRateCardView::render() from the admin module — the
 * view class is parameterized with a $base_url for filter links, so
 * we just pass in a frontend URL and the same rendering works.
 *
 * Permission gate: tt_view_reports. Observer role has this cap, so
 * this view is their primary frontend entry point. Admins and
 * coaches also have it.
 */
class FrontendRateCardView extends FrontendViewBase {

    public static function render(): void {
        self::enqueueAssets();
        // Chart.js needed for the trend line and radar — the admin
        // view has an enqueue helper, reuse it.
        \TT\Modules\Stats\Admin\PlayerRateCardView::enqueueChartLibrary();

        self::renderHeader( __( 'Rate cards', 'talenttrack' ) );

        $player_id = isset( $_GET['player_id'] ) ? absint( $_GET['player_id'] ) : 0;
        $filters   = PlayerStatsService::sanitizeFilters( $_GET );

        // Full roster — cross-club visibility. Observer use case is
        // club-wide, not team-scoped.
        $players = QueryHelpers::get_players();

        // Picker form. Preserves the current page URL (wherever the
        // [talenttrack_dashboard] shortcode lives) and swaps player_id.
        $current_url = remove_query_arg( [ 'player_id', 'date_from', 'date_to', 'eval_type_id' ] );
        ?>
        <form method="get" action="" style="margin:8px 0 20px;">
            <?php
            // Preserve non-filter query args (page, tt_view) as hidden inputs
            foreach ( $_GET as $k => $v ) {
                if ( in_array( $k, [ 'player_id', 'date_from', 'date_to', 'eval_type_id' ], true ) ) continue;
                if ( is_string( $v ) ) {
                    echo '<input type="hidden" name="' . esc_attr( (string) $k ) . '" value="' . esc_attr( wp_unslash( $v ) ) . '" />';
                }
            }
            ?>
            <label style="display:inline-flex; align-items:center; gap:8px;">
                <strong><?php esc_html_e( 'Player:', 'talenttrack' ); ?></strong>
                <select name="player_id" onchange="this.form.submit()" style="min-width:220px; padding:6px;">
                    <option value="0"><?php esc_html_e( '— Select a player —', 'talenttrack' ); ?></option>
                    <?php foreach ( $players as $pl ) : ?>
                        <option value="<?php echo (int) $pl->id; ?>" <?php selected( $player_id, (int) $pl->id ); ?>>
                            <?php echo esc_html( QueryHelpers::player_display_name( $pl ) ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
        </form>
        <?php

        if ( $player_id <= 0 ) {
            echo '<p><em>' . esc_html__( 'Pick a player above to see their rate card.', 'talenttrack' ) . '</em></p>';
            return;
        }

        // Base URL for filter links inside PlayerRateCardView — same
        // page, with player_id preserved, other filters stripped.
        $base_url = add_query_arg(
            [ 'player_id' => $player_id ],
            $current_url
        );

        // Delegate to the admin view class. It renders FIFA card +
        // headline numbers + radar + trend line. Chart.js must be
        // enqueued (done above).
        echo '<div class="tt-fe-rate-card" style="max-width:100%;">';
        \TT\Modules\Stats\Admin\PlayerRateCardView::render( $player_id, $filters, $base_url );
        echo '</div>';

        // Mobile-first CSS — card grids collapse to single column on
        // narrow viewports, filters stack, numbers stay readable.
        ?>
        <style>
            .tt-fe-rate-card { font-size:14px; }
            @media (max-width: 820px) {
                .tt-fe-rate-card .tt-stats-grid,
                .tt-fe-rate-card .tt-rate-grid {
                    grid-template-columns: minmax(0,1fr) !important;
                }
                .tt-fe-rate-card .tt-rate-card-layout {
                    display: block !important;
                }
            }
        </style>
        <?php
    }
}
