<?php
namespace TT\Modules\Development\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Development\GitHubPromoter;
use TT\Modules\Development\IdeaRepository;
use TT\Modules\Development\IdeaStatus;
use TT\Shared\Frontend\FlashMessages;

/**
 * Handles `admin-post.php?action=tt_dev_idea_promote` — the lead-dev
 * "Approve & promote" click that commits the idea to GitHub.
 *
 * Cap-gated by `tt_promote_idea` (administrator only by default).
 * `IdeaRepository::claimForPromotion()` performs an atomic flip from
 * `ready-for-approval` → `promoting` so two simultaneous clicks can't
 * both fire the API call.
 */
class IdeaPromoteHandler {

    public static function handle(): void {
        if ( ! is_user_logged_in() ) wp_die( 'Not logged in.', 403 );
        if ( ! current_user_can( 'tt_promote_idea' ) ) wp_die( 'Insufficient permissions.', 403 );

        check_admin_referer( 'tt_dev_idea_promote' );

        $id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
        if ( $id <= 0 ) wp_die( 'Bad request.', 400 );

        $redirect = isset( $_POST['_redirect'] ) ? esc_url_raw( wp_unslash( (string) $_POST['_redirect'] ) ) : home_url( '/' );

        if ( ! GitHubPromoter::tokenAvailable() ) {
            FlashMessages::add( 'error', __( 'Cannot promote: TT_GITHUB_TOKEN constant is not defined in wp-config.php.', 'talenttrack' ) );
            wp_safe_redirect( $redirect );
            exit;
        }

        $repo = new IdeaRepository();
        $idea = $repo->find( $id );
        if ( ! $idea ) wp_die( 'Not found.', 404 );

        // Allow re-promotion from `promotion-failed` too — clear the
        // error and put it back into `ready-for-approval` first so the
        // atomic claim works.
        if ( (string) $idea->status === IdeaStatus::PROMOTION_FAILED ) {
            $repo->update( $id, [
                'status'          => IdeaStatus::READY_FOR_APPROVAL,
                'promotion_error' => null,
            ] );
            $idea = $repo->find( $id );
        }

        if ( ! $idea || (string) $idea->status !== IdeaStatus::READY_FOR_APPROVAL ) {
            FlashMessages::add( 'error', __( 'Idea is not in "Ready for approval" — refresh and try again.', 'talenttrack' ) );
            wp_safe_redirect( $redirect );
            exit;
        }

        if ( ! $repo->claimForPromotion( $id ) ) {
            FlashMessages::add( 'error', __( 'Another promotion is in flight for this idea.', 'talenttrack' ) );
            wp_safe_redirect( $redirect );
            exit;
        }

        $promoter = new GitHubPromoter( $repo );
        $result   = $promoter->promote( $id );

        if ( $result['ok'] ) {
            FlashMessages::add(
                'success',
                sprintf(
                    /* translators: %s = filename */
                    __( 'Promoted to GitHub as %s.', 'talenttrack' ),
                    (string) $result['filename']
                )
            );
        } else {
            FlashMessages::add(
                'error',
                sprintf(
                    /* translators: %s = error message */
                    __( 'Promotion failed: %s', 'talenttrack' ),
                    (string) $result['error']
                )
            );
        }

        wp_safe_redirect( $redirect );
        exit;
    }
}
