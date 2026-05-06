<?php
namespace TT\Modules\Mfa\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Mfa\Domain\BackupCodesService;
use TT\Modules\Mfa\MfaSecretsRepository;

/**
 * MfaActionHandlers — `admin-post.php` endpoints for the actions on
 * the Account-page MFA tab (#0086 Workstream B Child 1, sprint 2).
 *
 * Two actions for the user's own MFA state:
 *   - `tt_mfa_regenerate_backup_codes` — issue a fresh set of 10 codes
 *     and overwrite the stored hashes. Returns the user to the MFA tab
 *     with `tt_msg=mfa_backup_regenerated` so the tab shows the codes
 *     once.
 *   - `tt_mfa_disable` — wipe the user's `tt_user_mfa` row. Used when
 *     the user wants to turn MFA off entirely (or before re-enrolling
 *     from scratch).
 *
 * Both endpoints require the user to be logged-in and operate on their
 * own row only. There is no admin-on-behalf-of-another flow yet — that
 * lands in sprint 3 alongside the lockout-recovery story (operator
 * disables MFA on a stuck user, audit-logged).
 */
final class MfaActionHandlers {

    public const ACTION_REGENERATE = 'tt_mfa_regenerate_backup_codes';
    public const ACTION_DISABLE    = 'tt_mfa_disable';

    public static function init(): void {
        add_action( 'admin_post_' . self::ACTION_REGENERATE, [ self::class, 'handleRegenerate' ] );
        add_action( 'admin_post_' . self::ACTION_DISABLE,    [ self::class, 'handleDisable' ] );
    }

    /**
     * Generate a fresh batch of backup codes and persist the hashes.
     * Plaintext codes are stored in a 5-minute transient keyed by the
     * user id so the destination tab can render them once and then
     * delete the transient.
     */
    public static function handleRegenerate(): void {
        if ( ! is_user_logged_in() ) {
            wp_die( esc_html__( 'You must be logged in to manage your MFA settings.', 'talenttrack' ) );
        }
        check_admin_referer( self::ACTION_REGENERATE, 'tt_mfa_nonce' );

        $user_id = get_current_user_id();
        $repo    = new MfaSecretsRepository();
        if ( ! $repo->isEnrolled( $user_id ) ) {
            self::redirectBack( 'mfa_not_enrolled' );
        }

        $generated = BackupCodesService::generate();
        $repo->updateBackupCodes( $user_id, $generated['storage'] );

        // Stash the plaintext for one-shot display on the next render.
        set_transient(
            'tt_mfa_fresh_backup_codes_' . $user_id,
            $generated['plaintext'],
            5 * MINUTE_IN_SECONDS
        );

        self::redirectBack( 'mfa_backup_regenerated' );
    }

    /**
     * Delete the user's `tt_user_mfa` row entirely. The user is asked
     * to confirm via a `confirm=yes` POST field before we proceed —
     * this is irreversible (subject to re-enrolling from scratch).
     */
    public static function handleDisable(): void {
        if ( ! is_user_logged_in() ) {
            wp_die( esc_html__( 'You must be logged in to manage your MFA settings.', 'talenttrack' ) );
        }
        check_admin_referer( self::ACTION_DISABLE, 'tt_mfa_nonce' );

        $confirmed = isset( $_POST['confirm'] ) && (string) $_POST['confirm'] === 'yes';
        if ( ! $confirmed ) {
            self::redirectBack( 'mfa_disable_unconfirmed' );
        }

        $user_id = get_current_user_id();
        $repo    = new MfaSecretsRepository();
        $repo->disable( $user_id );

        self::redirectBack( 'mfa_disabled' );
    }

    private static function redirectBack( string $msg ): void {
        wp_safe_redirect( add_query_arg(
            [ 'page' => 'tt-account', 'tab' => 'mfa', 'tt_msg' => $msg ],
            admin_url( 'admin.php' )
        ) );
        exit;
    }
}
