<?php
namespace TT\Modules\Stats\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Stats\PlayerStatsService;

/**
 * PlayerRateCardsPage — top-level admin page for rate cards.
 *
 * Sprint 2A (v2.14.0). TalentTrack → Player Rate Cards. Player picker
 * on top; below it, the shared PlayerRateCardView rendered for the
 * selected player. Filters are GET-param driven.
 *
 * URL: admin.php?page=tt-rate-cards[&player_id=N&date_from=&date_to=&eval_type_id=]
 */
class PlayerRateCardsPage {

    private const CAP = 'tt_view_reports';

    public static function init(): void {
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'maybeEnqueue' ] );
    }

    /**
     * Enqueue Chart.js only when the rate-cards top-level page is active.
     * The Players edit page handles its own enqueue (see PlayersPage).
     */
    public static function maybeEnqueue( string $hook ): void {
        // Hook suffix for a top-level admin page is like "toplevel_page_talenttrack"
        // or "talenttrack_page_tt-rate-cards" depending on registration path.
        $page = isset( $_GET['page'] ) ? (string) $_GET['page'] : '';
        if ( $page === 'tt-rate-cards' ) {
            PlayerRateCardView::enqueueChartLibrary();
        }
    }

    public static function render(): void {
        if ( ! current_user_can( self::CAP ) ) {
            wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        }

        $player_id = isset( $_GET['player_id'] ) ? absint( $_GET['player_id'] ) : 0;
        $filters   = PlayerStatsService::sanitizeFilters( $_GET );
        $players   = QueryHelpers::get_players();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Player Rate Cards', 'talenttrack' ); ?></h1>

            <form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" style="margin:12px 0 20px;">
                <input type="hidden" name="page" value="tt-rate-cards" />
                <label>
                    <strong><?php esc_html_e( 'Player:', 'talenttrack' ); ?></strong>
                    <select name="player_id" onchange="this.form.submit()">
                        <option value="0"><?php esc_html_e( '— Select a player —', 'talenttrack' ); ?></option>
                        <?php foreach ( $players as $pl ) : ?>
                            <option value="<?php echo (int) $pl->id; ?>" <?php selected( $player_id, (int) $pl->id ); ?>>
                                <?php echo esc_html( QueryHelpers::player_display_name( $pl ) ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </form>

            <?php if ( $player_id > 0 ) :
                $base_url = add_query_arg(
                    [ 'page' => 'tt-rate-cards', 'player_id' => $player_id ],
                    admin_url( 'admin.php' )
                );
                PlayerRateCardView::render( $player_id, $filters, $base_url );
            else : ?>
                <p><em><?php esc_html_e( 'Pick a player above to see their rate card.', 'talenttrack' ); ?></em></p>
            <?php endif; ?>
        </div>
        <?php
    }
}
