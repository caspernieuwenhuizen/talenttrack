<?php
namespace TT\Modules\Methodology\Frontend\Manage;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Shared\Frontend\Components\BackLink;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;
use TT\Shared\Frontend\Components\RecordLink;
use TT\Shared\Frontend\FrontendViewBase;

/**
 * MethodologyManageView (#2225) — the frontend authoring surface for the
 * methodology library. Reachable via
 * `?tt_view=methodology&mode=manage&mtab=<entity>`, co-located with the
 * read view (MethodologyView dispatches here when `mode=manage`).
 *
 * The view is a thin frame:
 *   - gates on `tt_edit_methodology`
 *   - emits the §5 nav (breadcrumb chain + auto tt_back pill) + a
 *     "View published methodology" link back to the read view
 *   - renders the tab bar from MethodologyManageRegistry (the extension
 *     point) and hands the body off to the selected tab's render callable
 *
 * All list / form / save logic lives in the per-entity tab classes and the
 * repositories (§4) — this frame decides nothing about a specific entity,
 * so sibling issues add their tab without touching this file.
 */
final class MethodologyManageView extends FrontendViewBase {

    public const CAP        = 'tt_edit_methodology';
    public const NONCE_ACTION = 'tt_methodology_manage';
    public const NONCE_FIELD  = '_tt_methodology_manage_nonce';

    public static function render(): void {
        $title = __( 'Manage methodology', 'talenttrack' );

        if ( ! current_user_can( self::CAP ) ) {
            FrontendBreadcrumbs::fromDashboard( __( 'Methodology', 'talenttrack' ) );
            self::renderHeader( $title );
            echo '<p class="tt-notice">' . esc_html__( 'You do not have permission to author the methodology library.', 'talenttrack' ) . '</p>';
            return;
        }

        $tabs = MethodologyManageRegistry::all();
        $requested = isset( $_GET['mtab'] ) ? sanitize_key( (string) $_GET['mtab'] ) : '';
        $active    = MethodologyManageRegistry::resolve( $requested );

        if ( $active === null ) {
            FrontendBreadcrumbs::fromDashboard( __( 'Methodology', 'talenttrack' ), [ self::readCrumb() ] );
            self::renderHeader( $title );
            echo '<p class="tt-notice">' . esc_html__( 'No methodology entities can be authored yet.', 'talenttrack' ) . '</p>';
            return;
        }

        $action = isset( $_GET['action'] ) ? sanitize_key( (string) $_GET['action'] ) : 'list';
        $id     = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;

        // Server-side POST handling for the active tab (create / edit /
        // delete forms). The tab owns the handler; the frame only verifies
        // the shared nonce and passes the flash back into the context.
        $flash = '';
        if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) === 'POST'
             && $active['handle'] !== null
             && isset( $_POST[ self::NONCE_FIELD ] )
             && wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION ) ) {
            $result = call_user_func( $active['handle'], $_POST );
            if ( is_array( $result ) ) {
                $flash = (string) ( $result['flash'] ?? '' );
                if ( ! empty( $result['back_to_list'] ) ) {
                    $action = 'list';
                    $id     = 0;
                }
            }
        }

        self::enqueueAssets();
        self::enqueueManageCss();

        FrontendBreadcrumbs::fromDashboard( $title, [ self::readCrumb() ] );
        self::renderHeader( $title, self::pageActionsHtml( [
            [
                'label'   => __( 'View published methodology', 'talenttrack' ),
                'href'    => add_query_arg( [ 'tt_view' => 'methodology' ], RecordLink::dashboardUrl() ),
                'variant' => 'secondary',
            ],
        ] ) );

        self::renderTabBar( $tabs, $active['key'] );

        if ( $flash !== '' ) {
            echo '<div class="tt-notice tt-notice-success">' . esc_html( $flash ) . '</div>';
        }

        call_user_func( $active['render'], [
            'action' => $action,
            'id'     => $id,
            'flash'  => $flash,
        ] );
    }

    /**
     * Build a URL into the manage surface for a given tab. Sibling tabs
     * use this to build their own list / edit / cancel links so the
     * `mode=manage` + `mtab` contract stays in one place.
     *
     * @param array<string,mixed> $extra_args
     */
    public static function tabUrl( string $mtab, array $extra_args = [] ): string {
        return add_query_arg(
            array_merge( [ 'tt_view' => 'methodology', 'mode' => 'manage', 'mtab' => $mtab ], $extra_args ),
            RecordLink::dashboardUrl()
        );
    }

    /**
     * The cancel target for a tab's form: back to the tab's own list,
     * unless a tt_back hint was captured (§6 — tt_back overrides).
     */
    public static function cancelUrl( string $mtab ): string {
        $back = BackLink::resolve();
        if ( $back !== null ) return $back['url'];
        return self::tabUrl( $mtab );
    }

    /** @param array<string, array{key:string,label:string,render:callable,handle:?callable,order:int}> $tabs */
    private static function renderTabBar( array $tabs, string $active_key ): void {
        echo '<nav class="tt-mmg-tabs" aria-label="' . esc_attr__( 'Methodology entities', 'talenttrack' ) . '">';
        foreach ( $tabs as $tab ) {
            $is_active = $tab['key'] === $active_key;
            $cls = 'tt-mmg-tab' . ( $is_active ? ' is-active' : '' );
            echo '<a class="' . esc_attr( $cls ) . '" href="' . esc_url( self::tabUrl( $tab['key'] ) ) . '"'
                . ( $is_active ? ' aria-current="page"' : '' ) . '>'
                . esc_html( $tab['label'] ) . '</a>';
        }
        echo '</nav>';
    }

    /** @return array{label:string,url:string} */
    private static function readCrumb(): array {
        return FrontendBreadcrumbs::viewCrumb( 'methodology', __( 'Methodology', 'talenttrack' ) );
    }

    private static function enqueueManageCss(): void {
        wp_enqueue_style(
            'tt-frontend-methodology-manage',
            TT_PLUGIN_URL . 'assets/css/frontend-methodology-manage.css',
            [ 'tt-frontend-app-chrome' ],
            TT_VERSION
        );
    }
}
