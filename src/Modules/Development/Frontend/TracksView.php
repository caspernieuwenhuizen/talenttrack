<?php
namespace TT\Modules\Development\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Development\IdeaRepository;
use TT\Modules\Development\IdeaStatus;
use TT\Modules\Development\TrackRepository;
use TT\Shared\Frontend\Components\FormSaveButton;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;
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

    protected static function enqueueAssets(): void {
        parent::enqueueAssets();
        wp_enqueue_style(
            'tt-frontend-ideas',
            TT_PLUGIN_URL . 'assets/css/frontend-ideas.css',
            [ 'tt-frontend-app-chrome' ],
            TT_VERSION
        );
        wp_enqueue_style(
            'tt-frontend-dev-tracks',
            TT_PLUGIN_URL . 'assets/css/frontend-dev-tracks.css',
            [ 'tt-frontend-app-chrome' ],
            TT_VERSION
        );
    }

    public static function render(): void {
        if ( ! current_user_can( 'tt_view_dev_board' ) ) {
            FrontendBreadcrumbs::fromDashboard( __( 'Not authorized', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'You do not have access to development tracks.', 'talenttrack' ) . '</p>';
            return;
        }

        self::enqueueAssets();
        FrontendBreadcrumbs::fromDashboard( __( 'Development tracks', 'talenttrack' ) );
        self::renderHeader( __( 'Development tracks', 'talenttrack' ) );

        $can_edit = current_user_can( 'tt_refine_idea' );
        $repo  = new TrackRepository();
        $ideas = new IdeaRepository();
        $tracks = $repo->listAll();
        $current = self::currentUrl();

        if ( $can_edit ) :
            ?>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="tt-ideas-card tt-ideas-card--form">
                <?php wp_nonce_field( 'tt_dev_track_save' ); ?>
                <input type="hidden" name="action" value="tt_dev_track_save" />
                <input type="hidden" name="_redirect" value="<?php echo esc_attr( $current ); ?>" />
                <h3 class="tt-ideas-card__head"><?php esc_html_e( 'Add a track', 'talenttrack' ); ?></h3>
                <div class="tt-ideas-field">
                    <label><?php esc_html_e( 'Name', 'talenttrack' ); ?></label>
                    <input type="text" name="name" required maxlength="120" />
                </div>
                <div class="tt-ideas-field">
                    <label><?php esc_html_e( 'Description (optional)', 'talenttrack' ); ?></label>
                    <textarea name="description" rows="3"></textarea>
                </div>
                <?php
                echo FormSaveButton::render( [ // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — component escapes internally.
                    'label'      => __( 'Add track', 'talenttrack' ),
                    'cancel_url' => $current,
                ] );
                ?>
            </form>
            <?php
        endif;

        if ( empty( $tracks ) ) {
            echo '<p class="tt-track-empty"><em>' . esc_html__( 'No tracks yet. Add one above to start grouping ideas into a roadmap.', 'talenttrack' ) . '</em></p>';
            return;
        }

        foreach ( $tracks as $track ) {
            self::renderTrackBlock( $track, $ideas, $can_edit, $current );
        }
    }

    private static function renderTrackBlock( object $track, IdeaRepository $ideas, bool $can_edit, string $current ): void {
        $rows = $ideas->listByTrack( (int) $track->id );
        ?>
        <div class="tt-track-card">
            <div class="tt-track-card__head">
                <div>
                    <h3 class="tt-track-card__title"><?php echo esc_html( (string) $track->name ); ?></h3>
                    <?php if ( ! empty( $track->description ) ) : ?>
                        <p class="tt-track-card__desc"><?php echo esc_html( (string) $track->description ); ?></p>
                    <?php endif; ?>
                </div>
                <?php if ( $can_edit ) : ?>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="tt-track-card__delete" onsubmit="return confirm('<?php echo esc_js( __( 'Delete this track? Tagged ideas will be detached but not deleted.', 'talenttrack' ) ); ?>');">
                        <?php wp_nonce_field( 'tt_dev_track_delete' ); ?>
                        <input type="hidden" name="action" value="tt_dev_track_delete" />
                        <input type="hidden" name="id" value="<?php echo (int) $track->id; ?>" />
                        <input type="hidden" name="_redirect" value="<?php echo esc_attr( $current ); ?>" />
                        <button type="submit" class="tt-btn tt-btn-secondary">
                            <?php esc_html_e( 'Delete track', 'talenttrack' ); ?>
                        </button>
                    </form>
                <?php endif; ?>
            </div>

            <?php if ( empty( $rows ) ) : ?>
                <p class="tt-track-empty"><em><?php esc_html_e( 'No ideas tagged to this track yet.', 'talenttrack' ); ?></em></p>
            <?php else : ?>
                <ul class="tt-track-ideas">
                    <?php foreach ( $rows as $row ) : ?>
                        <li class="tt-track-idea">
                            <span class="tt-track-idea__title"><?php echo esc_html( (string) $row->title ); ?></span>
                            <span class="tt-ideas-chip <?php echo esc_attr( self::statusChipClass( (string) $row->status ) ); ?>">
                                <?php echo esc_html( IdeaStatus::authorFacingLabel( (string) $row->status ) ); ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Map an idea status to a presentational chip-variant class.
     * Pure CSS-class lookup — no business logic; mirrors the chip
     * vocabulary in frontend-ideas.css.
     */
    private static function statusChipClass( string $status ): string {
        switch ( $status ) {
            case IdeaStatus::REJECTED:        return 'tt-ideas-chip--red';
            case IdeaStatus::PROMOTED:        return 'tt-ideas-chip--info';
            case IdeaStatus::IN_PROGRESS:     return 'tt-ideas-chip--gold';
            case IdeaStatus::DONE:            return 'tt-ideas-chip--green';
            default:                          return '';
        }
    }

    private static function currentUrl(): string {
        return isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( (string) wp_unslash( $_SERVER['REQUEST_URI'] ) ) : home_url( '/' );
    }
}
