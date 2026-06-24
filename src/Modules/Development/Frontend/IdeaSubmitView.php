<?php
namespace TT\Modules\Development\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Development\IdeaRepository;
use TT\Modules\Development\IdeaStatus;
use TT\Modules\Development\IdeaType;
use TT\Modules\Development\TrackRepository;
use TT\Shared\Frontend\Components\FormSaveButton;
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
        $cancel_url  = self::dashboardUrl();
        ?>
        <p class="tt-ideas-lede">
            <?php esc_html_e( 'Spotted something to fix or build? Drop a short title and as much detail as you have. The lead developer reviews submissions and either rejects with a note or promotes them straight into the development queue on GitHub.', 'talenttrack' ); ?>
        </p>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="tt-ideas-card tt-ideas-card--form">
            <?php wp_nonce_field( 'tt_dev_idea_submit' ); ?>
            <input type="hidden" name="action" value="tt_dev_idea_submit" />
            <input type="hidden" name="_redirect" value="<?php echo esc_attr( $current_url ); ?>" />

            <div class="tt-ideas-field">
                <label for="tt-idea-title"><?php esc_html_e( 'Title', 'talenttrack' ); ?></label>
                <input type="text" id="tt-idea-title" name="title" required maxlength="255" placeholder="<?php esc_attr_e( 'A short summary of the idea', 'talenttrack' ); ?>" />
            </div>

            <div class="tt-ideas-field">
                <label for="tt-idea-body"><?php esc_html_e( 'Details', 'talenttrack' ); ?></label>
                <textarea id="tt-idea-body" name="body" rows="6" placeholder="<?php esc_attr_e( 'What\'s the problem, who is affected, what do you want to happen?', 'talenttrack' ); ?>"></textarea>
            </div>

            <div class="tt-ideas-field">
                <label for="tt-idea-type"><?php esc_html_e( 'Type', 'talenttrack' ); ?></label>
                <select id="tt-idea-type" name="type">
                    <?php foreach ( IdeaType::all() as $t ) : ?>
                        <option value="<?php echo esc_attr( $t ); ?>" <?php selected( $t, IdeaType::NEEDS_TRIAGE ); ?>>
                            <?php echo esc_html( IdeaType::label( $t ) ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <?php if ( ! empty( $tracks ) ) : ?>
                <div class="tt-ideas-field">
                    <label for="tt-idea-track"><?php esc_html_e( 'Development track (optional)', 'talenttrack' ); ?></label>
                    <select id="tt-idea-track" name="track_id">
                        <option value="0">— <?php esc_html_e( 'No track', 'talenttrack' ); ?> —</option>
                        <?php foreach ( $tracks as $tr ) : ?>
                            <option value="<?php echo (int) $tr->id; ?>"><?php echo esc_html( (string) $tr->name ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>

            <?php
            echo FormSaveButton::render( [ // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — component escapes internally.
                'label'      => __( 'Submit idea', 'talenttrack' ),
                'cancel_url' => $cancel_url,
            ] );
            ?>
        </form>

        <?php if ( ! empty( $mine ) ) : ?>
            <h3 class="tt-ideas-section-head"><?php esc_html_e( 'My recent submissions', 'talenttrack' ); ?></h3>
            <table class="tt-table">
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
                                <br><small class="tt-ideas-note--reject"><?php echo esc_html( (string) $row->rejection_note ); ?></small>
                            <?php endif; ?>
                            <?php if ( (string) $row->status === IdeaStatus::PROMOTED && ! empty( $row->promoted_filename ) ) : ?>
                                <br><small class="tt-ideas-note--ok"><?php echo esc_html( (string) $row->promoted_filename ); ?></small>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html( IdeaType::label( (string) $row->type ) ); ?></td>
                        <td><span class="tt-ideas-chip <?php echo esc_attr( self::statusChipClass( (string) $row->status ) ); ?>"><?php echo esc_html( IdeaStatus::authorFacingLabel( (string) $row->status ) ); ?></span></td>
                        <td><?php echo esc_html( (string) $row->created_at ); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
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

    private static function dashboardUrl(): string {
        $current = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( (string) wp_unslash( $_SERVER['REQUEST_URI'] ) ) : home_url( '/' );
        return remove_query_arg( [ 'tt_view', 'id', 'action' ], $current );
    }

    private static function currentUrl(): string {
        $req = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( (string) wp_unslash( $_SERVER['REQUEST_URI'] ) ) : home_url( '/' );
        return $req;
    }
}
