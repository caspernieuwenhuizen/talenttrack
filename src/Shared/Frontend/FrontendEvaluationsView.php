<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;

/**
 * FrontendEvaluationsView — the "Evaluations" tile destination (coaching group).
 *
 * v3.0.0 slice 4. Wraps CoachForms::renderEvalForm with the v3
 * page-header + back-button treatment. The form action + AJAX
 * contract is unchanged from v2.x (tt_fe_save_evaluation).
 */
class FrontendEvaluationsView extends FrontendViewBase {

    public static function render( int $user_id, bool $is_admin ): void {
        self::enqueueAssets();
        self::renderHeader( __( 'Evaluations', 'talenttrack' ) );

        $teams = $is_admin ? QueryHelpers::get_teams() : QueryHelpers::get_teams_for_coach( $user_id );
        CoachForms::renderEvalForm( $teams, $is_admin );
    }
}
