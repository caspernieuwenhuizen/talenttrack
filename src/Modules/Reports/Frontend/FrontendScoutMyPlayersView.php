<?php
namespace TT\Modules\Reports\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Reports\AudienceDefaults;
use TT\Modules\Reports\AudienceType;
use TT\Modules\Reports\PhotoInliner;
use TT\Modules\Reports\PlayerReportRenderer;
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

    private const META_KEY = 'tt_scout_player_ids';

    public static function render( int $user_id ): void {
        self::enqueueAssets();
        \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard( __( 'My scouted players', 'talenttrack' ) );
        self::renderHeader( __( 'My players', 'talenttrack' ) );

        // v3.85.5 — Scout access is Pro-tier per FeatureMap.
        if ( class_exists( '\\TT\\Modules\\License\\LicenseGate' )
             && ! \TT\Modules\License\LicenseGate::allows( 'scout_access' )
        ) {
            echo \TT\Modules\License\Admin\UpgradeNudge::inline( __( 'Scout access', 'talenttrack' ), 'pro' );
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
                </li>
            <?php endforeach; ?>
        </ul>
        <?php
    }

    /**
     * @return int[]
     */
    public static function assignedPlayerIds( int $scout_user_id ): array {
        $raw = get_user_meta( $scout_user_id, self::META_KEY, true );
        if ( ! is_string( $raw ) || $raw === '' ) return [];
        $decoded = json_decode( $raw, true );
        if ( ! is_array( $decoded ) ) return [];
        $ids = array_map( 'intval', $decoded );
        return array_values( array_unique( array_filter( $ids, static fn( $i ) => $i > 0 ) ) );
    }

    private static function renderReport( int $scout_user_id, int $player_id ): void {
        $player = QueryHelpers::get_player( $player_id );
        if ( ! $player ) {
            echo '<p class="tt-notice">' . esc_html__( 'Player not found.', 'talenttrack' ) . '</p>';
            return;
        }

        // Build a scout-audience config and render.
        $defaults = AudienceDefaults::defaultsFor( AudienceType::SCOUT );
        $f        = AudienceDefaults::resolveScope( (string) $defaults['scope'] );
        $config   = new ReportConfig(
            AudienceType::SCOUT,
            [ 'date_from' => $f['date_from'], 'date_to' => $f['date_to'], 'eval_type_id' => 0 ],
            (array) $defaults['sections'],
            $defaults['privacy'],
            $player_id,
            $scout_user_id,
            null,
            (string) $defaults['tone_variant']
        );

        $renderer = new PlayerReportRenderer();
        $html     = $renderer->render( $config );
        $html     = PhotoInliner::inline( $html );

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
