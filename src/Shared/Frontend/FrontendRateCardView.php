<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Stats\PlayerStatsService;
use TT\Shared\Frontend\Components\PlayerSearchPickerComponent;
use TT\Shared\Frontend\Components\TeamPickerComponent;

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

        \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard( __( 'Rate cards', 'talenttrack' ) );
        self::renderHeader( __( 'Rate cards', 'talenttrack' ) );

        // #0011 — feature gate. Full rate card analytics (trends + radar +
        // comparison panels) is Standard-tier; Free sees an upgrade nudge.
        if ( \TT\Core\ModuleRegistry::isEnabled( 'TT\\Modules\\License\\LicenseModule' )
             && class_exists( '\TT\Modules\License\LicenseGate' )
             && ! \TT\Modules\License\LicenseGate::can( 'rate_cards_full' )
        ) {
            echo \TT\Modules\License\Admin\UpgradeNudge::inline(
                __( 'Rate cards (full analytics)', 'talenttrack' ),
                'standard'
            );
            return;
        }

        $player_id = isset( $_GET['player_id'] ) ? absint( $_GET['player_id'] ) : 0;
        $team_id   = isset( $_GET['team_id'] ) ? absint( $_GET['team_id'] ) : 0;
        $filters   = PlayerStatsService::sanitizeFilters( $_GET );

        // F3 — when the user coaches multiple teams, ask for a team
        // first; the player picker is then filtered to that team.
        // Admins and observers see cross-team and don't need the
        // upfront team filter, but it remains available as an opt-in
        // narrowing.
        $user_id    = get_current_user_id();
        $is_admin   = current_user_can( 'tt_edit_settings' );
        $coach_teams = TeamPickerComponent::resolveTeams( $user_id, $is_admin );

        // Auto-select the only team if the user has just one — saves
        // the click without changing the model.
        if ( $team_id <= 0 && count( $coach_teams ) === 1 ) {
            $team_id = (int) $coach_teams[0]->id;
        }

        // Resolve the candidate player set:
        //   - team_id given → that team
        //   - admin without team → all players
        //   - non-admin without team → coach's teams' players
        if ( $team_id > 0 ) {
            $players = QueryHelpers::get_players( $team_id );
        } elseif ( $is_admin ) {
            $players = QueryHelpers::get_players();
        } else {
            $players = [];
            foreach ( $coach_teams as $t ) {
                foreach ( QueryHelpers::get_players( (int) $t->id ) as $pl ) {
                    $players[] = $pl;
                }
            }
        }

        // Picker form. Preserves the current page URL (wherever the
        // [talenttrack_dashboard] shortcode lives) and swaps player_id.
        $current_url = remove_query_arg( [ 'player_id', 'team_id', 'date_from', 'date_to', 'eval_type_id' ] );
        ?>
        <form method="get" action="" class="tt-grid tt-grid-2" style="margin:8px 0 20px; gap:12px; max-width:680px;">
            <?php
            // Preserve non-filter query args (page, tt_view) as hidden inputs
            foreach ( $_GET as $k => $v ) {
                if ( in_array( $k, [ 'player_id', 'team_id', 'date_from', 'date_to', 'eval_type_id' ], true ) ) continue;
                if ( is_string( $v ) ) {
                    echo '<input type="hidden" name="' . esc_attr( (string) $k ) . '" value="' . esc_attr( wp_unslash( $v ) ) . '" />';
                }
            }

            // Team picker: required for multi-team coaches; optional
            // for admins/observers (a "(all teams)" option is still
            // available via the placeholder).
            echo TeamPickerComponent::render( [
                'name'        => 'team_id',
                'label'       => __( 'Team', 'talenttrack' ),
                'teams'       => $coach_teams,
                'selected'    => $team_id,
                'placeholder' => $is_admin
                    ? __( 'All teams', 'talenttrack' )
                    : __( '— Select team —', 'talenttrack' ),
                'required'    => ! $is_admin && count( $coach_teams ) > 1,
            ] );

            echo PlayerSearchPickerComponent::render( [
                'name'     => 'player_id',
                'label'    => __( 'Player', 'talenttrack' ),
                'required' => true,
                'players'  => $players,
                'selected' => $player_id,
            ] );
            ?>
            <div class="tt-field" style="grid-column: 1 / -1;">
                <button type="submit" class="tt-btn tt-btn-primary"><?php esc_html_e( 'Show rate card', 'talenttrack' ); ?></button>
            </div>
        </form>
        <script>
        (function(){
            // F3 — when team changes, refresh the player picker; if the
            // user has only the coach scope, also auto-submit so the
            // server reloads the candidate roster from the DB. Admins
            // can stay client-side filtering.
            var form = document.querySelector('form');
            if (!form) return;
            var teamSel = form.querySelector('select[name="team_id"]');
            var playerWrap = form.querySelector('[data-tt-psp]');
            if (teamSel && playerWrap) {
                teamSel.addEventListener('change', function(){
                    var teamId = parseInt(teamSel.value, 10) || 0;
                    playerWrap.dispatchEvent(new CustomEvent('tt-psp:set-team', { detail: { team_id: teamId } }));
                });
            }
        })();
        </script>
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
