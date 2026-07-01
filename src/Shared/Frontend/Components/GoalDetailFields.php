<?php
namespace TT\Shared\Frontend\Components;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * GoalDetailFields — shared read-only render of the three goal fields
 * that both goal-detail surfaces (coach `FrontendGoalsManageView` and
 * player `FrontendMyGoalsView`) must show but historically omitted:
 *
 *   1. Progress %      — as a bar (`.tt-goal-progress*`), or "—" when null.
 *   2. Linked principle — methodology principle name.
 *   3. Linked action    — football action name.
 *
 * Name resolution is delegated to the domain repositories
 * (`PrinciplesRepository::displayName` / `FootballActionsRepository::displayName`)
 * so views stay logic-free (CLAUDE.md § 4) and the coach + player
 * surfaces render identical answers.
 */
final class GoalDetailFields {

    /**
     * Echo the progress bar + principle + action fields for a goal row.
     *
     * @param object $goal A goal row exposing `progress_pct`,
     *                     `linked_principle_id`, `linked_action_id`.
     */
    public static function render( object $goal ): void {
        self::renderProgress( $goal );
        self::renderPrinciple( $goal );
        self::renderAction( $goal );
    }

    private static function renderProgress( object $goal ): void {
        $raw = $goal->progress_pct ?? null;

        echo '<div class="tt-goal-detail-field">';
        echo '<span class="tt-goal-detail-field__label">' . esc_html__( 'Progress', 'talenttrack' ) . '</span>';

        if ( $raw === null || $raw === '' ) {
            // Null progress renders as an em dash — never a fabricated 0%.
            echo '<div class="tt-goal-detail-field__value">&mdash;</div>';
            echo '</div>';
            return;
        }

        $pct  = max( 0, min( 100, (int) $raw ) );
        $done = $pct >= 100 ? ' tt-goal-progress-fill--done' : '';

        echo '<div class="tt-goal-progress">';
        echo '<div class="tt-goal-progress-head">';
        echo '<span>' . esc_html__( 'Progress', 'talenttrack' ) . '</span>';
        echo '<span class="tt-goal-progress-pct">' . esc_html( $pct . '%' ) . '</span>';
        echo '</div>';
        echo '<div class="tt-goal-progress-track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . esc_attr( (string) $pct ) . '">';
        echo '<div class="tt-goal-progress-fill' . esc_attr( $done ) . '" style="width:' . esc_attr( (string) $pct ) . '%;"></div>'; /* tt-inline-ok */
        echo '</div>';
        echo '</div>';

        echo '</div>';
    }

    private static function renderPrinciple( object $goal ): void {
        $id = (int) ( $goal->linked_principle_id ?? 0 );
        if ( $id <= 0 || ! class_exists( '\\TT\\Modules\\Methodology\\Repositories\\PrinciplesRepository' ) ) {
            return;
        }
        $name = ( new \TT\Modules\Methodology\Repositories\PrinciplesRepository() )->displayName( $id );
        if ( $name === '' ) {
            return;
        }
        echo '<div class="tt-goal-detail-field">';
        echo '<span class="tt-goal-detail-field__label">' . esc_html__( 'Connected principle', 'talenttrack' ) . '</span>';
        echo '<div class="tt-goal-detail-field__value">' . esc_html( $name ) . '</div>';
        echo '</div>';
    }

    private static function renderAction( object $goal ): void {
        $id = (int) ( $goal->linked_action_id ?? 0 );
        if ( $id <= 0 || ! class_exists( '\\TT\\Modules\\Methodology\\Repositories\\FootballActionsRepository' ) ) {
            return;
        }
        $name = ( new \TT\Modules\Methodology\Repositories\FootballActionsRepository() )->displayName( $id );
        if ( $name === '' ) {
            return;
        }
        echo '<div class="tt-goal-detail-field">';
        echo '<span class="tt-goal-detail-field__label">' . esc_html__( 'Connected football action', 'talenttrack' ) . '</span>';
        echo '<div class="tt-goal-detail-field__value">' . esc_html( $name ) . '</div>';
        echo '</div>';
    }
}
