<?php
namespace TT\Modules\Training\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Authorization\MatrixGate;
use TT\Modules\Training\Services\PlayerExposureReader;
use TT\Shared\Frontend\FrontendViewBase;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;

/**
 * FrontendTrainingCoverageView (#2500, epic #2493) — the head of
 * development's view of what the academy actually trains.
 *
 * `?tt_view=training-coverage`. Principle down the side, team across the
 * top, and in each cell how many trainings that team has spent on that
 * principle.
 *
 * ## What this is for
 *
 * A methodology is a claim about what an academy teaches. This is the
 * only screen that checks the claim against what happened. The finding
 * is almost always a gap: a principle every age group is supposed to
 * cover that one team has never trained, or that the whole academy has
 * quietly stopped doing since November.
 *
 * So the design commits to gaps being the subject. Only "never" is
 * coloured; every other cell is a plain number. A five-bucket heat map
 * would look more analytical and would bury the one thing worth acting
 * on under four shades of nearly-fine.
 *
 * ## The second table is the point of the first
 *
 * Underneath: players whose own open goal sits on a principle their team
 * barely trains. That is the actionable end of the matrix — not "U13
 * is light on pressing" but "Sem has a goal on pressing and his team has
 * trained it once since August".
 *
 * ## Gating
 *
 * `training_exposure` at global scope, which per D16 is head of
 * development and academy admin. A coach holds it at team scope and so
 * cannot open this view — their version of this question is the training
 * tab on each of their players.
 */
final class FrontendTrainingCoverageView extends FrontendViewBase {

    public static function render( int $user_id, bool $is_admin ): void {
        // Global scope specifically, not `canAnyScope`: a coach holds
        // `training_exposure` at team scope and must not reach an
        // academy-wide matrix through it.
        if ( ! MatrixGate::can( $user_id, 'training_exposure', MatrixGate::READ, MatrixGate::SCOPE_GLOBAL ) ) {
            FrontendBreadcrumbs::fromDashboard( __( 'Not authorized', 'talenttrack' ) );
            echo '<p class="tt-notice">'
                . esc_html__( 'Training coverage is an academy-wide view. Your access to training history is limited to your own teams — open a player to see theirs.', 'talenttrack' )
                . '</p>';
            return;
        }

        FrontendBreadcrumbs::fromDashboard(
            __( 'Training coverage', 'talenttrack' ),
            [ FrontendBreadcrumbs::viewCrumb( 'training-plans', __( 'Training', 'talenttrack' ) ) ]
        );

        self::enqueueAssets();
        wp_enqueue_style(
            'tt-frontend-training-exposure',
            TT_PLUGIN_URL . 'assets/css/frontend-training-exposure.css',
            [],
            TT_VERSION
        );

        self::renderHeader( __( 'Training coverage', 'talenttrack' ) );

        echo '<p class="tt-muted">'
            . esc_html__( 'How many trainings each team has spent on each principle of the methodology. A principle a team has never trained is marked — that is the column worth reading.', 'talenttrack' )
            . '</p>';

        $reader     = new PlayerExposureReader();
        $principles = $reader->principles();
        $coverage   = $reader->coverageByTeam();

        if ( $principles === [] ) {
            echo '<p class="tt-notice">'
                . esc_html__( 'No methodology principles are set up yet, so there is nothing to measure training against.', 'talenttrack' )
                . '</p>';
            return;
        }

        self::renderMatrix( $principles, $coverage );
    }

    /**
     * @param list<array<string,mixed>> $principles
     * @param list<array<string,mixed>> $coverage
     */
    private static function renderMatrix( array $principles, array $coverage ): void {
        // Index the coverage by team and principle, and collect the teams
        // that actually ran a training — a team with no runs at all is a
        // different finding from a team with gaps, and belongs in its own
        // sentence rather than as a column of zeroes.
        $teams = [];
        $cells = [];
        foreach ( $coverage as $row ) {
            $team_id = (int) $row['team_id'];
            $teams[ $team_id ] = (string) $row['team_name'];
            $cells[ $team_id ][ (int) $row['principle_id'] ] = (int) $row['sessions_count'];
        }

        if ( $teams === [] ) {
            echo '<p class="tt-notice">'
                . esc_html__( 'No training has been run from a plan yet, so there is nothing to report. Attach a plan to a training and finish it, and this fills in.', 'talenttrack' )
                . '</p>';
            return;
        }

        asort( $teams );

        echo '<div class="tt-coverage__scroll">';
        echo '<table class="tt-coverage__table">';

        echo '<thead><tr>';
        echo '<th scope="col">' . esc_html__( 'Principle', 'talenttrack' ) . '</th>';
        foreach ( $teams as $team_name ) {
            echo '<th scope="col">' . esc_html( $team_name ) . '</th>';
        }
        echo '</tr></thead>';

        echo '<tbody>';
        foreach ( $principles as $principle ) {
            $principle_id = (int) $principle['id'];

            echo '<tr>';
            echo '<th scope="row">'
                . '<span class="tt-exposure__code">' . esc_html( (string) $principle['code'] ) . '</span> '
                . esc_html( (string) $principle['title'] )
                . '</th>';

            foreach ( array_keys( $teams ) as $team_id ) {
                $count = (int) ( $cells[ $team_id ][ $principle_id ] ?? 0 );
                $never = $count === 0;

                echo '<td class="tt-coverage__cell' . ( $never ? ' is-never' : '' ) . '">';
                echo $never
                    ? esc_html__( 'never', 'talenttrack' )
                    : esc_html( (string) $count );
                echo '</td>';
            }

            echo '</tr>';
        }
        echo '</tbody></table></div>';

        echo '<p class="tt-coverage__legend tt-small">'
            . esc_html__( 'Each number is how many completed trainings included an exercise that trains that principle. Skipped blocks do not count, and editing a plan afterwards never changes what a training already recorded.', 'talenttrack' )
            . '</p>';
    }
}
