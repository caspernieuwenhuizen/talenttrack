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
            <h1><?php esc_html_e( 'Player Rate Cards', 'talenttrack' ); ?> <?php \TT\Shared\Admin\HelpLink::render( 'rate-cards' ); ?></h1>

            <?php
            // #0063 — autocomplete picker (PlayerSearchPickerComponent)
            // replaces the linear-select-of-everyone. Required attribute
            // means the form refuses to submit without a pick instead of
            // silently rendering "no player selected".
            $picker_html = \TT\Shared\Frontend\Components\PlayerSearchPickerComponent::render( [
                'name'             => 'player_id',
                'label'            => __( 'Player', 'talenttrack' ),
                'required'         => true,
                'selected'         => $player_id,
                'show_team_filter' => true,
                'is_admin'         => true,
                'players'          => $players,
            ] );
            ?>
            <form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>"
                  style="margin:12px 0 20px; max-width: 480px;"
                  onsubmit="if (this.querySelector('input[name=player_id]').value === '0' || this.querySelector('input[name=player_id]').value === '') { alert('<?php echo esc_js( __( 'Please select a player first.', 'talenttrack' ) ); ?>'); return false; }">
                <input type="hidden" name="page" value="tt-rate-cards" />
                <?php echo $picker_html; ?>
                <p style="margin: 8px 0 0;">
                    <button type="submit" class="button button-primary"><?php esc_html_e( 'Show rate card', 'talenttrack' ); ?></button>
                </p>
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
