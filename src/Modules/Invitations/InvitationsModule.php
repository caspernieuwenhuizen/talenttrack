<?php
namespace TT\Modules\Invitations;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\Container;
use TT\Core\ModuleInterface;
use TT\Modules\Invitations\Notifications\InvitationAuditLogger;

/**
 * InvitationsModule (#0032) — invitation flow + parent linking.
 *
 * Owns:
 *   - Schema (migration 0025): tt_invitations + tt_player_parents +
 *     locale columns on tt_players + tt_people.
 *   - The new tt_parent WP role with read + tt_view_parent_dashboard.
 *   - Three new caps: tt_send_invitation (admin + head_dev + club_admin
 *     + coach), tt_revoke_invitation (admin + head_dev + club_admin),
 *     tt_manage_invite_messages (admin + club_admin).
 *   - Acceptance route (?tt_view=accept-invite&token=NN) on the
 *     dashboard shortcode.
 *   - Form-post handlers for create / accept / revoke / message-save.
 *   - Audit logger that subscribes to tt_invitation_* hooks.
 *
 * The InviteButton component renders on three surfaces (frontend
 * roster row, wp-admin player edit, wp-admin people edit). Each
 * surface re-checks tt_send_invitation before drawing the button.
 */
class InvitationsModule implements ModuleInterface {

    public function getName(): string { return 'invitations'; }

    public function register( Container $container ): void {}

    public function boot( Container $container ): void {
        add_action( 'init', [ self::class, 'ensureCaps' ] );
        add_action( 'init', [ InvitationAuditLogger::class, 'register' ] );

        // Form-post handlers — frontend posts to admin-post.php for a
        // clean redirect after the write.
        add_action( 'admin_post_tt_invitation_create',   [ Frontend\InvitationCreateHandler::class, 'handle' ] );
        add_action( 'admin_post_tt_invitation_accept',   [ Frontend\InvitationAcceptHandler::class, 'handle' ] );
        add_action( 'admin_post_tt_invitation_revoke',   [ Frontend\InvitationRevokeHandler::class, 'handle' ] );
        add_action( 'admin_post_tt_invitation_message_save', [ Frontend\MessageSaveHandler::class, 'handle' ] );
        // Acceptance handler is also reachable when not logged in.
        add_action( 'admin_post_nopriv_tt_invitation_accept', [ Frontend\InvitationAcceptHandler::class, 'handle' ] );
    }

    public static function ensureCaps(): void {
        $send_roles = [ 'administrator', 'tt_head_dev', 'tt_club_admin', 'tt_coach' ];
        foreach ( $send_roles as $slug ) {
            $role = get_role( $slug );
            if ( $role && ! $role->has_cap( 'tt_send_invitation' ) ) {
                $role->add_cap( 'tt_send_invitation' );
            }
        }

        $revoke_roles = [ 'administrator', 'tt_head_dev', 'tt_club_admin' ];
        foreach ( $revoke_roles as $slug ) {
            $role = get_role( $slug );
            if ( $role && ! $role->has_cap( 'tt_revoke_invitation' ) ) {
                $role->add_cap( 'tt_revoke_invitation' );
            }
        }

        $message_roles = [ 'administrator', 'tt_club_admin' ];
        foreach ( $message_roles as $slug ) {
            $role = get_role( $slug );
            if ( $role && ! $role->has_cap( 'tt_manage_invite_messages' ) ) {
                $role->add_cap( 'tt_manage_invite_messages' );
            }
        }

        // tt_parent role — re-check the cap idempotently in case the
        // migration ran on a stale install before this code shipped.
        $parent = get_role( 'tt_parent' );
        if ( $parent && ! $parent->has_cap( 'tt_view_parent_dashboard' ) ) {
            $parent->add_cap( 'tt_view_parent_dashboard' );
        }
        // Admin + head_dev see the parent dashboard for support.
        foreach ( [ 'administrator', 'tt_head_dev' ] as $slug ) {
            $role = get_role( $slug );
            if ( $role && ! $role->has_cap( 'tt_view_parent_dashboard' ) ) {
                $role->add_cap( 'tt_view_parent_dashboard' );
            }
        }
    }
}
