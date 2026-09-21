<?php
namespace TT\Modules\Reports\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Analytics\Frontend\PlayerFamilyReportsTab;
use TT\Modules\Analytics\Frontend\PlayerReportPage;
use TT\Modules\Analytics\Reports\PlayerReportAccess;
use TT\Modules\Analytics\Reports\PlayerReportSnapshots;
use TT\Shared\Frontend\Components\RecordLink;

/**
 * ReportWizardRedirect (#3955, epic #3871) — `?tt_view=report-wizard` after
 * the wizard was retired.
 *
 * Every document the wizard made is the player report's now: staff and scout
 * documents since #3876, the family's since #3955. A bookmark or an old link
 * still lands somewhere useful rather than on an unknown view:
 *
 * - staff who may read the player's report: the player report, for that player;
 * - the player, or a parent of the child: the Reports tab on the player's file,
 *   where what the coach shared with them is listed;
 * - anyone else, or a link without a player: the player report, which shows
 *   its own picker or its own refusal.
 *
 * Runs on `template_redirect`, before any output, and redirects temporarily:
 * where the link leads depends on who follows it.
 */
final class ReportWizardRedirect {

    public const RETIRED_SLUG = 'report-wizard';

    public static function init(): void {
        add_action( 'template_redirect', [ __CLASS__, 'maybeRedirect' ], 4 );
    }

    public static function maybeRedirect(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing.
        $view = isset( $_GET['tt_view'] ) ? sanitize_key( wp_unslash( (string) $_GET['tt_view'] ) ) : '';
        if ( $view !== self::RETIRED_SLUG ) return;

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing.
        $player_id = isset( $_GET['player_id'] ) ? absint( $_GET['player_id'] ) : 0;

        wp_safe_redirect( self::targetFor( get_current_user_id(), $player_id ), 302 );
        exit;
    }

    /** Where an old wizard link for this player leads this reader. */
    public static function targetFor( int $user_id, int $player_id ): string {
        $dashboard = RecordLink::dashboardUrl();

        if ( $player_id > 0
            && ! PlayerReportAccess::canRead( $user_id, $player_id )
            && PlayerReportSnapshots::isFamilyReader( $user_id, $player_id )
        ) {
            $own  = \TT\Infrastructure\Query\QueryHelpers::get_player_for_user( $user_id );
            $args = [ 'tt_view' => 'overview', 'tab' => PlayerFamilyReportsTab::TAB ]; /* tt-xview-ok */ // the family's own file, gated by canViewPlayer
            if ( ! $own || (int) ( $own->id ?? 0 ) !== $player_id ) {
                $args['player_id'] = $player_id;
            }
            return add_query_arg( $args, $dashboard );
        }

        $args = [ 'tt_view' => 'standard-report', 'slug' => PlayerReportPage::SLUG ]; /* tt-xview-ok */ // the report guards itself
        if ( $player_id > 0 ) $args['player_id'] = $player_id;
        return add_query_arg( $args, $dashboard );
    }
}
