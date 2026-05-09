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
        ?>
        <p style="color:#666;">
            <?php esc_html_e( 'Approve to commit the idea straight to the talenttrack ideas/ folder on GitHub. The plugin assigns the next free #NNNN automatically.', 'talenttrack' ); ?>
        </p>

        <?php if ( ! $token_ok ) : ?>
            <div class="tt-notice" style="border-left:4px solid #b32d2e; padding:12px; background:#fff4f4;">
                <strong><?php esc_html_e( 'GitHub token not configured.', 'talenttrack' ); ?></strong>
                <p style="margin:6px 0 0;">
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

        <h3 style="margin-top:24px;"><?php esc_html_e( 'Ready for approval', 'talenttrack' ); ?> (<?php echo (int) count( $ready ); ?>)</h3>
        <?php if ( empty( $ready ) ) : ?>
            <p style="color:#888;"><em><?php esc_html_e( 'Queue is empty.', 'talenttrack' ); ?></em></p>
        <?php else : ?>
            <?php foreach ( $ready as $row ) : ?>
                <?php self::renderCard( $row, $current_url, $token_ok, false ); ?>
            <?php endforeach; ?>
        <?php endif; ?>

        <?php if ( ! empty( $failed ) ) : ?>
            <h3 style="margin-top:32px;"><?php esc_html_e( 'Promotion failed', 'talenttrack' ); ?> (<?php echo (int) count( $failed ); ?>)</h3>
            <p style="color:#666;"><?php esc_html_e( 'Retry promotion to re-attempt the GitHub commit. The error from the last attempt is shown on each card.', 'talenttrack' ); ?></p>
            <?php foreach ( $failed as $row ) : ?>
                <?php self::renderCard( $row, $current_url, $token_ok, true ); ?>
            <?php endforeach; ?>
        <?php endif; ?>
        <?php
    }

    private static function renderCard( object $row, string $redirect, bool $token_ok, bool $was_failed ): void {
        $author = self::authorName( (int) $row->author_user_id );
        ?>
        <div style="margin:12px 0; padding:14px; background:#fff; border:1px solid <?php echo $was_failed ? '#f1baba' : '#e5e7ea'; ?>; border-radius:8px;">
            <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:18px;">
                <div style="flex:1; min-width:0;">
                    <strong style="font-size:15px;"><?php echo esc_html( (string) $row->title ); ?></strong>
                    <div style="color:#777; font-size:12px; margin-top:4px;">
                        <?php echo esc_html( IdeaType::label( (string) $row->type ) ); ?>
                        · <?php echo esc_html( $author ); ?>
                        · <?php echo esc_html( (string) $row->created_at ); ?>
                    </div>
                    <?php if ( ! empty( $row->body ) ) : ?>
                        <p style="margin:10px 0 0; white-space:pre-wrap;"><?php echo esc_html( (string) $row->body ); ?></p>
                    <?php endif; ?>
                    <?php if ( $was_failed && ! empty( $row->promotion_error ) ) : ?>
                        <p style="margin:10px 0 0; color:#b32d2e; font-size:12px;">
                            <strong><?php esc_html_e( 'Last error:', 'talenttrack' ); ?></strong>
                            <code><?php echo esc_html( (string) $row->promotion_error ); ?></code>
                        </p>
                    <?php endif; ?>
                </div>
                <div style="flex-shrink:0; min-width:240px;">
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0 0 8px;" onsubmit="return confirm('<?php echo esc_js( sprintf( /* translators: %s = repo slug */ __( 'You\'re about to commit this idea to %s. Continue?', 'talenttrack' ), \TT\Modules\Development\GitHubPromoter::repoSlug() ) ); ?>');">
                        <?php wp_nonce_field( 'tt_dev_idea_promote' ); ?>
                        <input type="hidden" name="action" value="tt_dev_idea_promote" />
                        <input type="hidden" name="id" value="<?php echo (int) $row->id; ?>" />
                        <input type="hidden" name="_redirect" value="<?php echo esc_attr( $redirect ); ?>" />
                        <button type="submit" class="tt-btn tt-btn-primary" style="width:100%;" <?php disabled( ! $token_ok ); ?> title="<?php echo $token_ok ? '' : esc_attr__( 'TT_GITHUB_TOKEN constant must be set in wp-config.php.', 'talenttrack' ); ?>">
                            <?php echo $was_failed ? esc_html__( 'Retry promotion', 'talenttrack' ) : esc_html__( 'Approve & promote', 'talenttrack' ); ?>
                        </button>
                    </form>
                    <details style="margin-top:6px;">
                        <summary style="cursor:pointer; color:#b32d2e; font-size:12px;"><?php esc_html_e( 'Reject with note', 'talenttrack' ); ?></summary>
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:6px;">
                            <?php wp_nonce_field( 'tt_dev_idea_reject' ); ?>
                            <input type="hidden" name="action" value="tt_dev_idea_reject" />
                            <input type="hidden" name="id" value="<?php echo (int) $row->id; ?>" />
                            <input type="hidden" name="_redirect" value="<?php echo esc_attr( $redirect ); ?>" />
                            <textarea name="rejection_note" rows="3" style="width:100%; padding:6px; font-size:12px;" placeholder="<?php esc_attr_e( 'Tell the author why', 'talenttrack' ); ?>"></textarea>
                            <button type="submit" class="tt-btn tt-btn-secondary" style="width:100%; margin-top:4px;">
                                <?php esc_html_e( 'Reject', 'talenttrack' ); ?>
                            </button>
                        </form>
                    </details>
                </div>
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
