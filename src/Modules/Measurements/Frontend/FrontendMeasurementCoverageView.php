<?php
namespace TT\Modules\Measurements\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Authorization\MatrixGate;
use TT\Modules\Measurements\Services\MeasurementCoverageService;
use TT\Modules\Measurements\Services\MeasurementScheduleService;
use TT\Shared\Frontend\FrontendViewBase;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;

/**
 * FrontendMeasurementCoverageView (#1882, insights slice) — "who's due /
 * overdue" for a team. Player-centric (§1): for each scheduled test it
 * shows how many of the team are up to date and names the players who
 * need testing. Composition only; the cadence + counts live in the
 * coverage service (§4). Slug: `measurements-coverage`.
 */
final class FrontendMeasurementCoverageView extends FrontendViewBase {

    public static function render( int $user_id, bool $is_admin ): void {
        $title = __( 'Testing coverage', 'talenttrack' );
        FrontendBreadcrumbs::fromDashboard( $title );

        if ( ! MatrixGate::canAnyScope( $user_id, 'measurement_sessions', 'read' ) && ! $is_admin ) {
            self::renderHeader( $title );
            echo '<p class="tt-notice">' . esc_html__( 'You do not have permission to view testing coverage.', 'talenttrack' ) . '</p>';
            return;
        }

        $see_all = $is_admin || MatrixGate::can( $user_id, 'measurement_sessions', 'read', 'global' );
        $teams   = $see_all ? QueryHelpers::get_teams() : QueryHelpers::get_teams_for_coach( $user_id );

        self::enqueueAssets();
        wp_enqueue_style(
            'tt-measurement-coverage',
            TT_PLUGIN_URL . 'assets/css/frontend-measurement-coverage.css',
            [ 'tt-frontend-app-chrome' ],
            TT_VERSION
        );
        self::renderHeader( $title );

        if ( empty( $teams ) ) {
            echo '<p class="tt-notice">' . esc_html__( 'No teams are available to you yet.', 'talenttrack' ) . '</p>';
            return;
        }

        $allowed_ids = array_map( static fn ( $t ) => (int) $t->id, (array) $teams );
        $team_id     = isset( $_GET['team_id'] ) ? absint( $_GET['team_id'] ) : 0;
        if ( $team_id > 0 && ! in_array( $team_id, $allowed_ids, true ) ) {
            echo '<p class="tt-notice">' . esc_html__( 'You do not have access to this team.', 'talenttrack' ) . '</p>';
            return;
        }

        self::renderPicker( $teams, $team_id );

        if ( $team_id <= 0 ) {
            echo '<p class="tt-mc-hint">' . esc_html__( 'Choose a team to see who is due or overdue for each test.', 'talenttrack' ) . '</p>';
            return;
        }

        $coverage = ( new MeasurementCoverageService() )->forTeam( $team_id );
        if ( empty( $coverage['definitions'] ) ) {
            echo '<p class="tt-notice">' . esc_html__( 'No scheduled tests, or no players on this team yet. Coverage only counts tests that have a recurrence.', 'talenttrack' ) . '</p>';
            return;
        }

        foreach ( $coverage['definitions'] as $def ) {
            self::renderDefinitionCard( $def );
        }
    }

    /**
     * @param array<int, object> $teams
     */
    private static function renderPicker( array $teams, int $team_id ): void {
        $base = remove_query_arg( [ 'team_id' ] );
        ?>
        <form method="get" class="tt-mc-picker">
            <?php foreach ( $_GET as $k => $v ) :
                if ( $k === 'team_id' ) continue;
                if ( ! is_scalar( $v ) ) continue;
                ?>
                <input type="hidden" name="<?php echo esc_attr( (string) $k ); ?>" value="<?php echo esc_attr( (string) $v ); ?>" />
            <?php endforeach; ?>
            <label class="tt-mc-picker__field">
                <span class="tt-mc-picker__label"><?php esc_html_e( 'Team', 'talenttrack' ); ?></span>
                <select name="team_id" class="tt-input" onchange="this.form.submit()">
                    <option value="0"><?php esc_html_e( '— Choose team —', 'talenttrack' ); ?></option>
                    <?php foreach ( $teams as $t ) : ?>
                        <option value="<?php echo (int) $t->id; ?>"<?php selected( $team_id, (int) $t->id ); ?>><?php echo esc_html( (string) $t->name ); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <noscript><button type="submit" class="tt-btn tt-btn-secondary"><?php esc_html_e( 'Show', 'talenttrack' ); ?></button></noscript>
        </form>
        <?php
    }

    /** @param array<string,mixed> $def */
    private static function renderDefinitionCard( array $def ): void {
        $total      = (int) $def['total'];
        $up         = (int) $def['up_to_date'];
        $needs      = is_array( $def['needs'] ?? null ) ? $def['needs'] : [];
        $pct        = $total > 0 ? (int) round( $up / $total * 100 ) : 0;
        $freq_label = self::frequencyLabel( (string) $def['frequency'] );

        echo '<section class="tt-mc-card">';
        echo '<div class="tt-mc-card__head">';
        echo '<h2 class="tt-mc-card__title">' . esc_html( (string) $def['name'] ) . '</h2>';
        if ( $freq_label !== '' ) {
            echo '<span class="tt-mc-card__freq">' . esc_html( $freq_label ) . '</span>';
        }
        echo '</div>';

        echo '<p class="tt-mc-card__summary">' . esc_html( sprintf(
            /* translators: 1: up-to-date count, 2: team size */
            __( '%1$d of %2$d up to date', 'talenttrack' ),
            $up, $total
        ) ) . '</p>';

        echo '<div class="tt-mc-bar"><i style="width:' . (int) $pct . '%"></i></div>'; /* tt-inline-ok */

        if ( empty( $needs ) ) {
            echo '<p class="tt-mc-card__clear">' . esc_html__( 'Everyone is up to date.', 'talenttrack' ) . '</p>';
        } else {
            echo '<ul class="tt-mc-needs">';
            foreach ( $needs as $n ) {
                $st = (string) ( $n['status'] ?? '' );
                echo '<li class="tt-mc-need">';
                echo '<span class="tt-mc-need__name">' . esc_html( (string) ( $n['name'] ?? '' ) ) . '</span>';
                echo '<span class="tt-mc-chip tt-mc-chip--' . esc_attr( $st ) . '">' . esc_html( self::statusLabel( $st ) ) . '</span>';
                echo '</li>';
            }
            echo '</ul>';
        }
        echo '</section>';
    }

    private static function frequencyLabel( string $freq ): string {
        switch ( $freq ) {
            case 'annual':    return __( 'Once a season', 'talenttrack' );
            case 'biannual':  return __( 'Twice a season', 'talenttrack' );
            case 'quarterly': return __( 'Four times a season', 'talenttrack' );
            case 'monthly':   return __( 'Monthly', 'talenttrack' );
            default:          return '';
        }
    }

    private static function statusLabel( string $status ): string {
        switch ( $status ) {
            case MeasurementScheduleService::OVERDUE:  return __( 'Overdue', 'talenttrack' );
            case MeasurementScheduleService::DUE_SOON: return __( 'Due soon', 'talenttrack' );
            case MeasurementScheduleService::NEVER:    return __( 'Never tested', 'talenttrack' );
            default:                                   return '';
        }
    }
}
