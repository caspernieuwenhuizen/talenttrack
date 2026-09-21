<?php
namespace TT\Modules\Reports\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Reports\AudienceType;
use TT\Modules\Reports\PhotoInliner;
use TT\Modules\Reports\ReportConfig;
use TT\Modules\Reports\ScoutReportsRepository;
use TT\Shared\Frontend\FrontendViewBase;

/**
 * FrontendScoutMyPlayersView — scout-side list of assigned players.
 *
 * #0014 Sprint 5. Lists the players the HoD has explicitly assigned
 * to this scout (per `FrontendScoutAccessView::META_KEY` user-meta).
 * Clicking a player renders that player's scout-audience report
 * inline, persisting an audit row on each view.
 */
class FrontendScoutMyPlayersView extends FrontendViewBase {

    // #3566 — the META_KEY constant went with the inline decode it served.
    // The key now lives once, in ScoutPlayerLinks, which this class reads
    // through `assignedPlayerIds()` below.

    public static function render( int $user_id ): void {
        self::enqueueAssets();
        \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard( __( 'My scouted players', 'talenttrack' ) );
        self::renderHeader( __( 'My players', 'talenttrack' ) );

        // v3.85.5 — Scout access is Pro-tier per FeatureMap. #3104 — the
        // refusal renders through the shared panel.
        if ( class_exists( '\\TT\\Modules\\License\\LicenseGate' )
             && ! \TT\Modules\License\LicenseGate::allows( 'scout_access' )
        ) {
            echo \TT\Modules\License\UpgradePanel::render( 'scout_access' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — UpgradePanel returns escaped HTML
            return;
        }

        $assigned_ids = self::assignedPlayerIds( $user_id );
        if ( empty( $assigned_ids ) ) {
            echo '<p class="tt-notice">' . esc_html__( 'No players assigned to you yet. Reach out to the head of development if you expected access.', 'talenttrack' ) . '</p>';
            return;
        }

        $requested_id = isset( $_GET['player_id'] ) ? absint( $_GET['player_id'] ) : 0;
        if ( $requested_id > 0 && in_array( $requested_id, $assigned_ids, true ) ) {
            self::renderReport( $user_id, $requested_id );
            return;
        }
        if ( $requested_id > 0 ) {
            echo '<p class="tt-notice">' . esc_html__( 'You do not have access to this player.', 'talenttrack' ) . '</p>';
        }

        // List view.
        $chrome = \TT\Shared\Frontend\Components\FrontendAppChrome::class;
        ?>
        <p class="tt-sr-intro"><?php esc_html_e( 'Select a player to view the scout report.', 'talenttrack' ); ?></p>

        <div class="tt-sr-kpis" role="group" aria-label="<?php esc_attr_e( 'Assigned players summary', 'talenttrack' ); ?>">
            <?php
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — kpiTile escapes.
            echo $chrome::kpiTile( [ 'label' => __( 'Players assigned', 'talenttrack' ), 'value' => (string) count( $assigned_ids ) ] );
            ?>
        </div>

        <ul class="tt-smp-list">
            <?php foreach ( $assigned_ids as $pid ) :
                $player = QueryHelpers::get_player( $pid );
                if ( ! $player ) continue;
                $url  = add_query_arg( [ 'tt_view' => 'scout-my-players', 'player_id' => $pid ], remove_query_arg( [ 'player_id' ] ) );
                $name = QueryHelpers::player_display_name( $player );
                ?>
                <li class="tt-smp-item">
                    <a class="tt-smp-link" href="<?php echo esc_url( $url ); ?>">
                        <span class="tt-sr-avatar" aria-hidden="true"><?php echo esc_html( $chrome::initials( $name ) ); ?></span>
                        <span><?php echo esc_html( $name ); ?></span>
                        <span class="tt-smp-chevron" aria-hidden="true">&rarr;</span>
                    </a>
                    <?php
                    // #3807 — the thin card, for the comparison rather than
                    // the write-up. The report above is the scout-audience
                    // document; this is the four facts and the minutes
                    // share you want while standing next to a pitch.
                    ?>
                    <a class="tt-smp-card-link" href="<?php echo esc_url( \TT\Modules\Players\Frontend\FrontendScoutPlayerCardView::urlFor( $pid ) ); ?>"><?php esc_html_e( 'Player card', 'talenttrack' ); ?></a>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php
    }

    /**
     * #3566 — delegates to {@see ScoutPlayerLinks::assignedPlayerIds()}.
     *
     * This list is now an authorization input (a scout's `player` scope
     * resolves partly through it), so it gets one reader rather than a
     * copy per surface. Kept as a method here because existing callers
     * name this class.
     *
     * @return int[]
     */
    public static function assignedPlayerIds( int $scout_user_id ): array {
        return \TT\Infrastructure\Players\ScoutPlayerLinks::assignedPlayerIds( $scout_user_id );
    }

    private static function renderReport( int $scout_user_id, int $player_id ): void {
        $player = QueryHelpers::get_player( $player_id );
        if ( ! $player ) {
            echo '<p class="tt-notice">' . esc_html__( 'Player not found.', 'talenttrack' ) . '</p>';
            return;
        }

        // #3876 — the player report engine, composed for this reader. The
        // audience resolves from the scout, so the payload is the scout
        // allowlist whatever is asked for: scores without the coach's notes,
        // tests at the public level, nothing a scout may not receive.
        $window = \TT\Modules\Analytics\Reports\ReportFilters::seasonDefaultWindow();

        // The record of what was viewed, stored with the audit row.
        $config = new ReportConfig(
            AudienceType::SCOUT,
            [ 'date_from' => $window['from'], 'date_to' => $window['to'], 'eval_type_id' => 0 ],
            [ 'profile', 'ratings', 'attendance', 'sessions' ],
            $player_id,
            $scout_user_id
        );
        $report = ( new \TT\Modules\Analytics\Reports\PlayerReport() )->forPlayer( $player_id, $window['from'], $window['to'], [], $scout_user_id );
        if ( $report === null ) {
            echo '<p class="tt-notice">' . esc_html__( 'Player not found.', 'talenttrack' ) . '</p>';
            return;
        }

        \TT\Modules\Analytics\Frontend\PlayerReportPage::enqueuePublic();
        ob_start();
        echo '<div class="tt-mr tt-pr" data-tt-player-report>';
        \TT\Modules\Analytics\Frontend\PlayerReportPage::renderBlocks( $report, [ 'from' => $window['from'], 'to' => $window['to'], 'period' => '' ] );
        echo '</div>';
        $html = PhotoInliner::inline( (string) ob_get_clean() );

        // Persist an audit row per view (assigned-account audience).
        ( new ScoutReportsRepository() )->createAssignedAccountView(
            $player_id,
            $scout_user_id,
            $scout_user_id,
            $config,
            $html
        );

        $back_url = remove_query_arg( [ 'player_id' ] );
        ?>
        <a class="tt-smp-backlink" href="<?php echo esc_url( $back_url ); ?>"><span aria-hidden="true">&larr;</span> <?php esc_html_e( 'Back to my players', 'talenttrack' ); ?></a>
        <div class="tt-rwz-report-host">
            <?php echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — pre-escaped + photos inlined. ?>
        </div>
        <?php
    }
}
