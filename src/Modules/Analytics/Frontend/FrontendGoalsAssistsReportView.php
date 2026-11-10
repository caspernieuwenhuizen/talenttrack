<?php
namespace TT\Modules\Analytics\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\REST\GoalContributionsRestController;
use TT\Modules\Activities\Services\ActivityGridLink;
use TT\Modules\Analytics\Reports\GoalContributionDrilldown;
use TT\Modules\Analytics\Reports\GoalContributionQuery;
use TT\Modules\Authorization\AllTeamsScope;
use TT\Modules\Pdp\Repositories\SeasonsRepository;
use TT\Shared\Frontend\Components\BackLink;
use TT\Shared\Frontend\Components\CrossViewLink;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;
use TT\Shared\Frontend\Components\RecordLink;
use TT\Shared\Frontend\FrontendViewBase;

/**
 * FrontendGoalsAssistsReportView (#4994) — `?tt_view=goals-assists`, goals
 * and assists per team and season, and every match behind one player's count.
 *
 *   ?tt_view=goals-assists&team_id=N&season=S            the team table
 *   ?tt_view=goals-assists&team_id=N&from=…&to=…         the same, over a window
 *   …&player_id=P                                        one player's drill-down
 *
 * A thin view: the table reads `GoalContributionQuery::forTeam()` (the
 * Statistics tab's source), the drill-down reads
 * `GoalContributionDrilldown::payload()` (the REST route's answer), and
 * `GoalContributionsRenderer` draws both for screen and print. Read-only
 * (wizard plan: exemption, no record is created); staff who may edit a match
 * are linked to the existing minutes grid rather than offered an editor.
 */
final class FrontendGoalsAssistsReportView extends FrontendViewBase {

    public const SLUG = 'goals-assists';

    public const FEATURE = 'report_goals_assists';

    protected static function enqueueAssets(): void {
        parent::enqueueAssets();
        wp_enqueue_style( 'tt-stack-table', TT_PLUGIN_URL . 'assets/css/components/stack-table.css', [], TT_VERSION );
        wp_enqueue_style(
            'tt-goals-assists',
            TT_PLUGIN_URL . 'assets/css/frontend-goals-assists.css',
            [ 'tt-frontend-app-chrome', 'tt-stack-table' ],
            TT_VERSION
        );
    }

    public static function render( int $user_id, bool $is_admin ): void {
        self::enqueueAssets();
        $title   = __( 'Goals and assists', 'talenttrack' );
        $reports = FrontendBreadcrumbs::viewCrumb( 'reports', __( 'Reports', 'talenttrack' ) );

        if ( ! GoalContributionsRestController::can_view_teams() ) {
            FrontendBreadcrumbs::fromDashboard( __( 'Not authorized', 'talenttrack' ), [ $reports ] );
            echo '<p class="tt-notice">' . esc_html__( 'You do not have permission to view this report.', 'talenttrack' ) . '</p>';
            return;
        }
        if ( ! \TT\Core\FeatureRegistry::isEnabled( self::FEATURE ) ) {
            FrontendBreadcrumbs::fromDashboard( $title, [ $reports ] );
            echo '<p class="tt-notice">' . esc_html__( 'This report has been switched off for your academy.', 'talenttrack' ) . '</p>';
            return;
        }

        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only view state.
        $team_id   = isset( $_GET['team_id'] ) ? absint( $_GET['team_id'] ) : 0;
        $player_id = isset( $_GET['player_id'] ) ? absint( $_GET['player_id'] ) : 0;
        $season    = isset( $_GET['season'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['season'] ) ) : '';
        $from      = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['from'] ) ) : '';
        $to        = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['to'] ) ) : '';
        // phpcs:enable

        $window = GoalContributionQuery::resolveWindow( $season, $from, $to );
        $args   = array_filter( [
            'team_id' => $team_id > 0 ? $team_id : null,
            'season'  => $season !== '' ? $season : null,
            'from'    => $season === '' && $from !== '' ? $from : null,
            'to'      => $season === '' && $to !== '' ? $to : null,
        ] );
        $report_url = add_query_arg( array_merge( [ 'tt_view' => self::SLUG ], $args ), RecordLink::dashboardUrl() );

        if ( $team_id > 0 && $player_id > 0 ) {
            $refused = GoalContributionDrilldown::refusal( $user_id, $team_id, $player_id );
            $player  = $refused === null ? QueryHelpers::get_player( $player_id ) : null;
            $p       = $player ? (array) $player : [];
            $name    = $p !== [] ? trim( (string) ( $p['first_name'] ?? '' ) . ' ' . (string) ( $p['last_name'] ?? '' ) ) : '';
            if ( $name === '' ) $name = __( 'Player', 'talenttrack' );
            FrontendBreadcrumbs::fromDashboard( $name, [ $reports, [ 'label' => $title, 'url' => $report_url ] ] );
            self::renderHeader( $name );
            if ( $refused !== null ) {
                echo '<p class="tt-notice">' . esc_html( $refused['message'] ) . '</p>';
                return;
            }
            self::renderFilters( $user_id, $team_id, $player_id, $season, $window );
            if ( self::windowRefused( $window ) ) return;
            self::renderDrilldown( $team_id, $player_id, $window );
            return;
        }

        FrontendBreadcrumbs::fromDashboard( $title, [ $reports ] );
        self::renderHeader( $title );

        if ( $team_id > 0 && ! AllTeamsScope::canReadTeam( $user_id, $team_id ) ) {
            echo '<p class="tt-notice">' . esc_html__( 'That team is not in your scope.', 'talenttrack' ) . '</p>';
            return;
        }

        self::renderFilters( $user_id, $team_id, 0, $season, $window );
        if ( $team_id <= 0 ) {
            echo '<p class="tt-ga-empty">' . esc_html__( 'Choose a team to see its goals and assists.', 'talenttrack' ) . '</p>';
            return;
        }
        if ( self::windowRefused( $window ) ) return;

        self::renderTable( $team_id, $window, $args );
    }

    /** @param array{error:string} $window */
    private static function windowRefused( array $window ): bool {
        if ( $window['error'] === 'bad_filter' ) {
            echo '<p class="tt-notice">' . esc_html__( 'That season is not known.', 'talenttrack' ) . '</p>';
            return true;
        }
        if ( $window['error'] === 'bad_date' ) {
            echo '<p class="tt-notice">' . esc_html__( 'Dates must be written as YYYY-MM-DD, with from on or before to.', 'talenttrack' ) . '</p>';
            return true;
        }
        return false;
    }

    /**
     * Team and season, in the URL so they survive a reload. A window that
     * came from the Statistics tab (from / to) is kept and named.
     *
     * @param array{error:string, from:string, to:string, season_id:int, season:string, source:string} $window
     */
    private static function renderFilters( int $user_id, int $team_id, int $player_id, string $season, array $window ): void {
        $teams = QueryHelpers::user_has_global_entity_read( $user_id, 'team' )
            ? QueryHelpers::get_teams()
            : QueryHelpers::get_teams_for_coach( $user_id );

        echo '<form method="get" class="tt-ga-filters">';
        echo '<input type="hidden" name="tt_view" value="' . esc_attr( self::SLUG ) . '">';
        if ( $player_id > 0 ) {
            echo '<input type="hidden" name="player_id" value="' . esc_attr( (string) $player_id ) . '">';
        }

        echo '<label class="tt-field tt-ga-filters__field"><span class="tt-field-label">' . esc_html__( 'Team', 'talenttrack' ) . '</span>';
        echo '<select class="tt-input" name="team_id">';
        echo '<option value="">' . esc_html__( 'Choose a team', 'talenttrack' ) . '</option>';
        foreach ( $teams as $t ) {
            $id = (int) ( $t->id ?? 0 );
            echo '<option value="' . esc_attr( (string) $id ) . '"' . selected( $id, $team_id, false ) . '>' . esc_html( (string) ( $t->name ?? '' ) ) . '</option>';
        }
        echo '</select></label>';

        $custom = $window['source'] === 'custom';
        echo '<label class="tt-field tt-ga-filters__field"><span class="tt-field-label">' . esc_html__( 'Season', 'talenttrack' ) . '</span>';
        echo '<select class="tt-input" name="season">';
        if ( $custom ) {
            echo '<option value="" selected>' . esc_html( sprintf(
                /* translators: 1: window start date, 2: window end date */
                __( 'From %1$s to %2$s', 'talenttrack' ),
                \TT\Shared\Dates\TTDate::date( $window['from'] ),
                \TT\Shared\Dates\TTDate::date( $window['to'] )
            ) ) . '</option>';
        }
        foreach ( ( new SeasonsRepository() )->all() as $s ) {
            $sid = (int) ( $s->id ?? 0 );
            $on  = ! $custom && ( $season !== '' ? (string) $sid === $season : $sid === (int) $window['season_id'] );
            echo '<option value="' . esc_attr( (string) $sid ) . '"' . ( $on ? ' selected' : '' ) . '>' . esc_html( (string) ( $s->name ?? '' ) ) . '</option>';
        }
        echo '</select></label>';
        if ( $custom ) {
            echo '<input type="hidden" name="from" value="' . esc_attr( $window['from'] ) . '">';
            echo '<input type="hidden" name="to" value="' . esc_attr( $window['to'] ) . '">';
        }

        echo '<button type="submit" class="tt-btn tt-btn-secondary">' . esc_html__( 'Apply', 'talenttrack' ) . '</button>';
        echo '</form>';
    }

    /**
     * @param array{from:string, to:string, source:string} $window
     * @param array<string, mixed>                         $args
     */
    private static function renderTable( int $team_id, array $window, array $args ): void {
        $rows = GoalContributionDrilldown::teamRows( get_current_user_id(), $team_id, $window );
        $base = array_merge( [ 'tt_view' => self::SLUG ], $args );
        echo '<div class="tt-ga">';
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the renderer escapes.
        echo GoalContributionsRenderer::table( $rows, static function ( int $pid ) use ( $base ): string {
            return BackLink::appendTo( add_query_arg( array_merge( $base, [ 'player_id' => $pid ] ), RecordLink::dashboardUrl() ) );
        }, (string) $window['source'] );
        echo '</div>';
    }

    /** @param array{from:string, to:string, season_id:int, season:string, source:string} $window */
    private static function renderDrilldown( int $team_id, int $player_id, array $window ): void {
        $data    = GoalContributionDrilldown::payload( $team_id, $player_id, $window );
        $user_id = get_current_user_id();

        echo '<div class="tt-ga">';
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the renderer escapes.
        echo GoalContributionsRenderer::drilldown(
            $data,
            static fn( array $m ): string => CrossViewLink::allows( 'activities' ) ? RecordLink::detailUrlForWithBack( 'activities', (int) $m['activity_id'] ) : '',
            // The entry grids' one chokepoint: their toggle, the team, the
            // right to edit, and the date-narrowed URL. The row holds the
            // team and date already, so priming costs no read.
            static function ( array $m ) use ( $team_id, $user_id ): string {
                $activity_id = (int) $m['activity_id'];
                ActivityGridLink::primeAnchor( $activity_id, $team_id, (string) $m['date'] );
                if ( ! ActivityGridLink::canUseMinutes( $activity_id, $user_id ) ) return '';
                $url = ActivityGridLink::minutesUrl( $activity_id );
                return $url !== '' ? BackLink::appendTo( $url ) : '';
            }
        );
        echo '</div>';
    }
}
