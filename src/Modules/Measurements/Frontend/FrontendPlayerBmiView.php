<?php
namespace TT\Modules\Measurements\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Authorization\MatrixGate;
use TT\Modules\Measurements\Growth\BmiSeriesBuilder;
use TT\Modules\Measurements\Reports\BmiQuery;
use TT\Modules\Measurements\Repositories\MeasurementDefinitionsRepository;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;
use TT\Shared\Frontend\Components\RecordLink;
use TT\Shared\Frontend\FrontendViewBase;

/**
 * FrontendPlayerBmiView (#2895) — BMI-for-age, roster and per player.
 *
 * Two of the three surfaces decision 3 called for; the third is the block on
 * the player's Measurements tab. All three render through {@see BmiBlock} and
 * read {@see BmiQuery}, so the figures cannot drift apart.
 *
 * The report deliberately refuses to grade anyone. It shows where a player
 * sits on a published growth curve and how that has moved, and stops there —
 * see BmiBlock's docblock for why. These are minors, and a screen that labels
 * a child "overweight" in front of whoever is standing behind the laptop is
 * not a screen this product ships.
 *
 * Slug: `player-bmi`.
 */
final class FrontendPlayerBmiView extends FrontendViewBase {

    public static function render( int $user_id, bool $is_admin ): void {
        $title = __( 'Player · BMI-for-age', 'talenttrack' );
        FrontendBreadcrumbs::fromDashboard(
            $title,
            [ FrontendBreadcrumbs::viewCrumb( 'reports', __( 'Reports', 'talenttrack' ) ) ]
        );

        if ( ! $is_admin && ! MatrixGate::canAnyScope( $user_id, 'measurements', 'read' ) ) {
            self::renderHeader( $title );
            echo '<p class="tt-notice">' . esc_html__( 'You do not have permission to browse test results.', 'talenttrack' ) . '</p>';
            return;
        }
        if ( ! \TT\Core\FeatureRegistry::isEnabled( 'report_player_bmi' ) ) {
            self::renderHeader( $title );
            echo '<p class="tt-notice">' . esc_html__( 'This report has been switched off for your academy.', 'talenttrack' ) . '</p>';
            return;
        }

        self::enqueueAssets();
        wp_enqueue_style(
            'tt-frontend-bmi',
            TT_PLUGIN_URL . 'assets/css/frontend-bmi.css',
            [ 'tt-frontend-app-chrome' ],
            TT_VERSION
        );

        self::renderHeader( $title );

        // The report needs a height test and a weight test to exist at all.
        // Saying so plainly beats rendering an empty grid (#2357).
        if ( ! self::hasHeightAndWeight() ) {
            echo '<p class="tt-notice">'
                . esc_html__( 'This report needs a height test and a weight test in your test catalogue. Add them under Manage tests, record some results, and the curve will fill in.', 'talenttrack' )
                . '</p>';
            return;
        }

        $see_all = $is_admin || MatrixGate::can( $user_id, 'measurements', 'read', 'global' );
        $teams   = QueryHelpers::get_teams_in_scope( $user_id, $see_all );

        if ( $teams === [] ) {
            echo '<p class="tt-notice">' . esc_html__( 'No teams are in your scope yet.', 'talenttrack' ) . '</p>';
            return;
        }

        $team_id = isset( $_GET['team_id'] ) ? absint( $_GET['team_id'] ) : 0;
        $allowed = array_map( static fn ( $t ) => (int) $t->id, $teams );
        if ( $team_id > 0 && ! in_array( $team_id, $allowed, true ) ) {
            $team_id = 0;
        }

        $player_id = isset( $_GET['player_id'] ) ? absint( $_GET['player_id'] ) : 0;

        $query = new BmiQuery();

        self::renderTeamFilter( $teams, $team_id, $player_id );
        BmiBlock::renderCaveat( $query );

        if ( $player_id > 0 ) {
            self::renderPlayerTrend( $query, $player_id, $team_id );
            return;
        }

        $rows = $query->rosterRows( $team_id > 0 ? [ $team_id ] : $allowed );
        self::renderRoster( $query, $rows, $team_id );
    }

    /**
     * The roster table — every player in scope, one row each.
     *
     * @param list<array<string,mixed>> $rows
     */
    private static function renderRoster( BmiQuery $query, array $rows, int $team_id ): void {
        if ( $rows === [] ) {
            echo '<p class="tt-notice">' . esc_html__( 'No players in this selection.', 'talenttrack' ) . '</p>';
            return;
        }

        $with_data = 0;
        foreach ( $rows as $r ) {
            if ( $r['bmi'] !== null ) $with_data++;
        }

        echo '<p class="tt-bmi-coverage">';
        printf(
            /* translators: 1: number of players with a BMI, 2: total players. */
            esc_html__( '%1$d of %2$d players have a usable height and weight pair.', 'talenttrack' ),
            (int) $with_data,
            count( $rows )
        );
        echo '</p>';

        echo '<div class="tt-table-scroll">';
        echo '<table class="tt-table tt-bmi-table">';
        echo '<thead><tr>';
        echo '<th scope="col">' . esc_html__( 'Player', 'talenttrack' ) . '</th>';
        echo '<th scope="col">' . esc_html__( 'Team', 'talenttrack' ) . '</th>';
        echo '<th scope="col">' . esc_html__( 'BMI', 'talenttrack' ) . '</th>';
        echo '<th scope="col">' . esc_html__( 'Percentile', 'talenttrack' ) . '</th>';
        echo '<th scope="col">' . esc_html__( 'Change', 'talenttrack' ) . '</th>';
        echo '<th scope="col">' . esc_html__( 'Measured', 'talenttrack' ) . '</th>';
        echo '</tr></thead><tbody>';

        foreach ( $rows as $row ) {
            $name = trim( (string) $row['first_name'] . ' ' . (string) $row['last_name'] );

            echo '<tr>';

            $player_url = RecordLink::detailUrlForWithBack( 'players', (int) $row['player_id'] );
            echo '<td>';
            if ( $player_url !== '' ) {
                printf( '<a href="%s">%s</a>', esc_url( $player_url ), esc_html( $name ) );
            } else {
                echo esc_html( $name );
            }
            echo '</td>';
            echo '<td>' . esc_html( (string) $row['team_name'] ) . '</td>';

            if ( $row['bmi'] === null ) {
                // One empty cell spanning the figures, with the reason, rather
                // than four dashes that look like zeroes.
                echo '<td colspan="4" class="tt-bmi-cell-empty">'
                    . esc_html__( 'No height and weight recorded close enough together', 'talenttrack' )
                    . '</td>';
                echo '</tr>';
                continue;
            }

            echo '<td class="tt-bmi-cell-num">' . esc_html( number_format_i18n( (float) $row['bmi'], 1 ) ) . '</td>';

            echo '<td class="tt-bmi-cell-num">';
            if ( ! empty( $row['covered'] ) && $row['percentile'] !== null ) {
                echo esc_html( BmiBlock::ordinal( (float) $row['percentile'] ) )
                    . ' <span class="tt-bmi-sds">' . esc_html( BmiBlock::signed( (float) $row['sds'] ) ) . '</span>';
            } else {
                echo '<span class="tt-bmi-cell-empty">' . esc_html__( 'Not covered by the reference', 'talenttrack' ) . '</span>';
            }
            echo '</td>';

            echo '<td class="tt-bmi-cell-num">';
            echo $row['delta_sds'] === null
                ? '<span class="tt-bmi-cell-empty">' . esc_html__( 'First measurement', 'talenttrack' ) . '</span>'
                : esc_html( BmiBlock::signed( (float) $row['delta_sds'] ) ) . ' ' . esc_html__( 'SDS', 'talenttrack' );
            echo '</td>';

            echo '<td>' . esc_html( BmiBlock::provenance( $row ) ) . '</td>';

            echo '</tr>';
        }

        echo '</tbody></table></div>';
    }

    /** One player's series over time. */
    private static function renderPlayerTrend( BmiQuery $query, int $player_id, int $team_id ): void {
        $series = $query->playerSeries( $player_id );

        if ( $series === [] ) {
            echo '<p class="tt-notice">'
                . esc_html__( 'This player has no BMI points yet — it needs a height and a weight recorded close together.', 'talenttrack' )
                . '</p>';
            return;
        }

        echo '<div class="tt-table-scroll">';
        echo '<table class="tt-table tt-bmi-table">';
        echo '<thead><tr>';
        echo '<th scope="col">' . esc_html__( 'Date', 'talenttrack' ) . '</th>';
        echo '<th scope="col">' . esc_html__( 'Height', 'talenttrack' ) . '</th>';
        echo '<th scope="col">' . esc_html__( 'Weight', 'talenttrack' ) . '</th>';
        echo '<th scope="col">' . esc_html__( 'BMI', 'talenttrack' ) . '</th>';
        echo '<th scope="col">' . esc_html__( 'Percentile', 'talenttrack' ) . '</th>';
        echo '<th scope="col">' . esc_html__( 'Gap', 'talenttrack' ) . '</th>';
        echo '</tr></thead><tbody>';

        foreach ( array_reverse( $series ) as $point ) {
            echo '<tr>';
            echo '<td>' . esc_html( (string) $point['date'] ) . '</td>';
            echo '<td class="tt-bmi-cell-num">' . esc_html( number_format_i18n( (float) $point['height_cm'], 1 ) ) . ' cm</td>';
            echo '<td class="tt-bmi-cell-num">' . esc_html( number_format_i18n( (float) $point['weight_kg'], 1 ) ) . ' kg</td>';
            echo '<td class="tt-bmi-cell-num">' . esc_html( number_format_i18n( (float) $point['bmi'], 1 ) ) . '</td>';

            echo '<td class="tt-bmi-cell-num">';
            echo $point['percentile'] === null
                ? '<span class="tt-bmi-cell-empty">' . esc_html__( 'Not covered', 'talenttrack' ) . '</span>'
                : esc_html( BmiBlock::ordinal( (float) $point['percentile'] ) )
                  . ' <span class="tt-bmi-sds">' . esc_html( BmiBlock::signed( (float) $point['sds'] ) ) . '</span>';
            echo '</td>';

            $gap = (int) $point['gap_days'];
            echo '<td class="tt-bmi-cell-num">' . esc_html(
                $gap === 0
                    ? __( 'same day', 'talenttrack' )
                    : sprintf(
                        /* translators: %d = number of days between the height and weight readings. */
                        _n( '%d day', '%d days', $gap, 'talenttrack' ),
                        $gap
                    )
            ) . '</td>';

            echo '</tr>';
        }

        echo '</tbody></table></div>';
    }

    /**
     * @param list<object> $teams
     */
    private static function renderTeamFilter( array $teams, int $team_id, int $player_id ): void {
        echo '<form method="get" class="tt-filterbar">';
        echo '<input type="hidden" name="tt_view" value="player-bmi" />';
        if ( $player_id > 0 ) {
            echo '<input type="hidden" name="player_id" value="' . esc_attr( (string) $player_id ) . '" />';
        }
        echo '<label class="tt-field"><span class="tt-field-label">' . esc_html__( 'Team', 'talenttrack' ) . '</span>';
        echo '<select name="team_id" class="tt-input">';
        echo '<option value="0">' . esc_html__( 'All teams in scope', 'talenttrack' ) . '</option>';
        foreach ( $teams as $t ) {
            printf(
                '<option value="%d"%s>%s</option>',
                (int) $t->id,
                selected( $team_id, (int) $t->id, false ),
                esc_html( (string) ( $t->name ?? '' ) )
            );
        }
        echo '</select></label>';
        echo '<button type="submit" class="tt-btn tt-btn-secondary">' . esc_html__( 'Apply', 'talenttrack' ) . '</button>';
        echo '</form>';
    }

    /**
     * Does the academy have both a height and a weight test configured?
     *
     * Which definitions those are is per-academy configuration, not a
     * hard-coded id, so it resolves through the definitions repository.
     */
    private static function hasHeightAndWeight(): bool {
        $definitions = ( new MeasurementDefinitionsRepository() )->listAll();
        $has_height  = false;
        $has_weight  = false;

        foreach ( (array) $definitions as $d ) {
            $name = strtolower( trim( (string) ( $d->name ?? '' ) ) );
            if ( in_array( $name, BmiSeriesBuilder::HEIGHT_NAMES, true ) ) $has_height = true;
            if ( in_array( $name, BmiSeriesBuilder::WEIGHT_NAMES, true ) ) $has_weight = true;
        }

        return $has_height && $has_weight;
    }
}
