<?php
namespace TT\Modules\Development\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Development\IdeaRepository;
use TT\Modules\Development\IdeaStatus;
use TT\Modules\Development\IdeaType;
use TT\Modules\Development\TrackRepository;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;
use TT\Shared\Frontend\FrontendViewBase;

/**
 * IdeasRefineView — admin form to edit a single staged idea.
 *
 * Cap-gated by `tt_refine_idea`. Lets admin edit title/body/slug/type
 * and optionally re-tag to a track / player / team. Status moves are
 * handled by the same form via the IdeaRefineHandler.
 */
class IdeasRefineView extends FrontendViewBase {

    public static function render(): void {
        $ideas_label = __( 'Ideas', 'talenttrack' );
        $ideas_crumb = [ FrontendBreadcrumbs::viewCrumb( 'ideas-board', $ideas_label ) ];

        if ( ! current_user_can( 'tt_refine_idea' ) ) {
            FrontendBreadcrumbs::fromDashboard( __( 'Not authorized', 'talenttrack' ), $ideas_crumb );
            echo '<p class="tt-notice">' . esc_html__( 'You do not have permission to refine ideas.', 'talenttrack' ) . '</p>';
            return;
        }

        $id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
        if ( $id <= 0 ) {
            FrontendBreadcrumbs::fromDashboard( __( 'Refine idea', 'talenttrack' ), $ideas_crumb );
            echo '<p>' . esc_html__( 'Idea id missing from URL.', 'talenttrack' ) . '</p>';
            return;
        }

        $repo = new IdeaRepository();
        $idea = $repo->find( $id );
        if ( ! $idea ) {
            FrontendBreadcrumbs::fromDashboard( __( 'Idea not found', 'talenttrack' ), $ideas_crumb );
            echo '<p>' . esc_html__( 'Idea not found.', 'talenttrack' ) . '</p>';
            return;
        }

        self::enqueueAssets();
        FrontendBreadcrumbs::fromDashboard( __( 'Refine idea', 'talenttrack' ), $ideas_crumb );
        self::renderHeader( __( 'Refine idea', 'talenttrack' ) );

        $tracks = ( new TrackRepository() )->listAll();
        $board_url = self::baseUrl( 'ideas-board' );
        ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:760px; padding:18px; background:#fff; border:1px solid #e5e7ea; border-radius:8px;">
            <?php wp_nonce_field( 'tt_dev_idea_refine' ); ?>
            <input type="hidden" name="action" value="tt_dev_idea_refine" />
            <input type="hidden" name="id" value="<?php echo (int) $idea->id; ?>" />
            <input type="hidden" name="_redirect" value="<?php echo esc_attr( $board_url ); ?>" />

            <p style="color:#777; margin:0 0 12px; font-size:12px;">
                <?php esc_html_e( 'Submitted by', 'talenttrack' ); ?>
                <strong><?php echo esc_html( self::authorName( (int) $idea->author_user_id ) ); ?></strong>
                · <?php echo esc_html( (string) $idea->created_at ); ?>
            </p>

            <p>
                <label style="display:block; font-weight:600;"><?php esc_html_e( 'Title', 'talenttrack' ); ?></label>
                <input type="text" name="title" value="<?php echo esc_attr( (string) $idea->title ); ?>" required maxlength="255" style="width:100%; padding:8px;" />
            </p>

            <p>
                <label style="display:block; font-weight:600;"><?php esc_html_e( 'Body', 'talenttrack' ); ?></label>
                <textarea name="body" rows="10" style="width:100%; padding:8px;"><?php echo esc_textarea( (string) ( $idea->body ?? '' ) ); ?></textarea>
            </p>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                <p>
                    <label style="display:block; font-weight:600;"><?php esc_html_e( 'Slug', 'talenttrack' ); ?></label>
                    <input type="text" name="slug" value="<?php echo esc_attr( (string) ( $idea->slug ?? '' ) ); ?>" maxlength="120" style="width:100%; padding:8px;" placeholder="<?php esc_attr_e( 'auto from title if empty', 'talenttrack' ); ?>" />
                </p>
                <p>
                    <label style="display:block; font-weight:600;"><?php esc_html_e( 'Type', 'talenttrack' ); ?></label>
                    <select name="type" style="width:100%; padding:8px;">
                        <?php foreach ( IdeaType::all() as $t ) : ?>
                            <option value="<?php echo esc_attr( $t ); ?>" <?php selected( $t, (string) $idea->type ); ?>>
                                <?php echo esc_html( IdeaType::label( $t ) ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </p>
            </div>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                <p>
                    <label style="display:block; font-weight:600;"><?php esc_html_e( 'Track (optional)', 'talenttrack' ); ?></label>
                    <select name="track_id" style="width:100%; padding:8px;">
                        <option value="0">— <?php esc_html_e( 'No track', 'talenttrack' ); ?> —</option>
                        <?php foreach ( $tracks as $tr ) : ?>
                            <option value="<?php echo (int) $tr->id; ?>" <?php selected( (int) $tr->id, (int) ( $idea->track_id ?? 0 ) ); ?>>
                                <?php echo esc_html( (string) $tr->name ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </p>
                <p>
                    <label style="display:block; font-weight:600;"><?php esc_html_e( 'Status', 'talenttrack' ); ?></label>
                    <select name="status" style="width:100%; padding:8px;">
                        <?php foreach ( [ IdeaStatus::SUBMITTED, IdeaStatus::REFINING, IdeaStatus::READY_FOR_APPROVAL, IdeaStatus::IN_PROGRESS, IdeaStatus::DONE ] as $s ) : ?>
                            <option value="<?php echo esc_attr( $s ); ?>" <?php selected( $s, (string) $idea->status ); ?>>
                                <?php echo esc_html( IdeaStatus::label( $s ) ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </p>
            </div>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                <p>
                    <label style="display:block; font-weight:600;"><?php esc_html_e( 'Player ID (optional)', 'talenttrack' ); ?></label>
                    <input type="number" name="player_id" value="<?php echo esc_attr( (string) ( $idea->player_id ?? '' ) ); ?>" min="0" style="width:100%; padding:8px;" />
                </p>
                <p>
                    <label style="display:block; font-weight:600;"><?php esc_html_e( 'Team ID (optional)', 'talenttrack' ); ?></label>
                    <input type="number" name="team_id" value="<?php echo esc_attr( (string) ( $idea->team_id ?? '' ) ); ?>" min="0" style="width:100%; padding:8px;" />
                </p>
            </div>

            <p style="margin-top:14px;">
                <button type="submit" class="tt-btn tt-btn-primary"><?php esc_html_e( 'Save', 'talenttrack' ); ?></button>
                <a class="tt-btn tt-btn-secondary" href="<?php echo esc_url( $board_url ); ?>"><?php esc_html_e( 'Cancel', 'talenttrack' ); ?></a>
            </p>
        </form>

        <?php if ( (string) $idea->status === IdeaStatus::PROMOTED && ! empty( $idea->promoted_commit_url ) ) : ?>
            <p style="margin-top:18px; color:#1d7874;">
                <?php esc_html_e( 'Promoted as', 'talenttrack' ); ?>
                <strong><?php echo esc_html( (string) $idea->promoted_filename ); ?></strong> —
                <a href="<?php echo esc_url( (string) $idea->promoted_commit_url ); ?>" target="_blank" rel="noopener noreferrer">
                    <?php esc_html_e( 'view commit', 'talenttrack' ); ?>
                </a>
            </p>
        <?php elseif ( (string) $idea->status === IdeaStatus::PROMOTION_FAILED ) : ?>
            <p style="margin-top:18px; color:#b32d2e;">
                <?php esc_html_e( 'Last promotion attempt failed:', 'talenttrack' ); ?>
                <code><?php echo esc_html( (string) ( $idea->promotion_error ?? '' ) ); ?></code>
            </p>
        <?php endif; ?>
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
