<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;

/**
 * FrontendSessionsView — the "Sessions" tile destination (coaching group).
 *
 * v3.0.0 slice 4. Session recording form with attendance matrix for
 * all players on the coach's teams.
 */
class FrontendSessionsView extends FrontendViewBase {

    public static function render( int $user_id, bool $is_admin ): void {
        self::enqueueAssets();
        self::renderHeader( __( 'Sessions', 'talenttrack' ) );

        $teams = $is_admin ? QueryHelpers::get_teams() : QueryHelpers::get_teams_for_coach( $user_id );
        CoachForms::renderSessionForm( $teams );
    }
}
