<?php
namespace TT\Modules\Development\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Development\GitHubPromoter;
use TT\Modules\Development\IdeaRepository;
use TT\Modules\Development\IdeaStatus;
use TT\Modules\Development\IdeaType;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;
use TT\Shared\Frontend\FrontendViewBase;

/**
 * IdeasApprovalView — lead-developer approval queue.
 *
 * Lists everything in `ready-for-approval` plus anything currently
 * stuck in `promotion-failed` (so the lead dev can retry). Cap-gated
 * by `tt_promote_idea` (administrator-only).
 *
 * The "Approve & promote" button is disabled with a tooltip when the
 * `TT_GITHUB_TOKEN` constant is not configured — surfacing the setup
 * gap right where the lead dev expects to act.
 */
class IdeasApprovalView extends FrontendViewBase {

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
        if ( ! current_user_can( 'tt_promote_idea' ) ) {
            FrontendBreadcrumbs::fromDashboard( __( 'Not authorized', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'Only administrators can promote ideas.', 'talenttrack' ) . '</p>';
            return;
        }

        self::enqueueAssets();
        FrontendBreadcrumbs::fromDashboard( __( 'Approval queue', 'talenttrack' ) );
        self::renderHeader( __( 'Approval queue', 'talenttrack' ) );

        $repo  = new IdeaRepository();
        $ready  = $repo->listByStatus( IdeaStatus::READY_FOR_APPROVAL, 100 );
        $failed = $repo->listByStatus( IdeaStatus::PROMOTION_FAILED, 100 );

        $token_ok = GitHubPromoter::tokenAvailable();
        $current_url = self::currentUrl();
        $kpi = \TT\Shared\Frontend\Components\FrontendAppChrome::class;
        ?>
        <div class="tt-ideas-kpis">
            <?php
            echo $kpi::kpiTile( [ // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — helper escapes internally.
                'label' => __( 'Ready for approval', 'talenttrack' ),
                'value' => (string) count( $ready ),
            ] );
            echo $kpi::kpiTile( [ // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — helper escapes internally.
                'label' => __( 'Promotion failed', 'talenttrack' ),
                'value' => (string) count( $failed ),
                'flag'  => count( $failed ) > 0 ? 'red' : '',
            ] );
            ?>
        </div>

        <p class="tt-ideas-lede">
            <?php esc_html_e( 'Approve to commit the idea straight to the talenttrack ideas/ folder on GitHub. The plugin assigns the next free #NNNN automatically.', 'talenttrack' ); ?>
        </p>

        <?php if ( ! $token_ok ) : ?>
            <div class="tt-notice tt-ideas-card tt-ideas-card--alert">
                <strong><?php esc_html_e( 'GitHub token not configured.', 'talenttrack' ); ?></strong>
                <p class="tt-ideas-alert-detail">
                    <?php
                    printf(
                        /* translators: 1: constant name, 2: file path */
                        esc_html__( 'Set %1$s in %2$s with a fine-grained PAT scoped to "contents: write" on the talenttrack repo. Until that is done, promotion is disabled.', 'talenttrack' ),
                        '<code>TT_GITHUB_TOKEN</code>',
                        '<code>wp-config.php</code>'
                    );
                    ?>
                </p>
            </div>
        <?php endif; ?>

        <h3 class="tt-ideas-section-head"><?php esc_html_e( 'Ready for approval', 'talenttrack' ); ?> (<?php echo (int) count( $ready ); ?>)</h3>
        <?php if ( empty( $ready ) ) : ?>
            <p class="tt-track-empty"><em><?php esc_html_e( 'Queue is empty.', 'talenttrack' ); ?></em></p>
        <?php else : ?>
            <?php foreach ( $ready as $row ) : ?>
                <?php self::renderCard( $row, $current_url, $token_ok, false ); ?>
            <?php endforeach; ?>
        <?php endif; ?>

        <?php if ( ! empty( $failed ) ) : ?>
            <h3 class="tt-ideas-section-head"><?php esc_html_e( 'Promotion failed', 'talenttrack' ); ?> (<?php echo (int) count( $failed ); ?>)</h3>
            <p class="tt-ideas-lede"><?php esc_html_e( 'Retry promotion to re-attempt the GitHub commit. The error from the last attempt is shown on each card.', 'talenttrack' ); ?></p>
            <?php foreach ( $failed as $row ) : ?>
                <?php self::renderCard( $row, $current_url, $token_ok, true ); ?>
            <?php endforeach; ?>
        <?php endif; ?>
        <?php
    }

    private static function renderCard( object $row, string $redirect, bool $token_ok, bool $was_failed ): void {
        $author = self::authorName( (int) $row->author_user_id );
        $card_class = 'tt-ideas-card tt-approval-card' . ( $was_failed ? ' tt-approval-card--failed' : '' );
        ?>
        <div class="<?php echo esc_attr( $card_class ); ?>">
            <div class="tt-approval-card__main">
                <strong class="tt-approval-card__title"><?php echo esc_html( (string) $row->title ); ?></strong>
                <div class="tt-approval-card__meta">
                    <?php echo esc_html( IdeaType::label( (string) $row->type ) ); ?>
                    · <?php echo esc_html( $author ); ?>
                    · <?php echo esc_html( (string) $row->created_at ); ?>
                </div>
                <?php if ( ! empty( $row->body ) ) : ?>
                    <p class="tt-approval-card__body"><?php echo esc_html( (string) $row->body ); ?></p>
                <?php endif; ?>
                <?php if ( $was_failed && ! empty( $row->promotion_error ) ) : ?>
                    <p class="tt-approval-card__error">
                        <strong><?php esc_html_e( 'Last error:', 'talenttrack' ); ?></strong>
                        <code><?php echo esc_html( (string) $row->promotion_error ); ?></code>
                    </p>
                <?php endif; ?>
            </div>
            <div class="tt-approval-card__actions">
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( sprintf( /* translators: %s = repo slug */ __( 'You\'re about to commit this idea to %s. Continue?', 'talenttrack' ), \TT\Modules\Development\GitHubPromoter::repoSlug() ) ); ?>');">
                    <?php wp_nonce_field( 'tt_dev_idea_promote' ); ?>
                    <input type="hidden" name="action" value="tt_dev_idea_promote" />
                    <input type="hidden" name="id" value="<?php echo (int) $row->id; ?>" />
                    <input type="hidden" name="_redirect" value="<?php echo esc_attr( $redirect ); ?>" />
                    <button type="submit" class="tt-btn tt-btn-primary" <?php disabled( ! $token_ok ); ?> title="<?php echo $token_ok ? '' : esc_attr__( 'TT_GITHUB_TOKEN constant must be set in wp-config.php.', 'talenttrack' ); ?>">
                        <?php echo $was_failed ? esc_html__( 'Retry promotion', 'talenttrack' ) : esc_html__( 'Approve & promote', 'talenttrack' ); ?>
                    </button>
                </form>
                <details class="tt-approval-card__reject">
                    <summary><?php esc_html_e( 'Reject with note', 'talenttrack' ); ?></summary>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <?php wp_nonce_field( 'tt_dev_idea_reject' ); ?>
                        <input type="hidden" name="action" value="tt_dev_idea_reject" />
                        <input type="hidden" name="id" value="<?php echo (int) $row->id; ?>" />
                        <input type="hidden" name="_redirect" value="<?php echo esc_attr( $redirect ); ?>" />
                        <label class="screen-reader-text" for="tt-reject-note-<?php echo (int) $row->id; ?>"><?php esc_html_e( 'Rejection note', 'talenttrack' ); ?></label>
                        <textarea id="tt-reject-note-<?php echo (int) $row->id; ?>" name="rejection_note" rows="3" placeholder="<?php esc_attr_e( 'Tell the author why', 'talenttrack' ); ?>"></textarea>
                        <button type="submit" class="tt-btn tt-btn-secondary">
                            <?php esc_html_e( 'Reject', 'talenttrack' ); ?>
                        </button>
                    </form>
                </details>
            </div>
        </div>
        <?php
    }

    private static function authorName( int $userId ): string {
        $u = get_userdata( $userId );
        return $u ? (string) $u->display_name : __( 'Unknown', 'talenttrack' );
    }

    private static function currentUrl(): string {
        return isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( (string) wp_unslash( $_SERVER['REQUEST_URI'] ) ) : home_url( '/' );
    }
}
