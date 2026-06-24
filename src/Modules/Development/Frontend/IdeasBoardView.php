<?php
namespace TT\Modules\Development\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Development\IdeaRepository;
use TT\Modules\Development\IdeaStatus;
use TT\Modules\Development\IdeaType;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;
use TT\Shared\Frontend\FrontendViewBase;

/**
 * IdeasBoardView — kanban board for the dev-management staging.
 *
 * Six columns keyed off `IdeaStatus::boardColumns()`. Each card has a
 * "Refine" link that drops into IdeasRefineView for inline edit and a
 * status dropdown that posts to IdeaRefineHandler. Promotion + reject
 * happen from the Approval queue (IdeasApprovalView), reachable from
 * the "Ready for approval" column header.
 */
class IdeasBoardView extends FrontendViewBase {

    protected static function enqueueAssets(): void {
        parent::enqueueAssets();
        wp_enqueue_style(
            'tt-frontend-ideas',
            TT_PLUGIN_URL . 'assets/css/frontend-ideas.css',
            [ 'tt-frontend-app-chrome' ],
            TT_VERSION
        );
    }

    public static function render(): void {
        if ( ! current_user_can( 'tt_view_dev_board' ) ) {
            FrontendBreadcrumbs::fromDashboard( __( 'Not authorized', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'You do not have access to the development board.', 'talenttrack' ) . '</p>';
            return;
        }

        self::enqueueAssets();
        FrontendBreadcrumbs::fromDashboard( __( 'Development board', 'talenttrack' ) );
        self::renderHeader( __( 'Development board', 'talenttrack' ) );

        $repo = new IdeaRepository();
        $by_status = [];
        foreach ( IdeaStatus::boardColumns() as $col ) {
            $by_status[ $col ] = $repo->listByStatus( $col, 100 );
        }

        $approval_url = self::baseUrl( 'ideas-approval' );
        $tracks_url   = self::baseUrl( 'dev-tracks' );
        $refine_base  = self::baseUrl( 'ideas-refine' );
        ?>
        <p class="tt-ideas-lede">
            <?php esc_html_e( 'Submitted ideas flow left-to-right. Drop a card into Ready for approval to send it to the lead developer; rejections and promotions happen on the Approval queue.', 'talenttrack' ); ?>
        </p>
        <p class="tt-ideas-toolbar">
            <a class="tt-btn" href="<?php echo esc_url( $approval_url ); ?>"><?php esc_html_e( 'Open approval queue', 'talenttrack' ); ?></a>
            <a class="tt-btn tt-btn-secondary" href="<?php echo esc_url( $tracks_url ); ?>"><?php esc_html_e( 'Manage tracks', 'talenttrack' ); ?></a>
        </p>

        <div class="tt-kanban">
            <?php foreach ( IdeaStatus::boardColumns() as $col ) : ?>
                <div class="tt-kanban-col">
                    <h4 class="tt-kanban-col__head">
                        <?php echo esc_html( IdeaStatus::label( $col ) ); ?>
                        <span class="tt-kanban-col__count">(<?php echo (int) count( $by_status[ $col ] ); ?>)</span>
                    </h4>
                    <?php foreach ( $by_status[ $col ] as $row ) : ?>
                        <div class="tt-kanban-card">
                            <strong class="tt-kanban-card__title"><?php echo esc_html( (string) $row->title ); ?></strong>
                            <div class="tt-kanban-card__meta">
                                <?php echo esc_html( IdeaType::label( (string) $row->type ) ); ?>
                                · <?php echo esc_html( self::authorName( (int) $row->author_user_id ) ); ?>
                                · <?php echo esc_html( (string) $row->created_at ); ?>
                            </div>
                            <a class="tt-kanban-card__refine" href="<?php echo esc_url( add_query_arg( [ 'tt_view' => 'ideas-refine', 'id' => (int) $row->id ], $refine_base ) ); ?>">
                                <?php esc_html_e( 'Refine', 'talenttrack' ); ?> →
                            </a>
                            <?php self::renderQuickStatusForm( (int) $row->id, $col ); ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
    }

    private static function renderQuickStatusForm( int $id, string $current ): void {
        $allowed = [
            IdeaStatus::SUBMITTED,
            IdeaStatus::REFINING,
            IdeaStatus::READY_FOR_APPROVAL,
            IdeaStatus::IN_PROGRESS,
            IdeaStatus::DONE,
        ];
        ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="tt-kanban-card__move">
            <?php wp_nonce_field( 'tt_dev_idea_refine' ); ?>
            <input type="hidden" name="action" value="tt_dev_idea_refine" />
            <input type="hidden" name="id" value="<?php echo (int) $id; ?>" />
            <input type="hidden" name="_redirect" value="<?php echo esc_attr( self::baseUrl( 'ideas-board' ) ); ?>" />
            <label class="screen-reader-text" for="tt-kanban-move-<?php echo (int) $id; ?>"><?php esc_html_e( 'Move to status', 'talenttrack' ); ?></label>
            <select id="tt-kanban-move-<?php echo (int) $id; ?>" name="status">
                <?php foreach ( $allowed as $s ) : ?>
                    <option value="<?php echo esc_attr( $s ); ?>" <?php selected( $s, $current ); ?>>
                        <?php echo esc_html( IdeaStatus::label( $s ) ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="tt-btn tt-btn-secondary"><?php esc_html_e( 'Move', 'talenttrack' ); ?></button>
        </form>
        <?php
    }

    private static function authorName( int $userId ): string {
        $u = get_userdata( $userId );
        return $u ? (string) $u->display_name : __( 'Unknown', 'talenttrack' );
    }

    private static function baseUrl( string $view ): string {
        $current = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( (string) wp_unslash( $_SERVER['REQUEST_URI'] ) ) : home_url( '/' );
        $base    = remove_query_arg( [ 'tt_view', 'id', 'action' ], $current );
        return add_query_arg( 'tt_view', $view, $base );
    }
}
