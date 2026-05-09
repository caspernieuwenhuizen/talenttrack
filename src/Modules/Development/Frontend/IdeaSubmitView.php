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
 * IdeaSubmitView — the "Submit an idea" tile destination.
 *
 * The form posts to admin-post.php → IdeaSubmitHandler. After a
 * successful save the handler flashes + redirects back to this view
 * so the author sees their idea in the "My recent submissions" list
 * underneath the form.
 */
class IdeaSubmitView extends FrontendViewBase {

    public static function render(): void {
        if ( ! current_user_can( 'tt_submit_idea' ) ) {
            FrontendBreadcrumbs::fromDashboard( __( 'Not authorized', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'Your role does not allow submitting ideas.', 'talenttrack' ) . '</p>';
            return;
        }

        self::enqueueAssets();
        FrontendBreadcrumbs::fromDashboard( __( 'Submit an idea', 'talenttrack' ) );
        self::renderHeader( __( 'Submit an idea', 'talenttrack' ) );

        $tracks = ( new TrackRepository() )->listAll();
        $repo = new IdeaRepository();
        $mine = $repo->listByAuthor( get_current_user_id(), 20 );

        $current_url = self::currentUrl();
        ?>
        <p style="color:#666; max-width:640px;">
            <?php esc_html_e( 'Spotted something to fix or build? Drop a short title and as much detail as you have. The lead developer reviews submissions and either rejects with a note or promotes them straight into the development queue on GitHub.', 'talenttrack' ); ?>
        </p>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:640px; margin:20px 0; padding:20px; background:#fff; border:1px solid #e5e7ea; border-radius:8px;">
            <?php wp_nonce_field( 'tt_dev_idea_submit' ); ?>
            <input type="hidden" name="action" value="tt_dev_idea_submit" />
            <input type="hidden" name="_redirect" value="<?php echo esc_attr( $current_url ); ?>" />

            <p style="margin:0 0 12px;">
                <label for="tt-idea-title" style="display:block; font-weight:600; margin-bottom:4px;">
                    <?php esc_html_e( 'Title', 'talenttrack' ); ?>
                </label>
                <input type="text" id="tt-idea-title" name="title" required maxlength="255" style="width:100%; padding:8px;" placeholder="<?php esc_attr_e( 'A short summary of the idea', 'talenttrack' ); ?>" />
            </p>

            <p style="margin:0 0 12px;">
                <label for="tt-idea-body" style="display:block; font-weight:600; margin-bottom:4px;">
                    <?php esc_html_e( 'Details', 'talenttrack' ); ?>
                </label>
                <textarea id="tt-idea-body" name="body" rows="6" style="width:100%; padding:8px;" placeholder="<?php esc_attr_e( 'What\'s the problem, who is affected, what do you want to happen?', 'talenttrack' ); ?>"></textarea>
            </p>

            <p style="margin:0 0 12px;">
                <label for="tt-idea-type" style="display:block; font-weight:600; margin-bottom:4px;">
                    <?php esc_html_e( 'Type', 'talenttrack' ); ?>
                </label>
                <select id="tt-idea-type" name="type" style="padding:8px;">
                    <?php foreach ( IdeaType::all() as $t ) : ?>
                        <option value="<?php echo esc_attr( $t ); ?>" <?php selected( $t, IdeaType::NEEDS_TRIAGE ); ?>>
                            <?php echo esc_html( IdeaType::label( $t ) ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </p>

            <?php if ( ! empty( $tracks ) ) : ?>
                <p style="margin:0 0 12px;">
                    <label for="tt-idea-track" style="display:block; font-weight:600; margin-bottom:4px;">
                        <?php esc_html_e( 'Development track (optional)', 'talenttrack' ); ?>
                    </label>
                    <select id="tt-idea-track" name="track_id" style="padding:8px;">
                        <option value="0">— <?php esc_html_e( 'No track', 'talenttrack' ); ?> —</option>
                        <?php foreach ( $tracks as $tr ) : ?>
                            <option value="<?php echo (int) $tr->id; ?>"><?php echo esc_html( (string) $tr->name ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </p>
            <?php endif; ?>

            <p style="margin:16px 0 0;">
                <button type="submit" class="tt-btn tt-btn-primary">
                    <?php esc_html_e( 'Submit idea', 'talenttrack' ); ?>
                </button>
            </p>
        </form>

        <?php if ( ! empty( $mine ) ) : ?>
            <h3 style="margin-top:32px;"><?php esc_html_e( 'My recent submissions', 'talenttrack' ); ?></h3>
            <table class="tt-table" style="width:100%; max-width:760px;">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Title', 'talenttrack' ); ?></th>
                        <th><?php esc_html_e( 'Type', 'talenttrack' ); ?></th>
                        <th><?php esc_html_e( 'Status', 'talenttrack' ); ?></th>
                        <th><?php esc_html_e( 'Submitted', 'talenttrack' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $mine as $row ) : ?>
                    <tr>
                        <td>
                            <strong><?php echo esc_html( (string) $row->title ); ?></strong>
                            <?php if ( (string) $row->status === IdeaStatus::REJECTED && ! empty( $row->rejection_note ) ) : ?>
                                <br><small style="color:#b32d2e;"><?php echo esc_html( (string) $row->rejection_note ); ?></small>
                            <?php endif; ?>
                            <?php if ( (string) $row->status === IdeaStatus::PROMOTED && ! empty( $row->promoted_filename ) ) : ?>
                                <br><small style="color:#1d7874;"><?php echo esc_html( (string) $row->promoted_filename ); ?></small>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html( IdeaType::label( (string) $row->type ) ); ?></td>
                        <td><?php echo esc_html( IdeaStatus::authorFacingLabel( (string) $row->status ) ); ?></td>
                        <td><?php echo esc_html( (string) $row->created_at ); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <?php
    }

    private static function currentUrl(): string {
        $req = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( (string) wp_unslash( $_SERVER['REQUEST_URI'] ) ) : home_url( '/' );
        return $req;
    }
}
