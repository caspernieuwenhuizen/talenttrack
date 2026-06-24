<?php
namespace TT\Modules\Players\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Shared\Frontend\FrontendViewBase;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;
use TT\Shared\Frontend\Components\RecordLink;
use TT\Shared\Frontend\Components\BackLink;
use TT\Infrastructure\Security\AuthorizationService;

/**
 * FrontendAccountsHubView (#1815) — `?tt_view=accounts`.
 *
 * "Accounts & access" landing that groups the academy's account-management
 * surfaces: Player accounts, Parent accounts, and Invitations. Each tile is
 * cap-gated and links to its dedicated view, so the hub is a thin, discover-
 * able entry point — no business logic (CLAUDE.md §4).
 */
final class FrontendAccountsHubView extends FrontendViewBase {

    public static function render( int $user_id, bool $is_admin ): void {
        $title = __( 'Accounts & access', 'talenttrack' );

        $tiles = self::tilesFor( $user_id, $is_admin );

        if ( empty( $tiles ) ) {
            FrontendBreadcrumbs::fromDashboard( __( 'Not authorized', 'talenttrack' ) );
            self::renderHeader( $title );
            echo '<p class="tt-notice">' . esc_html__( 'You do not have permission to manage accounts.', 'talenttrack' ) . '</p>';
            return;
        }

        self::enqueueAssets();
        wp_enqueue_style(
            'tt-accounts-hub',
            TT_PLUGIN_URL . 'assets/css/components/accounts-hub.css',
            [ 'tt-frontend-app-chrome' ],
            TT_VERSION
        );

        FrontendBreadcrumbs::fromDashboard( $title );
        self::renderHeader( $title );

        echo '<p class="tt-acc-intro">' . esc_html__( 'Manage who can sign in to TalentTrack and how their login maps to a player, parent or staff record.', 'talenttrack' ) . '</p>';

        echo '<ul class="tt-acc-grid">';
        foreach ( $tiles as $tile ) {
            self::renderTile( $tile );
        }
        echo '</ul>';
    }

    /**
     * Cap-gated tile definitions, in display order.
     *
     * @return list<array{slug:string,title:string,desc:string}>
     */
    private static function tilesFor( int $user_id, bool $is_admin ): array {
        $tiles = [];

        if ( $is_admin || AuthorizationService::userCanOrMatrix( $user_id, 'tt_manage_players' ) ) {
            $tiles[] = [
                'slug'  => 'player-accounts',
                'title' => __( 'Player accounts', 'talenttrack' ),
                'desc'  => __( 'Link or unlink a WordPress login to each player record.', 'talenttrack' ),
            ];
        }
        if ( $is_admin || AuthorizationService::userCanOrMatrix( $user_id, 'tt_manage_parent_accounts' ) ) {
            $tiles[] = [
                'slug'  => 'parent-accounts',
                'title' => __( 'Parent accounts', 'talenttrack' ),
                'desc'  => __( 'Link or unlink guardian logins to the players they follow.', 'talenttrack' ),
            ];
        }
        if ( $is_admin || AuthorizationService::userCanOrMatrix( $user_id, 'tt_send_invitation' ) ) {
            $tiles[] = [
                'slug'  => 'invitations-config',
                'title' => __( 'Invitations', 'talenttrack' ),
                'desc'  => __( 'Send and track player, parent and staff invitation links.', 'talenttrack' ),
            ];
        }

        return $tiles;
    }

    /** @param array{slug:string,title:string,desc:string} $tile */
    private static function renderTile( array $tile ): void {
        $url = add_query_arg( [ 'tt_view' => $tile['slug'] ], RecordLink::dashboardUrl() );
        // Carry a back-hint so each destination renders the "← Back to
        // Accounts & access" pill (CLAUDE.md §5).
        $url = BackLink::appendTo( $url );
        echo '<li class="tt-acc-tile-wrap">';
        echo '<a class="tt-acc-tile" href="' . esc_url( $url ) . '">';
        echo '<span class="tt-acc-tile-title">' . esc_html( $tile['title'] ) . '</span>';
        echo '<span class="tt-acc-tile-desc">' . esc_html( $tile['desc'] ) . '</span>';
        echo '</a></li>';
    }
}
