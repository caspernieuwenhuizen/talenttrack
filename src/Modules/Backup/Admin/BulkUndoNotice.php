<?php
namespace TT\Modules\Backup\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Backup\BulkSafetyHook;
use TT\Modules\License\LicenseGate;

/**
 * BulkUndoNotice — surfaces an admin notice with an "Undo via backup"
 * link after a destructive bulk operation that triggered an auto-
 * safety snapshot.
 *
 * Renders on every TalentTrack admin pageload while the per-user undo
 * transient exists (14-day TTL set by BulkSafetyHook). The Undo link
 * dispatches to BulkUndoHandler which performs the partial restore.
 *
 * The notice is dismissible via a small "Dismiss" link that consumes
 * the transient without restoring — useful when the admin meant to do
 * the bulk action and doesn't want the link cluttering the dashboard
 * for two weeks.
 */
class BulkUndoNotice {

    public static function init(): void {
        add_action( 'admin_notices', [ self::class, 'maybeRender' ] );
    }

    public static function maybeRender(): void {
        $user_id = get_current_user_id();
        if ( $user_id <= 0 ) return;
        $payload = BulkSafetyHook::peekPending( $user_id );
        if ( ! $payload ) return;

        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen ) return;
        // Only render on TalentTrack admin pages — don't pollute the
        // entire wp-admin with this notice.
        if ( strpos( (string) $screen->id, 'talenttrack' ) === false && strpos( (string) $screen->id, 'tt-' ) === false ) {
            return;
        }

        $action = (string) ( $payload['action'] ?? '' );
        $entity = (string) ( $payload['entity'] ?? '' );
        $count  = is_array( $payload['ids'] ?? null ) ? count( $payload['ids'] ) : 0;
        $when   = isset( $payload['created_at'] ) ? (int) $payload['created_at'] : time();

        $undo_url = wp_nonce_url(
            add_query_arg(
                [ 'action' => 'tt_backup_bulk_undo' ],
                admin_url( 'admin-post.php' )
            ),
            'tt_backup_bulk_undo',
            'tt_backup_undo_nonce'
        );
        $dismiss_url = wp_nonce_url(
            add_query_arg(
                [ 'action' => 'tt_backup_bulk_undo_dismiss' ],
                admin_url( 'admin-post.php' )
            ),
            'tt_backup_bulk_undo_dismiss',
            'tt_backup_undo_nonce'
        );

        $action_label = $action === 'archive'
            ? __( 'archived', 'talenttrack' )
            : ( $action === 'delete_permanent' ? __( 'permanently deleted', 'talenttrack' ) : $action );

        // #0080 Wave A — Free-tier sees the safety-backup notice (the
        // backup is taken regardless; that's defensive code) but the
        // undo link is replaced with a paywall variant. Standard+ keeps
        // the working undo link.
        $can_undo = LicenseGate::allows( 'undo_bulk' );
        $account_url = admin_url( 'admin.php?page=tt-account&tab=plan' );
        ?>
        <div class="notice notice-warning is-dismissible">
            <p>
                <?php
                printf(
                    /* translators: 1: number of rows, 2: action verb in past tense, 3: entity name */
                    esc_html__( '%1$d %3$s %2$s. A safety backup was taken — you can undo this for the next 14 days.', 'talenttrack' ),
                    (int) $count,
                    esc_html( $action_label ),
                    esc_html( $entity )
                );
                ?>
                <?php if ( $can_undo ) : ?>
                    <a href="<?php echo esc_url( $undo_url ); ?>" style="margin-left:8px;">
                        <strong><?php esc_html_e( 'Undo via backup →', 'talenttrack' ); ?></strong>
                    </a>
                <?php else : ?>
                    <a href="<?php echo esc_url( $account_url ); ?>" style="margin-left:8px;">
                        <strong><?php esc_html_e( 'Bulk-undo is part of the Standard plan. Upgrade to recover from accidents.', 'talenttrack' ); ?></strong>
                    </a>
                <?php endif; ?>
                <a href="<?php echo esc_url( $dismiss_url ); ?>" style="margin-left:12px; color:#666;">
                    <?php esc_html_e( 'Dismiss', 'talenttrack' ); ?>
                </a>
            </p>
        </div>
        <?php
    }
}
