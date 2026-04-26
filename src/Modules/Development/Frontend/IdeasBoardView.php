<?php
namespace TT\Modules\Development\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Development\IdeaRepository;
use TT\Modules\Development\IdeaStatus;
use TT\Modules\Development\IdeaType;
use TT\Shared\Frontend\FrontendBackButton;
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

    public static function render(): void {
        if ( ! current_user_can( 'tt_view_dev_board' ) ) {
            FrontendBackButton::render();
            echo '<p class="tt-notice">' . esc_html__( 'You do not have access to the development board.', 'talenttrack' ) . '</p>';
            return;
        }

        self::enqueueAssets();
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
        <p style="color:#666;">
            <?php esc_html_e( 'Submitted ideas flow left-to-right. Drop a card into Ready for approval to send it to the lead developer; rejections and promotions happen on the Approval queue.', 'talenttrack' ); ?>
        </p>
        <p style="margin:0 0 18px;">
            <a class="tt-btn" href="<?php echo esc_url( $approval_url ); ?>"><?php esc_html_e( 'Open approval queue', 'talenttrack' ); ?></a>
            <a class="tt-btn tt-btn-secondary" href="<?php echo esc_url( $tracks_url ); ?>"><?php esc_html_e( 'Manage tracks', 'talenttrack' ); ?></a>
        </p>

        <style>
        .tt-kanban {
            display: grid;
            grid-template-columns: repeat(<?php echo count( IdeaStatus::boardColumns() ); ?>, minmax(220px, 1fr));
            gap: 12px;
            overflow-x: auto;
        }
        .tt-kanban-col {
            background: #f4f5f7;
            border-radius: 8px;
            padding: 10px;
            min-height: 160px;
        }
        .tt-kanban-col h4 {
            margin: 0 0 10px;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #444;
        }
        .tt-kanban-card {
            background: #fff;
            border: 1px solid #e5e7ea;
            border-radius: 6px;
            padding: 10px;
            margin-bottom: 8px;
            font-size: 13px;
        }
        .tt-kanban-card strong { display: block; margin-bottom: 4px; }
        .tt-kanban-card .tt-card-meta { color: #777; font-size: 11px; }
        .tt-kanban-card form { margin: 6px 0 0; }
        .tt-kanban-card select, .tt-kanban-card button { font-size: 11px; padding: 2px 4px; }
        </style>

        <div class="tt-kanban">
            <?php foreach ( IdeaStatus::boardColumns() as $col ) : ?>
                <div class="tt-kanban-col">
                    <h4><?php echo esc_html( IdeaStatus::label( $col ) ); ?> (<?php echo (int) count( $by_status[ $col ] ); ?>)</h4>
                    <?php foreach ( $by_status[ $col ] as $row ) : ?>
                        <div class="tt-kanban-card">
                            <strong><?php echo esc_html( (string) $row->title ); ?></strong>
                            <div class="tt-card-meta">
                                <?php echo esc_html( IdeaType::label( (string) $row->type ) ); ?>
                                · <?php echo esc_html( self::authorName( (int) $row->author_user_id ) ); ?>
                                · <?php echo esc_html( (string) $row->created_at ); ?>
                            </div>
                            <p style="margin:6px 0 0;">
                                <a href="<?php echo esc_url( add_query_arg( [ 'tt_view' => 'ideas-refine', 'id' => (int) $row->id ], $refine_base ) ); ?>">
                                    <?php esc_html_e( 'Refine', 'talenttrack' ); ?> →
                                </a>
                            </p>
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
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'tt_dev_idea_refine' ); ?>
            <input type="hidden" name="action" value="tt_dev_idea_refine" />
            <input type="hidden" name="id" value="<?php echo (int) $id; ?>" />
            <input type="hidden" name="_redirect" value="<?php echo esc_attr( self::baseUrl( 'ideas-board' ) ); ?>" />
            <select name="status">
                <?php foreach ( $allowed as $s ) : ?>
                    <option value="<?php echo esc_attr( $s ); ?>" <?php selected( $s, $current ); ?>>
                        <?php echo esc_html( IdeaStatus::label( $s ) ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit"><?php esc_html_e( 'Move', 'talenttrack' ); ?></button>
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
