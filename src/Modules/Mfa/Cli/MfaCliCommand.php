<?php
namespace TT\Modules\Mfa\Cli;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Mfa\Auth\MfaLoginGuard;
use TT\Modules\Mfa\MfaSecretsRepository;
use TT\Modules\Mfa\Settings\MfaSettings;

/**
 * MfaCliCommand (#1392) — `wp tt mfa` recovery + diagnostics group.
 *
 * The break-glass companion to the `TT_MFA_DISABLE` wp-config constant:
 * where the constant suppresses enforcement without touching state,
 * these commands MUTATE state for operators comfortable with wp-cli.
 *
 *   wp tt mfa status        — gated personas + per-user enrolled/locked state
 *   wp tt mfa disable       — set the persona policy to [] (explicit off)
 *                             + flush every live pending/must-enroll
 *                             transient so the next request reaches admin
 *   wp tt mfa reset <user>  — wipe ONE user's enrollment row (they
 *                             re-enroll on next login); policy untouched
 *
 * Registered behind `class_exists( 'WP_CLI' )` in MfaModule — a no-op
 * on web requests.
 */
final class MfaCliCommand {

    /**
     * Show gated personas and per-user enrollment / lockout state.
     *
     * ## EXAMPLES
     *
     *     wp tt mfa status
     *
     * @param array<int,string>    $args
     * @param array<string,mixed>  $assoc_args
     */
    public function status( $args, $assoc_args ): void {
        $settings = new MfaSettings();
        $personas = $settings->requiredPersonas();

        \WP_CLI::line( 'Kill-switch (TT_MFA_DISABLE): ' . ( MfaLoginGuard::killSwitchActive() ? 'ACTIVE — enforcement suppressed' : 'not set' ) );
        \WP_CLI::line( 'Gated personas: ' . ( $personas ? implode( ', ', $personas ) : '(none — enforcement off)' ) );

        $rows = ( new MfaSecretsRepository() )->listEnrollments();
        if ( ! $rows ) {
            \WP_CLI::line( 'No enrollment rows.' );
            return;
        }
        $table = [];
        foreach ( $rows as $r ) {
            $user   = get_userdata( $r['wp_user_id'] );
            $locked = $r['locked_until'] !== null && strtotime( $r['locked_until'] ) > time();
            $table[] = [
                'user_id'         => $r['wp_user_id'],
                'login'           => $user ? $user->user_login : '(missing user)',
                'enrolled'        => $r['enrolled_at'] !== null ? $r['enrolled_at'] : 'pending',
                'locked_until'    => $locked ? (string) $r['locked_until'] : '-',
                'failed_attempts' => $r['failed_attempts'],
            ];
        }
        \WP_CLI\Utils\format_items( 'table', $table, [ 'user_id', 'login', 'enrolled', 'locked_until', 'failed_attempts' ] );
    }

    /**
     * Turn MFA enforcement off install-wide: persona policy becomes []
     * (the explicit-off value) and every live pending / must-enroll
     * transient is flushed so the next request reaches admin.
     *
     * Enrollment rows are NOT touched — re-enabling the policy from the
     * MFA tab restores enforcement with everyone's enrollment intact.
     *
     * ## EXAMPLES
     *
     *     wp tt mfa disable
     *
     * @param array<int,string>    $args
     * @param array<string,mixed>  $assoc_args
     */
    public function disable( $args, $assoc_args ): void {
        ( new MfaSettings() )->setRequiredPersonas( [] );

        // Flush live challenge transients for every user with a row AND
        // every user currently mid-challenge (transient without a row —
        // the never-completed-enrollment case).
        global $wpdb;
        $flushed = 0;
        $option_names = $wpdb->get_col(
            "SELECT option_name FROM {$wpdb->options}
              WHERE option_name LIKE '\_transient\_tt\_mfa\_pending\_%'
                 OR option_name LIKE '\_transient\_tt\_mfa\_must\_enroll\_%'"
        );
        foreach ( (array) $option_names as $name ) {
            $key = (string) preg_replace( '/^_transient_/', '', (string) $name );
            if ( delete_transient( $key ) ) $flushed++;
        }

        \WP_CLI::success( sprintf(
            /* translators: %d: number of flushed challenge transients */
            __( 'MFA persona policy set to [] (enforcement off); %d live challenge transient(s) flushed. Enrollment rows untouched — re-enable from the MFA tab when recovered.', 'talenttrack' ),
            $flushed
        ) );
    }

    /**
     * Wipe one user's enrollment row so they re-enroll on next login.
     * Install-wide policy is untouched.
     *
     * ## OPTIONS
     *
     * <user>
     * : User ID, login, or email.
     *
     * ## EXAMPLES
     *
     *     wp tt mfa reset 7
     *     wp tt mfa reset hod@academy.nl
     *
     * @param array<int,string>    $args
     * @param array<string,mixed>  $assoc_args
     */
    public function reset( $args, $assoc_args ): void {
        $ref  = (string) ( $args[0] ?? '' );
        $user = is_numeric( $ref ) ? get_userdata( (int) $ref ) : ( get_user_by( 'login', $ref ) ?: get_user_by( 'email', $ref ) );
        if ( ! $user instanceof \WP_User ) {
            \WP_CLI::error( sprintf( /* translators: %s: the user reference the operator typed */ __( 'No user found for "%s" (tried id, login, email).', 'talenttrack' ), $ref ) );
            return;
        }
        $repo = new MfaSecretsRepository();
        if ( $repo->findByUserId( (int) $user->ID ) === null ) {
            \WP_CLI::warning( sprintf(
                /* translators: 1: user login, 2: user id */
                __( '%1$s (#%2$d) has no MFA enrollment row — nothing to reset.', 'talenttrack' ),
                $user->user_login,
                (int) $user->ID
            ) );
        } else {
            $repo->disable( (int) $user->ID );
            \WP_CLI::success( sprintf(
                /* translators: 1: user login, 2: user id */
                __( 'Enrollment wiped for %1$s (#%2$d) — they re-enroll on next login.', 'talenttrack' ),
                $user->user_login,
                (int) $user->ID
            ) );
        }
        // Clear any live challenge so the user isn't stuck mid-redirect.
        MfaLoginGuard::clearPending( (int) $user->ID );
    }
}
