<?php
namespace TT\Modules\Development\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Development\IdeaRepository;
use TT\Modules\Development\IdeaStatus;
use TT\Modules\Development\TrackRepository;
use TT\Shared\Frontend\FrontendBackButton;
use TT\Shared\Frontend\FrontendViewBase;

/**
 * TracksView — admin-curated development tracks + the per-track
 * roadmap of tagged ideas.
 *
 * Two halves on one page so the admin doesn't have to jump:
 *  - top: create / rename / delete tracks
 *  - below: per-track ordered list of ideas tagged to that track,
 *    with their current status pill (using author-facing labels so
 *    the page reads as a roadmap rather than internal plumbing).
 */
class TracksView extends FrontendViewBase {

    public static function render(): void {
        if ( ! current_user_can( 'tt_view_dev_board' ) ) {
            FrontendBackButton::render();
            echo '<p class="tt-notice">' . esc_html__( 'You do not have access to development tracks.', 'talenttrack' ) . '</p>';
            return;
        }

        self::enqueueAssets();
        self::renderHeader( __( 'Development tracks', 'talenttrack' ) );

        $can_edit = current_user_can( 'tt_refine_idea' );
        $repo  = new TrackRepository();
        $ideas = new IdeaRepository();
        $tracks = $repo->listAll();
        $current = self::currentUrl();

        if ( $can_edit ) :
            ?>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:560px; padding:14px; background:#fff; border:1px solid #e5e7ea; border-radius:8px;">
                <?php wp_nonce_field( 'tt_dev_track_save' ); ?>
                <input type="hidden" name="action" value="tt_dev_track_save" />
                <input type="hidden" name="_redirect" value="<?php echo esc_attr( $current ); ?>" />
                <h3 style="margin:0 0 10px;"><?php esc_html_e( 'Add a track', 'talenttrack' ); ?></h3>
                <p>
                    <label style="display:block; font-weight:600;"><?php esc_html_e( 'Name', 'talenttrack' ); ?></label>
                    <input type="text" name="name" required maxlength="120" style="width:100%; padding:8px;" />
                </p>
                <p>
                    <label style="display:block; font-weight:600;"><?php esc_html_e( 'Description (optional)', 'talenttrack' ); ?></label>
                    <textarea name="description" rows="3" style="width:100%; padding:8px;"></textarea>
                </p>
                <p style="margin:0;">
                    <button type="submit" class="tt-btn tt-btn-primary"><?php esc_html_e( 'Add track', 'talenttrack' ); ?></button>
                </p>
            </form>
            <?php
        endif;

        if ( empty( $tracks ) ) {
            echo '<p style="margin-top:18px;"><em>' . esc_html__( 'No tracks yet. Add one above to start grouping ideas into a roadmap.', 'talenttrack' ) . '</em></p>';
            return;
        }

        foreach ( $tracks as $track ) {
            self::renderTrackBlock( $track, $ideas, $can_edit, $current );
        }
    }

    private static function renderTrackBlock( object $track, IdeaRepository $ideas, bool $can_edit, string $current ): void {
        $rows = $ideas->listByTrack( (int) $track->id );
        ?>
        <div style="margin:24px 0; padding:16px; background:#fff; border:1px solid #e5e7ea; border-radius:8px;">
            <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:18px;">
                <div style="flex:1; min-width:0;">
                    <h3 style="margin:0;"><?php echo esc_html( (string) $track->name ); ?></h3>
                    <?php if ( ! empty( $track->description ) ) : ?>
                        <p style="margin:6px 0 0; color:#666;"><?php echo esc_html( (string) $track->description ); ?></p>
                    <?php endif; ?>
                </div>
                <?php if ( $can_edit ) : ?>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Delete this track? Tagged ideas will be detached but not deleted.', 'talenttrack' ) ); ?>');">
                        <?php wp_nonce_field( 'tt_dev_track_delete' ); ?>
                        <input type="hidden" name="action" value="tt_dev_track_delete" />
                        <input type="hidden" name="id" value="<?php echo (int) $track->id; ?>" />
                        <input type="hidden" name="_redirect" value="<?php echo esc_attr( $current ); ?>" />
                        <button type="submit" class="tt-btn tt-btn-secondary" style="font-size:12px;">
                            <?php esc_html_e( 'Delete track', 'talenttrack' ); ?>
                        </button>
                    </form>
                <?php endif; ?>
            </div>

            <?php if ( empty( $rows ) ) : ?>
                <p style="margin:12px 0 0; color:#888;"><em><?php esc_html_e( 'No ideas tagged to this track yet.', 'talenttrack' ); ?></em></p>
            <?php else : ?>
                <ul style="margin:14px 0 0; padding:0; list-style:none;">
                    <?php foreach ( $rows as $row ) : ?>
                        <li style="display:flex; align-items:center; gap:10px; padding:8px 0; border-bottom:1px solid #f0f1f2;">
                            <span style="flex:1; min-width:0;"><?php echo esc_html( (string) $row->title ); ?></span>
                            <span style="font-size:12px; padding:3px 8px; border-radius:10px; background:<?php echo esc_attr( self::pillBg( (string) $row->status ) ); ?>; color:#fff;">
                                <?php echo esc_html( IdeaStatus::authorFacingLabel( (string) $row->status ) ); ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
        <?php
    }

    private static function pillBg( string $status ): string {
        switch ( $status ) {
            case IdeaStatus::REJECTED:        return '#b32d2e';
            case IdeaStatus::PROMOTED:        return '#2271b1';
            case IdeaStatus::IN_PROGRESS:     return '#c9962a';
            case IdeaStatus::DONE:            return '#1d7874';
            default:                          return '#888';
        }
    }

    private static function currentUrl(): string {
        return isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( (string) wp_unslash( $_SERVER['REQUEST_URI'] ) ) : home_url( '/' );
    }
}
