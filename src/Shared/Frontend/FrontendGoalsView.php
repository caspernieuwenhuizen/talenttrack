<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;

/**
 * FrontendGoalsView — the "Goals" tile destination (coaching group).
 *
 * v3.0.0 slice 4. Goal creation form + current-goals table with
 * inline status editing and delete.
 */
class FrontendGoalsView extends FrontendViewBase {

    public static function render( int $user_id, bool $is_admin ): void {
        self::enqueueAssets();
        self::renderHeader( __( 'Goals', 'talenttrack' ) );

        $teams = $is_admin ? QueryHelpers::get_teams() : QueryHelpers::get_teams_for_coach( $user_id );
        CoachForms::renderGoalsForm( $teams, $is_admin );
    }
}
