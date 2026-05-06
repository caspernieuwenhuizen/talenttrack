<?php
namespace TT\Modules\Mfa\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Mfa\Audit\MfaAuditEvents;
use TT\Modules\Mfa\Auth\RememberDeviceCookie;
use TT\Modules\Mfa\Domain\BackupCodesService;
use TT\Modules\Mfa\MfaSecretsRepository;
use TT\Modules\Mfa\Settings\MfaSettings;

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

    public const ACTION_REGENERATE       = 'tt_mfa_regenerate_backup_codes';
    public const ACTION_DISABLE          = 'tt_mfa_disable';
    public const ACTION_SAVE_PERSONAS    = 'tt_mfa_save_required_personas';
    public const ACTION_OPERATOR_DISABLE = 'tt_mfa_operator_disable_user';

    public static function init(): void {
        add_action( 'admin_post_' . self::ACTION_REGENERATE,       [ self::class, 'handleRegenerate' ] );
        add_action( 'admin_post_' . self::ACTION_DISABLE,          [ self::class, 'handleDisable' ] );
        add_action( 'admin_post_' . self::ACTION_SAVE_PERSONAS,    [ self::class, 'handleSavePersonas' ] );
        add_action( 'admin_post_' . self::ACTION_OPERATOR_DISABLE, [ self::class, 'handleOperatorDisable' ] );
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
        MfaAuditEvents::record( MfaAuditEvents::BACKUP_CODES_REGENERATED, $user_id );

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
        RememberDeviceCookie::clear();
        MfaAuditEvents::record( MfaAuditEvents::DISABLED, $user_id, [ 'self_initiated' => true ] );

        self::redirectBack( 'mfa_disabled' );
    }

    /**
     * Operator-only — save the per-club `mfa_required_personas` setting.
     * Form is rendered inside the AccountPage MFA tab's operator
     * sub-section; submits with the operator's own `tt_edit_settings`
     * cap.
     */
    public static function handleSavePersonas(): void {
        if ( ! current_user_can( 'tt_edit_settings' ) ) {
            wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        }
        check_admin_referer( self::ACTION_SAVE_PERSONAS, 'tt_mfa_nonce' );

        $raw_personas = isset( $_POST['personas'] ) && is_array( $_POST['personas'] )
            ? array_map( 'sanitize_key', wp_unslash( $_POST['personas'] ) )
            : [];
        $valid_keys = array_keys( MfaSettings::operatorSelectablePersonas() );
        $clean      = array_values( array_intersect( $valid_keys, $raw_personas ) );

        $settings = new MfaSettings();
        $before   = $settings->requiredPersonas();
        $settings->setRequiredPersonas( $clean );

        MfaAuditEvents::record(
            MfaAuditEvents::REQUIRED_PERSONAS_CHANGED,
            get_current_user_id(),
            [ 'before' => $before, 'after' => $clean ]
        );

        wp_safe_redirect( add_query_arg(
            [ 'page' => 'tt-account', 'tab' => 'mfa', 'tt_msg' => 'mfa_personas_saved' ],
            admin_url( 'admin.php' )
        ) );
        exit;
    }

    /**
     * Operator-only — disable MFA on another user's behalf. Used to
     * recover a user who's lost both their authenticator app AND their
     * backup codes (the only path that doesn't involve manual database
     * editing).
     *
     * Audit-logged with the target user as the subject and the operator
     * captured as the actor by `AuditService::record()` via
     * `get_current_user_id()`.
     */
    public static function handleOperatorDisable(): void {
        if ( ! current_user_can( 'tt_edit_settings' ) ) {
            wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        }
        check_admin_referer( self::ACTION_OPERATOR_DISABLE, 'tt_mfa_nonce' );

        $target_id = isset( $_POST['target_user_id'] ) ? (int) $_POST['target_user_id'] : 0;
        $confirmed = isset( $_POST['confirm'] ) && (string) $_POST['confirm'] === 'yes';
        if ( $target_id <= 0 || ! $confirmed ) {
            wp_safe_redirect( add_query_arg(
                [ 'page' => 'tt-account', 'tab' => 'mfa', 'tt_msg' => 'mfa_operator_disable_invalid' ],
                admin_url( 'admin.php' )
            ) );
            exit;
        }

        $repo = new MfaSecretsRepository();
        $repo->disable( $target_id );
        MfaAuditEvents::record( MfaAuditEvents::DISABLED, $target_id, [ 'operator_initiated' => true ] );

        wp_safe_redirect( add_query_arg(
            [ 'page' => 'tt-account', 'tab' => 'mfa', 'tt_msg' => 'mfa_operator_disabled', 'target' => $target_id ],
            admin_url( 'admin.php' )
        ) );
        exit;
    }

    private static function redirectBack( string $msg ): void {
        wp_safe_redirect( add_query_arg(
            [ 'page' => 'tt-account', 'tab' => 'mfa', 'tt_msg' => $msg ],
            admin_url( 'admin.php' )
        ) );
        exit;
    }
}
