<?php
namespace TT\Modules\Mfa\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\License\Admin\AccountPage;
use TT\Modules\Mfa\Admin\MfaActionHandlers;
use TT\Modules\Mfa\Domain\BackupCodesService;
use TT\Modules\Mfa\MfaSecretsRepository;
use TT\Modules\Mfa\Wizards\MfaEnrollmentWizard;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;
use TT\Shared\Frontend\Components\RecordLink;
use TT\Shared\Frontend\FrontendViewBase;
use TT\Shared\Wizards\WizardEntryPoint;

/**
 * FrontendTwoFactorView (#3134) — a user's own two-factor enrolment,
 * on the frontend.
 *
 * ## Half a tab, on purpose
 *
 * The wp-admin MFA tab groups two things that are not the same feature:
 * *"enrol my own second factor"*, which every user needs and most users
 * have no other reason to open wp-admin for, and *"remove somebody
 * else's"*, which is an operator recovery action used when a person is
 * locked out. Only the first half is here.
 *
 * The operator half — per-club persona enforcement and the
 * lockout-recovery reset — stays on `AccountPage`, along with the
 * phone-home diagnostic. That is the #3134 decision of 2026-08-30, and
 * the reason is that stripping another person's second factor deserves
 * its own threat model rather than arriving as a side effect of a port.
 * It is also consistent with the rule the earlier ports settled on:
 * wp-admin keeps the surfaces you need *when something is wrong*, and an
 * MFA lockout is exactly that.
 *
 * ## Same handlers, not a fork
 *
 * Regenerate and disable post to the existing
 * `MfaActionHandlers` endpoints. Those already require a signed-in user
 * and operate on that user's own row only, so there is no new write path
 * and no second copy of the audit logging. The forms carry
 * `tt_return=frontend` so the handler sends the user back to whichever
 * surface they submitted from.
 *
 * ## §6 Save + Cancel
 *
 * Exemption — no record is being created or edited. Regenerate and
 * disable are single-purpose actions, and disable already carries its own
 * confirmation checkbox.
 */
class FrontendTwoFactorView extends FrontendViewBase {

    public const SLUG = 'two-factor';

    public static function init(): void {
        // No handlers of its own — see the class docblock. The view is
        // registered here only so the module has one place that knows
        // the surface exists.
    }

    /**
     * Where to send a user to manage their own second factor.
     *
     * Frontend when a page hosts the dashboard shortcode, wp-admin's MFA
     * tab otherwise — the same guard `FrontendPlanView::url()` uses, and
     * for the same reason: with no dashboard page,
     * `RecordLink::dashboardUrl()` resolves to the current request.
     *
     * @param array<string,string> $args extra query arguments
     */
    public static function url( array $args = [] ): string {
        if ( RecordLink::dashboardPageId() > 0 ) {
            return add_query_arg(
                array_merge( [ 'tt_view' => self::SLUG ], $args ),
                RecordLink::dashboardUrl()
            );
        }

        return add_query_arg(
            array_merge( [ 'page' => AccountPage::SLUG, 'tab' => AccountPage::TAB_MFA ], $args ),
            admin_url( 'admin.php' )
        );
    }

    public static function render( int $user_id, bool $is_admin ): void {
        if ( $user_id <= 0 ) {
            FrontendBreadcrumbs::fromDashboard( __( 'Not authorized', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'Sign in to manage your two-factor authentication.', 'talenttrack' ) . '</p>';
            return;
        }

        FrontendBreadcrumbs::fromDashboard( __( 'Two-factor authentication', 'talenttrack' ) );

        self::enqueueAssets();
        wp_enqueue_style(
            'tt-frontend-account',
            TT_PLUGIN_URL . 'assets/css/frontend-account.css',
            [ 'tt-public' ],
            TT_VERSION
        );

        self::renderHeader( __( 'Two-factor authentication', 'talenttrack' ) );

        echo '<p class="tt-muted tt-account-intro">'
            . esc_html__( 'A second step at sign-in: a 6-digit code from an authenticator app on your phone, plus 10 single-use backup codes for the day you lose it.', 'talenttrack' )
            . '</p>';

        $msg = isset( $_GET['tt_msg'] ) ? sanitize_key( (string) wp_unslash( (string) $_GET['tt_msg'] ) ) : '';
        self::renderFlashFor( $msg, $user_id );

        $repo    = new MfaSecretsRepository();
        $row     = $repo->findByUserId( $user_id );
        $enrolled = $row !== null && ! empty( $row['enrolled_at'] );

        if ( $enrolled ) {
            self::renderEnrolled( (array) ( $row['backup_codes'] ?? [] ) );
        } else {
            self::renderNotEnrolled();
        }
    }

    /** One-shot messages, including the only place fresh backup codes are shown. */
    private static function renderFlashFor( string $msg, int $user_id ): void {
        if ( $msg === 'mfa_enrolled' ) {
            echo '<p class="tt-notice tt-notice-success">'
                . esc_html__( 'Two-factor is now active. From your next sign-in TalentTrack asks for a code from your authenticator app.', 'talenttrack' )
                . '</p>';
            return;
        }

        if ( $msg === 'mfa_disabled' ) {
            echo '<p class="tt-notice tt-notice-warning">'
                . esc_html__( 'Two-factor is now off. You can set it up again at any time from this page.', 'talenttrack' )
                . '</p>';
            return;
        }

        if ( $msg === 'mfa_disable_unconfirmed' ) {
            echo '<p class="tt-notice">'
                . esc_html__( 'Two-factor was left on — the confirmation box was not ticked.', 'talenttrack' )
                . '</p>';
            return;
        }

        if ( $msg === 'mfa_not_enrolled' ) {
            echo '<p class="tt-notice">'
                . esc_html__( 'There was nothing to change — two-factor is not set up on your account.', 'talenttrack' )
                . '</p>';
            return;
        }

        if ( $msg !== 'mfa_backup_regenerated' ) return;

        // The plaintext codes live in a 5-minute one-shot transient that
        // the handler wrote. Read it and delete it in the same breath, so
        // a refresh cannot show them twice.
        $key   = 'tt_mfa_fresh_backup_codes_' . $user_id;
        $fresh = get_transient( $key );
        delete_transient( $key );
        if ( ! is_array( $fresh ) || $fresh === [] ) return;

        echo '<section class="tt-panel tt-account-panel tt-account-codes">';
        echo '<h2 class="tt-panel-title">' . esc_html__( 'Your new backup codes', 'talenttrack' ) . '</h2>';
        echo '<p>' . esc_html__( 'Save these somewhere safe now. They are shown once and never again, and the previous set stopped working the moment these were made.', 'talenttrack' ) . '</p>';
        echo '<ol class="tt-account-codes__list">';
        foreach ( $fresh as $code ) {
            echo '<li><code>' . esc_html( (string) $code ) . '</code></li>';
        }
        echo '</ol>';
        echo '</section>';
    }

    /** @param array<int,mixed> $backup_codes */
    private static function renderEnrolled( array $backup_codes ): void {
        $left  = BackupCodesService::unusedCount( $backup_codes );
        $total = BackupCodesService::CODE_COUNT;

        echo '<section class="tt-panel tt-account-panel">';
        echo '<h2 class="tt-panel-title">' . esc_html__( 'Two-factor is on', 'talenttrack' ) . '</h2>';
        echo '<p>' . sprintf(
            /* translators: 1: number of unused backup codes left, 2: total number of backup codes issued */
            esc_html__( 'Backup codes remaining: %1$d of %2$d.', 'talenttrack' ),
            (int) $left,
            (int) $total
        ) . '</p>';
        if ( $left <= 3 ) {
            echo '<p class="tt-notice tt-notice-warning">'
                . esc_html__( 'Running low — make a fresh set before you run out, or a lost phone means asking an administrator to reset you.', 'talenttrack' )
                . '</p>';
        }
        echo '</section>';

        echo '<section class="tt-panel tt-account-panel">';
        echo '<h2 class="tt-panel-title">' . esc_html__( 'Manage', 'talenttrack' ) . '</h2>';

        echo '<form class="tt-account-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( MfaActionHandlers::ACTION_REGENERATE, 'tt_mfa_nonce' );
        echo '<input type="hidden" name="action" value="' . esc_attr( MfaActionHandlers::ACTION_REGENERATE ) . '" />';
        echo '<input type="hidden" name="tt_return" value="frontend" />';
        echo '<p class="tt-muted">' . esc_html__( 'A fresh set of 10 codes. The old set stops working straight away.', 'talenttrack' ) . '</p>';
        echo '<button type="submit" class="tt-btn tt-btn-secondary">'
            . esc_html__( 'Make new backup codes', 'talenttrack' ) . '</button>';
        echo '</form>';

        echo '<details class="tt-account-danger">';
        echo '<summary>' . esc_html__( 'Turn two-factor off', 'talenttrack' ) . '</summary>';
        echo '<form class="tt-account-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( MfaActionHandlers::ACTION_DISABLE, 'tt_mfa_nonce' );
        echo '<input type="hidden" name="action" value="' . esc_attr( MfaActionHandlers::ACTION_DISABLE ) . '" />';
        echo '<input type="hidden" name="tt_return" value="frontend" />';
        echo '<p>' . esc_html__( 'This removes your secret and your backup codes. From your next sign-in, your password alone gets you in.', 'talenttrack' ) . '</p>';
        echo '<label class="tt-account-confirm">';
        echo '<input type="checkbox" name="confirm" value="yes" required /> ';
        echo '<span>' . esc_html__( 'I understand this makes my account easier to break into.', 'talenttrack' ) . '</span>';
        echo '</label>';
        echo '<button type="submit" class="tt-btn tt-btn-danger">'
            . esc_html__( 'Turn two-factor off', 'talenttrack' ) . '</button>';
        echo '</form>';
        echo '</details>';

        echo '</section>';
    }

    private static function renderNotEnrolled(): void {
        echo '<section class="tt-panel tt-account-panel">';
        echo '<h2 class="tt-panel-title">' . esc_html__( 'Two-factor is off', 'talenttrack' ) . '</h2>';
        echo '<p>' . esc_html__( 'Setting it up takes about two minutes: scan a QR code with an authenticator app, type the first code back to prove it works, and write down the 10 backup codes it gives you.', 'talenttrack' ) . '</p>';

        $wizard_url = WizardEntryPoint::urlFor( MfaEnrollmentWizard::SLUG, '' );
        if ( $wizard_url !== '' ) {
            echo '<p><a class="tt-btn tt-btn-primary" href="' . esc_url( $wizard_url ) . '">'
                . esc_html__( 'Set up two-factor', 'talenttrack' ) . '</a></p>';
        } else {
            echo '<p class="tt-notice">'
                . esc_html__( 'Setup is unavailable on this install because wizards are switched off in the configuration.', 'talenttrack' )
                . '</p>';
        }

        echo '</section>';
    }
}
