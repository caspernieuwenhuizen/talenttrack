<?php
namespace TT\Modules\License\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\License\DevOverride;
use TT\Modules\License\FeatureMap;
use TT\Modules\License\FreemiusAdapter;
use TT\Modules\License\FreeTierCaps;
use TT\Modules\License\LicenseGate;
use TT\Modules\License\TrialState;

/**
 * AccountPage — `TalentTrack → Account` wp-admin page.
 *
 * Two tabs:
 *   - **Account** (operator-only, `tt_edit_settings`): tier, trial /
 *     grace state, usage vs caps, trial CTAs, phone-home diagnostics.
 *   - **Plan & restrictions** (everyone, `read`): the same caps table
 *     plus a Free / Standard / Pro feature matrix. Replaces the former
 *     standalone `PlanOverviewPage` so non-operators still get a clear
 *     "what's locked" view without a separate menu entry.
 *
 * The page itself only requires `read` — non-operators see only the
 * Plan tab; operators see both, defaulting to Account.
 */
class AccountPage {

    public const SLUG = 'tt-account';
    public const CAP  = 'tt_edit_settings';

    /** Tab the operator-only billing controls live on. */
    public const TAB_ACCOUNT = 'account';

    /** Tab the read-only plan / caps / feature-matrix view lives on. */
    public const TAB_PLAN    = 'plan';

    /** #0086 Workstream B Child 1 — multi-factor authentication. Open to every logged-in user (each user manages their own MFA). */
    public const TAB_MFA     = 'mfa';

    public static function init(): void {
        add_action( 'admin_post_tt_license_start_trial', [ self::class, 'handleStartTrial' ] );
        add_action( 'admin_post_tt_license_reset_trial', [ self::class, 'handleResetTrial' ] );
        // v3.72.3 — manual phone-home trigger so operators can verify
        // that an install can reach the Admin Center receiver. Useful
        // when one install is silent and
        // another from the same code base phones home fine.
        add_action( 'admin_post_tt_phone_home_now', [ self::class, 'handlePhoneHomeNow' ] );
    }

    /**
     * v3.72.3 — fire a single phone-home payload synchronously and
     * surface the receiver's response. Wraps `Sender::sendDiagnostic`
     * so the operator sees endpoint / HTTP code / WP_Error message /
     * duration without grepping logs.
     */
    public static function handlePhoneHomeNow(): void {
        if ( ! current_user_can( self::CAP ) ) wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        check_admin_referer( 'tt_phone_home_now', 'tt_nonce' );

        $result = \TT\Modules\AdminCenterClient\Sender::sendDiagnostic( \TT\Modules\AdminCenterClient\PayloadBuilder::TRIGGER_DAILY );
        set_transient( 'tt_admin_center_last_diagnostic_' . get_current_user_id(), $result, 5 * MINUTE_IN_SECONDS );

        wp_safe_redirect( add_query_arg( [ 'page' => self::SLUG, 'tab' => self::TAB_ACCOUNT, 'tt_msg' => 'phone_home_done' ], admin_url( 'admin.php' ) ) );
        exit;
    }

    public static function render(): void {
        if ( ! current_user_can( 'read' ) ) {
            wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        }

        $is_operator = current_user_can( self::CAP );
        $requested   = isset( $_GET['tab'] ) ? sanitize_key( (string) $_GET['tab'] ) : '';

        // MFA is the user-facing tab and open to every logged-in user
        // (each user manages their own enrollment). Plan stays read-only
        // for non-operators; Account stays operator-only.
        $allowed = [ self::TAB_MFA, self::TAB_PLAN ];
        if ( $is_operator ) $allowed[] = self::TAB_ACCOUNT;

        if ( in_array( $requested, $allowed, true ) ) {
            $tab = $requested;
        } else {
            $tab = $is_operator ? self::TAB_ACCOUNT : self::TAB_PLAN;
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'TalentTrack — Account', 'talenttrack' ) . '</h1>';

        self::renderTabNav( $tab, $is_operator );

        if ( $tab === self::TAB_PLAN ) {
            self::renderPlanTab();
        } elseif ( $tab === self::TAB_MFA ) {
            self::renderMfaTab( $is_operator );
        } else {
            self::renderAccountTab();
        }

        echo '</div>';
    }

    private static function renderTabNav( string $current, bool $is_operator ): void {
        $base = admin_url( 'admin.php?page=' . self::SLUG );
        $tabs = [];
        if ( $is_operator ) {
            $tabs[ self::TAB_ACCOUNT ] = __( 'Account', 'talenttrack' );
        }
        $tabs[ self::TAB_PLAN ] = __( 'Plan & restrictions', 'talenttrack' );
        $tabs[ self::TAB_MFA ]  = __( 'MFA',                 'talenttrack' );
        echo '<h2 class="nav-tab-wrapper">';
        foreach ( $tabs as $slug => $label ) {
            $url  = add_query_arg( 'tab', $slug, $base );
            $cls  = 'nav-tab' . ( $current === $slug ? ' nav-tab-active' : '' );
            echo '<a href="' . esc_url( $url ) . '" class="' . esc_attr( $cls ) . '">' . esc_html( $label ) . '</a>';
        }
        echo '</h2>';
    }

    /**
     * #0086 Workstream B Child 1 — MFA tab.
     *
     * Sprint 2 wires up the actual enrollment + recovery surface:
     *   - Not-enrolled path: "Start enrollment" button → 4-step wizard.
     *   - Enrolled path: backup-codes counter + "Regenerate" + "Disable" actions.
     *   - One-shot messages for `mfa_enrolled` (after wizard), `mfa_backup_regenerated`
     *     (with the new codes shown once), `mfa_disabled`.
     *
     * Sprint 3 will add: per-club `require_mfa_for_personas` setting
     * (operator-only sub-section), "remembered devices" list with
     * revoke buttons, recent verification history.
     */
    private static function renderMfaTab( bool $is_operator = false ): void {
        $user_id = get_current_user_id();
        if ( $user_id <= 0 ) {
            echo '<p>' . esc_html__( 'You must be logged in to manage your MFA settings.', 'talenttrack' ) . '</p>';
            return;
        }

        $repo                = new \TT\Modules\Mfa\MfaSecretsRepository();
        $row                 = $repo->findByUserId( $user_id );
        $is_enrolled         = $row !== null && ! empty( $row['enrolled_at'] );
        $unused_backup_count = $is_enrolled
            ? \TT\Modules\Mfa\Domain\BackupCodesService::unusedCount( (array) ( $row['backup_codes'] ?? [] ) )
            : 0;

        $tt_msg = isset( $_GET['tt_msg'] ) ? sanitize_key( (string) wp_unslash( (string) $_GET['tt_msg'] ) ) : '';

        ?>
        <h2><?php esc_html_e( 'Two-factor authentication', 'talenttrack' ); ?></h2>
        <p style="max-width:760px;">
            <?php esc_html_e( 'A second factor at login — a 6-digit code from your authenticator app, plus 10 single-use backup codes for the case where you lose your device. Building it natively into TalentTrack means it travels into the future SaaS migration unchanged.', 'talenttrack' ); ?>
        </p>

        <?php
        // One-shot messages.
        if ( $tt_msg === 'mfa_enrolled' ) :
            ?>
            <div class="notice notice-success is-dismissible" style="padding:12px 16px;">
                <p style="margin:0;"><strong><?php esc_html_e( 'MFA is now active on your account.', 'talenttrack' ); ?></strong> <?php esc_html_e( "From your next sign-in onward TalentTrack will ask for a 6-digit code from your authenticator app.", 'talenttrack' ); ?></p>
            </div>
            <?php
        elseif ( $tt_msg === 'mfa_disabled' ) :
            ?>
            <div class="notice notice-warning is-dismissible" style="padding:12px 16px;">
                <p style="margin:0;"><strong><?php esc_html_e( 'MFA is now off.', 'talenttrack' ); ?></strong> <?php esc_html_e( 'Re-enroll any time from this tab.', 'talenttrack' ); ?></p>
            </div>
            <?php
        elseif ( $tt_msg === 'mfa_disable_unconfirmed' ) :
            ?>
            <div class="notice notice-info is-dismissible" style="padding:12px 16px;">
                <p style="margin:0;"><?php esc_html_e( 'MFA was not turned off — the confirmation checkbox was not ticked.', 'talenttrack' ); ?></p>
            </div>
            <?php
        elseif ( $tt_msg === 'mfa_backup_regenerated' ) :
            // Pull the freshly-generated plaintext from the one-shot transient
            // and delete it immediately so it's never displayed twice.
            $fresh_key = 'tt_mfa_fresh_backup_codes_' . $user_id;
            $fresh     = get_transient( $fresh_key );
            delete_transient( $fresh_key );
            if ( is_array( $fresh ) && ! empty( $fresh ) ) :
                ?>
                <div class="notice notice-success" style="padding:16px; max-width:760px;">
                    <p style="margin:0 0 8px;"><strong><?php esc_html_e( 'Here are your new backup codes.', 'talenttrack' ); ?></strong> <?php esc_html_e( "Save them somewhere safe — you won't see them again on this page after you leave.", 'talenttrack' ); ?></p>
                    <ol style="font-family:monospace; font-size:14px; line-height:1.7; columns:2; column-gap:32px; margin:8px 0 0; padding-left:24px;">
                        <?php foreach ( $fresh as $code ) : ?>
                            <li style="break-inside:avoid;"><?php echo esc_html( (string) $code ); ?></li>
                        <?php endforeach; ?>
                    </ol>
                    <p style="margin:12px 0 0;">
                        <button type="button" class="button" onclick="navigator.clipboard?.writeText(<?php echo esc_attr( wp_json_encode( implode( "\n", $fresh ) ) ); ?>)"><?php esc_html_e( 'Copy all to clipboard', 'talenttrack' ); ?></button>
                        <button type="button" class="button" onclick="window.print()"><?php esc_html_e( 'Print', 'talenttrack' ); ?></button>
                    </p>
                </div>
                <?php
            endif;
        endif;

        if ( $is_enrolled ) :
            ?>
            <div class="notice notice-info" style="padding:16px; max-width:760px;">
                <p style="margin:0 0 8px;">
                    <strong><?php esc_html_e( 'You are enrolled in MFA.', 'talenttrack' ); ?></strong>
                </p>
                <p style="margin:0; color:#5b6e75;">
                    <?php
                    printf(
                        /* translators: 1: number of unused backup codes left, 2: total number of backup codes generated */
                        esc_html__( 'Backup codes remaining: %1$d of %2$d.', 'talenttrack' ),
                        (int) $unused_backup_count,
                        (int) \TT\Modules\Mfa\Domain\BackupCodesService::CODE_COUNT
                    );
                    if ( $unused_backup_count <= 3 ) {
                        echo ' <strong style="color:#b32d2e;">'
                            . esc_html__( 'Running low — regenerate now.', 'talenttrack' )
                            . '</strong>';
                    }
                    ?>
                </p>
            </div>

            <h3 style="margin-top:32px;"><?php esc_html_e( 'Manage', 'talenttrack' ); ?></h3>
            <p style="max-width:760px;"><?php esc_html_e( 'Generate a fresh batch of 10 backup codes (the old set stops working immediately), or turn MFA off entirely.', 'talenttrack' ); ?></p>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block; margin:0 12px 12px 0;">
                <?php wp_nonce_field( \TT\Modules\Mfa\Admin\MfaActionHandlers::ACTION_REGENERATE, 'tt_mfa_nonce' ); ?>
                <input type="hidden" name="action" value="<?php echo esc_attr( \TT\Modules\Mfa\Admin\MfaActionHandlers::ACTION_REGENERATE ); ?>">
                <button type="submit" class="button"><?php esc_html_e( 'Regenerate backup codes', 'talenttrack' ); ?></button>
            </form>

            <details style="max-width:760px; margin-top:12px;">
                <summary style="cursor:pointer; color:#b32d2e; font-weight:600;"><?php esc_html_e( 'Turn MFA off', 'talenttrack' ); ?></summary>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:12px; padding:16px; background:#fff8f8; border:1px solid #f0c5c5;">
                    <?php wp_nonce_field( \TT\Modules\Mfa\Admin\MfaActionHandlers::ACTION_DISABLE, 'tt_mfa_nonce' ); ?>
                    <input type="hidden" name="action" value="<?php echo esc_attr( \TT\Modules\Mfa\Admin\MfaActionHandlers::ACTION_DISABLE ); ?>">
                    <p style="margin:0 0 12px;"><?php esc_html_e( 'Turning MFA off removes your secret and your backup codes. From your next sign-in onward, TalentTrack will accept just your password again.', 'talenttrack' ); ?></p>
                    <label style="display:flex; gap:8px; align-items:flex-start; cursor:pointer; margin-bottom:12px;">
                        <input type="checkbox" name="confirm" value="yes" required style="margin-top:4px;">
                        <span><?php esc_html_e( 'I understand that turning MFA off weakens the security of my TalentTrack account.', 'talenttrack' ); ?></span>
                    </label>
                    <button type="submit" class="button button-secondary" style="color:#b32d2e; border-color:#b32d2e;"><?php esc_html_e( 'Turn MFA off', 'talenttrack' ); ?></button>
                </form>
            </details>

            <p style="margin-top:32px; max-width:760px;"><?php esc_html_e( 'Lost your phone? Use a backup code at the next sign-in, then come back here and regenerate the set.', 'talenttrack' ); ?></p>

            <?php
        else :
            $wizard_url = \TT\Shared\Wizards\WizardEntryPoint::urlFor(
                \TT\Modules\Mfa\Wizards\MfaEnrollmentWizard::SLUG,
                ''
            );
            ?>
            <div class="notice notice-info" style="padding:16px; max-width:760px;">
                <p style="margin:0 0 8px;"><strong><?php esc_html_e( 'You are not yet enrolled in MFA.', 'talenttrack' ); ?></strong></p>
                <p style="margin:0; color:#5b6e75;"><?php esc_html_e( 'Enrollment is a 4-step wizard: a brief intro, a QR code your authenticator app scans, a quick verification of your first code, and your 10 backup codes shown once. Takes about 2 minutes.', 'talenttrack' ); ?></p>
            </div>

            <p style="margin-top:24px;">
                <?php if ( $wizard_url !== '' ) : ?>
                    <a class="button button-primary button-hero" href="<?php echo esc_url( $wizard_url ); ?>">
                        <?php esc_html_e( 'Start enrollment', 'talenttrack' ); ?>
                    </a>
                <?php else : ?>
                    <em><?php esc_html_e( 'Enrollment is unavailable on this install — wizards are disabled in the configuration.', 'talenttrack' ); ?></em>
                <?php endif; ?>
            </p>
            <?php
        endif;
        ?>

        <?php if ( $is_operator ) : self::renderOperatorMfaSection( $tt_msg ); endif; ?>

        <p style="max-width:760px; margin-top:32px;">
            <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=tt-docs&topic=security-operator-guide' ) ); ?>">
                <?php esc_html_e( 'Read the security operator guide', 'talenttrack' ); ?>
            </a>
        </p>
        <?php
    }

    /**
     * #0086 Workstream B Child 1 sprint 3 — operator-only sub-section on
     * the MFA tab. Two controls:
     *   - `require_mfa_for_personas` — multi-checkbox; submit writes
     *     the per-club setting via `MfaActionHandlers::handleSavePersonas`.
     *   - Operator-on-behalf-of-user disable — the lockout-recovery flow.
     *     Operator types a username + ticks confirm, MFA is wiped on
     *     that user's `tt_user_mfa` row.
     */
    private static function renderOperatorMfaSection( string $tt_msg ): void {
        $settings = new \TT\Modules\Mfa\Settings\MfaSettings();
        $required = $settings->requiredPersonas();
        $personas = \TT\Modules\Mfa\Settings\MfaSettings::operatorSelectablePersonas();

        echo '<hr style="margin:40px 0 24px;">';
        echo '<h2>' . esc_html__( 'Per-club enforcement (operator only)', 'talenttrack' ) . '</h2>';
        echo '<p style="max-width:760px;">'
            . esc_html__( 'Pick which personas must enroll in MFA. Users whose persona is on this list will see the MFA prompt at every login (skipped only when they have a valid 30-day "remember this device" cookie). Un-enrolled users in gated personas are redirected to the enrollment wizard at login until they finish.', 'talenttrack' )
            . '</p>';

        if ( $tt_msg === 'mfa_personas_saved' ) {
            echo '<div class="notice notice-success is-dismissible" style="padding:12px 16px;">'
                . '<p style="margin:0;">' . esc_html__( 'Persona enforcement saved.', 'talenttrack' ) . '</p>'
                . '</div>';
        }

        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:16px; max-width:760px; padding:16px; background:#fafafa; border:1px solid #ddd;">';
        wp_nonce_field( \TT\Modules\Mfa\Admin\MfaActionHandlers::ACTION_SAVE_PERSONAS, 'tt_mfa_nonce' );
        echo '<input type="hidden" name="action" value="' . esc_attr( \TT\Modules\Mfa\Admin\MfaActionHandlers::ACTION_SAVE_PERSONAS ) . '">';
        echo '<fieldset><legend style="font-weight:600; margin-bottom:8px;">'
            . esc_html__( 'Personas required to enroll', 'talenttrack' )
            . '</legend>';
        foreach ( $personas as $key => $label ) {
            $checked = in_array( $key, $required, true );
            echo '<label style="display:block; padding:4px 0; cursor:pointer;">';
            echo '<input type="checkbox" name="personas[]" value="' . esc_attr( $key ) . '" ' . checked( $checked, true, false ) . '> ';
            echo '<span>' . esc_html( $label ) . '</span>';
            echo '</label>';
        }
        echo '</fieldset>';
        echo '<p style="margin-top:12px;"><button type="submit" class="button button-primary">'
            . esc_html__( 'Save', 'talenttrack' )
            . '</button></p>';
        echo '</form>';

        // Operator-on-behalf-of-user disable.
        echo '<h3 style="margin-top:32px;">' . esc_html__( 'Reset MFA on another user (lockout recovery)', 'talenttrack' ) . '</h3>';
        echo '<p style="max-width:760px;">'
            . esc_html__( 'When a user has lost both their authenticator app and all their backup codes, this is the recovery path. Their MFA secret + remembered devices are wiped; they re-enroll on the next login. Audit-logged on both the actor and target side.', 'talenttrack' )
            . '</p>';

        if ( $tt_msg === 'mfa_operator_disabled' && isset( $_GET['target'] ) ) {
            $target_id = (int) $_GET['target'];
            $target    = get_user_by( 'id', $target_id );
            $target_lbl = $target ? $target->user_login : ('#' . $target_id);
            echo '<div class="notice notice-success is-dismissible" style="padding:12px 16px;">'
                . '<p style="margin:0;">' . esc_html(
                    sprintf(
                        /* translators: %s username */
                        __( 'MFA reset for %s. They will be prompted to re-enroll on their next login.', 'talenttrack' ),
                        $target_lbl
                    )
                ) . '</p>'
                . '</div>';
        } elseif ( $tt_msg === 'mfa_operator_disable_invalid' ) {
            echo '<div class="notice notice-error is-dismissible" style="padding:12px 16px;">'
                . '<p style="margin:0;">' . esc_html__( 'No reset performed — pick a user and tick the confirmation box.', 'talenttrack' ) . '</p>'
                . '</div>';
        }

        // List of currently-enrolled users (so the operator picks from a
        // dropdown rather than typing a numeric ID). Cap to first 200 by
        // username; in practice an academy has < 50 enrolled accounts.
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT m.wp_user_id, u.user_login, u.display_name
               FROM {$wpdb->prefix}tt_user_mfa m
               JOIN {$wpdb->users} u ON u.ID = m.wp_user_id
              WHERE m.club_id = %d AND m.enrolled_at IS NOT NULL
              ORDER BY u.user_login ASC
              LIMIT 200",
            \TT\Infrastructure\Tenancy\CurrentClub::id()
        ) );

        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:16px; max-width:760px; padding:16px; background:#fff8f8; border:1px solid #f0c5c5;">';
        wp_nonce_field( \TT\Modules\Mfa\Admin\MfaActionHandlers::ACTION_OPERATOR_DISABLE, 'tt_mfa_nonce' );
        echo '<input type="hidden" name="action" value="' . esc_attr( \TT\Modules\Mfa\Admin\MfaActionHandlers::ACTION_OPERATOR_DISABLE ) . '">';

        if ( empty( $rows ) ) {
            echo '<p style="margin:0;">' . esc_html__( 'Nobody is currently enrolled in MFA on this install.', 'talenttrack' ) . '</p>';
        } else {
            echo '<label style="display:block; margin-bottom:12px;">';
            echo '<span style="display:block; font-weight:600; margin-bottom:6px;">'
                . esc_html__( 'Enrolled user', 'talenttrack' )
                . '</span>';
            echo '<select name="target_user_id" required style="min-width:280px;">';
            echo '<option value="0">' . esc_html__( '— pick a user —', 'talenttrack' ) . '</option>';
            foreach ( $rows as $r ) {
                $label = sprintf( '%s (%s)', (string) $r->display_name, (string) $r->user_login );
                echo '<option value="' . (int) $r->wp_user_id . '">' . esc_html( $label ) . '</option>';
            }
            echo '</select>';
            echo '</label>';
            echo '<label style="display:flex; gap:8px; align-items:flex-start; cursor:pointer; margin-bottom:12px;">';
            echo '<input type="checkbox" name="confirm" value="yes" required style="margin-top:4px;">';
            echo '<span>' . esc_html__( "I have verified that this user is locked out and unable to use their authenticator app or any of their backup codes.", 'talenttrack' ) . '</span>';
            echo '</label>';
            echo '<button type="submit" class="button button-secondary" style="color:#b32d2e; border-color:#b32d2e;">'
                . esc_html__( 'Reset MFA on this user', 'talenttrack' )
                . '</button>';
        }
        echo '</form>';
    }

    private static function renderAccountTab(): void {
        if ( ! current_user_can( self::CAP ) ) {
            echo '<p>' . esc_html__( 'You do not have access to the operator account controls. The Plan & restrictions tab is open to everyone.', 'talenttrack' ) . '</p>';
            return;
        }

        $tier         = LicenseGate::tier();
        $eff_tier     = LicenseGate::effectiveTier();
        $in_trial     = LicenseGate::isInTrial();
        $in_grace     = LicenseGate::isInGrace();
        $trial_days   = LicenseGate::trialDaysRemaining();
        $grace_days   = LicenseGate::graceDaysRemaining();
        $configured   = FreemiusAdapter::isConfigured();
        $override     = DevOverride::active();
        $trial_data   = TrialState::read();
        $teams_used   = FreeTierCaps::currentCount( FreeTierCaps::CAP_TEAMS );
        $players_used = FreeTierCaps::currentCount( FreeTierCaps::CAP_PLAYERS );
        $teams_cap    = FreeTierCaps::teamCap();
        $players_cap  = FreeTierCaps::playerCap();
        ?>
            <?php if ( isset( $_GET['tt_msg'] ) ) :
                $msg = sanitize_text_field( wp_unslash( (string) $_GET['tt_msg'] ) );
                if ( $msg === 'cap_players' || $msg === 'cap_teams' ) :
                    echo UpgradeNudge::capHit( $msg === 'cap_teams' ? 'teams' : 'players' );
                else :
                    $messages = [
                        'trial_started' => __( 'Trial started.',          'talenttrack' ),
                        'trial_reset'   => __( 'Trial state cleared.',    'talenttrack' ),
                    ];
                    if ( isset( $messages[ $msg ] ) ) : ?>
                        <div class="notice notice-success is-dismissible"><p><?php echo esc_html( $messages[ $msg ] ); ?></p></div>
                    <?php endif;
                endif;
            endif; ?>

            <?php if ( $override !== null ) : ?>
                <div class="notice notice-warning">
                    <p><strong><?php esc_html_e( 'Developer override active.', 'talenttrack' ); ?></strong>
                       <?php
                       printf(
                           /* translators: 1: tier label, 2: relative time */
                           esc_html__( 'Tier forced to %1$s; expires in %2$s.', 'talenttrack' ),
                           '<code>' . esc_html( FeatureMap::tierLabel( $override['tier'] ) ) . '</code>',
                           esc_html( human_time_diff( time(), $override['set_at'] + DevOverride::TRANSIENT_TTL ) )
                       );
                       ?>
                    </p>
                </div>
            <?php endif; ?>

            <?php if ( ! $configured ) : ?>
                <div class="notice notice-info">
                    <p><strong><?php esc_html_e( 'Monetization not yet configured.', 'talenttrack' ); ?></strong>
                       <?php esc_html_e( 'Until the Freemius credentials are defined in wp-config.php, this install runs on the Free tier (or a trial / dev override if active). Paid plans become available once the SDK is wired up.', 'talenttrack' ); ?>
                    </p>
                </div>
            <?php endif; ?>

            <?php
            // Setup-wizard hand-off (#5). If the wizard is incomplete, show a
            // small in-page notice with a Resume button so admins finding their
            // way to Account can pick it up here too — same as on the
            // Configuration → Setup wizard tab.
            if ( class_exists( '\\TT\\Modules\\Onboarding\\OnboardingState' )
                && ! \TT\Modules\Onboarding\OnboardingState::isCompleted() ) :
                $wizard_url = admin_url( 'admin.php?page=tt-welcome' );
                ?>
                <div class="notice notice-info">
                    <p><strong><?php esc_html_e( 'Finish setting up TalentTrack.', 'talenttrack' ); ?></strong>
                       <?php esc_html_e( 'The setup wizard is still in progress — pick it up where you left off any time.', 'talenttrack' ); ?>
                       <a href="<?php echo esc_url( $wizard_url ); ?>" class="button button-small" style="margin-left:8px;">
                           <?php esc_html_e( 'Resume setup wizard', 'talenttrack' ); ?>
                       </a>
                    </p>
                </div>
            <?php endif; ?>

            <h2><?php esc_html_e( 'Current tier', 'talenttrack' ); ?></h2>
            <p style="font-size:18px;">
                <strong><?php echo esc_html( FeatureMap::tierLabel( $tier ) ); ?></strong>
                <?php if ( $tier !== $eff_tier ) : ?>
                    <span style="color:#b32d2e;"> · <?php
                        printf(
                            /* translators: %s is the effective tier label */
                            esc_html__( 'effective: %s (read-only grace)', 'talenttrack' ),
                            esc_html( FeatureMap::tierLabel( $eff_tier ) )
                        );
                    ?></span>
                <?php endif; ?>
            </p>

            <?php if ( $in_trial ) : ?>
                <p><?php
                    printf(
                        /* translators: %d is the number of days remaining */
                        esc_html( _n( 'Trial: %d day remaining.', 'Trial: %d days remaining.', $trial_days, 'talenttrack' ) ),
                        $trial_days
                    );
                ?></p>
            <?php elseif ( $in_grace ) : ?>
                <p style="color:#b32d2e;"><?php
                    printf(
                        /* translators: %d is the number of grace days remaining */
                        esc_html( _n( 'Trial ended — %d day of read-only access remaining. Upgrade to keep adding new evaluations.', 'Trial ended — %d days of read-only access remaining. Upgrade to keep adding new evaluations.', $grace_days, 'talenttrack' ) ),
                        $grace_days
                    );
                ?></p>
            <?php elseif ( $tier === FeatureMap::TIER_FREE && $trial_data === null ) : ?>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:12px;">
                    <?php wp_nonce_field( 'tt_license_start_trial', 'tt_license_nonce' ); ?>
                    <input type="hidden" name="action" value="tt_license_start_trial" />
                    <button type="submit" class="button button-primary button-hero">
                        <?php esc_html_e( 'Start 30-day Pro trial', 'talenttrack' ); ?>
                    </button>
                </form>
                <p class="description" style="margin-top:6px;">
                    <?php esc_html_e( 'Unlocks every Pro-tier feature for 30 days — trial cases, scout access, team chemistry, radar charts, the lot. After 30 days you get 14 days of read-only grace; then the install drops back to Free.', 'talenttrack' ); ?>
                </p>
            <?php endif; ?>

            <h2 style="margin-top:32px;"><?php esc_html_e( 'Usage vs free-tier caps', 'talenttrack' ); ?></h2>
            <table class="widefat striped" style="max-width:520px;">
                <thead><tr>
                    <th><?php esc_html_e( 'Resource', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'In use', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'Free cap', 'talenttrack' ); ?></th>
                </tr></thead>
                <tbody>
                    <tr>
                        <td><?php esc_html_e( 'Teams', 'talenttrack' ); ?></td>
                        <td><?php echo (int) $teams_used; ?></td>
                        <td><?php echo (int) $teams_cap; ?></td>
                    </tr>
                    <tr>
                        <td><?php esc_html_e( 'Players', 'talenttrack' ); ?></td>
                        <td><?php echo (int) $players_used; ?></td>
                        <td><?php echo (int) $players_cap; ?></td>
                    </tr>
                    <tr>
                        <td><?php esc_html_e( 'Evaluations', 'talenttrack' ); ?></td>
                        <td colspan="2"><em><?php esc_html_e( 'Unlimited on every tier.', 'talenttrack' ); ?></em></td>
                    </tr>
                </tbody>
            </table>

            <?php if ( $trial_data !== null ) : ?>
                <h2 style="margin-top:32px;"><?php esc_html_e( 'Trial state', 'talenttrack' ); ?></h2>
                <p>
                    <?php
                    printf(
                        /* translators: 1: trial started date, 2: trial expires date, 3: grace until date */
                        esc_html__( 'Started %1$s, expires %2$s, grace ends %3$s.', 'talenttrack' ),
                        esc_html( gmdate( 'Y-m-d', $trial_data['started_at'] ) ),
                        esc_html( gmdate( 'Y-m-d', $trial_data['expires_at'] ) ),
                        esc_html( gmdate( 'Y-m-d', $trial_data['grace_until'] ) )
                    );
                    ?>
                </p>
                <?php if ( DevOverride::isAvailable() ) : ?>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <?php wp_nonce_field( 'tt_license_reset_trial', 'tt_license_nonce' ); ?>
                        <input type="hidden" name="action" value="tt_license_reset_trial" />
                        <p>
                            <button type="submit" class="button"><?php esc_html_e( 'Reset trial state (dev only)', 'talenttrack' ); ?></button>
                        </p>
                    </form>
                <?php endif; ?>
            <?php endif; ?>

            <?php self::renderPhoneHomeDiagnostics(); ?>
        <?php
    }

    /**
     * Plan & restrictions — read-only "what's locked / what's unlocked"
     * tab. Replaces the former standalone `PlanOverviewPage` so coaches
     * and other read-only users still get a one-glance view of caps,
     * the trial / grace state, and the per-tier feature matrix without
     * a separate menu entry.
     */
    private static function renderPlanTab(): void {
        $tier        = LicenseGate::tier();
        $tier_label  = FeatureMap::tierLabel( $tier );
        $effective   = LicenseGate::effectiveTier();
        $in_trial    = LicenseGate::isInTrial();
        $in_grace    = LicenseGate::isInGrace();
        $trial_days  = LicenseGate::trialDaysRemaining();
        $grace_days  = LicenseGate::graceDaysRemaining();
        $is_operator = current_user_can( self::CAP );

        echo '<p>' . esc_html__( "Everything that's locked or limited on your install, in one place. Caps come from the Free-tier policy; features come from the plan you're on.", 'talenttrack' ) . '</p>';

        // 1. Current tier
        echo '<div class="notice" style="padding:16px; max-width:760px; margin-top:20px;">';
        echo '<h2 style="margin-top:0;">' . esc_html__( 'Current plan', 'talenttrack' ) . '</h2>';
        echo '<p style="font-size:18px; margin:0;"><strong>' . esc_html( $tier_label ) . '</strong>';
        if ( $in_trial ) {
            echo ' · <span style="color:#0b3d2e;">' . esc_html(
                sprintf(
                    /* translators: %d days remaining */
                    _n( '%d day left in trial', '%d days left in trial', $trial_days, 'talenttrack' ),
                    $trial_days
                )
            ) . '</span>';
        } elseif ( $in_grace ) {
            echo ' · <span style="color:#a86322;">' . esc_html(
                sprintf(
                    /* translators: %d grace days */
                    _n( 'Grace period — %d day until features lock', 'Grace period — %d days until features lock', $grace_days, 'talenttrack' ),
                    $grace_days
                )
            ) . '</span>';
        }
        echo '</p>';
        if ( $is_operator && $tier !== FeatureMap::TIER_PRO ) {
            $url = admin_url( 'admin.php?page=' . self::SLUG . '&tab=' . self::TAB_ACCOUNT );
            echo '<p style="margin-top:12px;"><a class="button button-primary" href="' . esc_url( $url ) . '">'
                . esc_html__( 'Upgrade or start a trial', 'talenttrack' )
                . '</a></p>';
        }
        echo '</div>';

        // 2. Caps table
        echo '<h2 style="margin-top:32px;">' . esc_html__( 'Free-tier caps', 'talenttrack' ) . '</h2>';
        echo '<p>' . esc_html__( 'Caps apply only on the Free plan. Trial and Standard / Pro have no cap.', 'talenttrack' ) . '</p>';
        $caps_apply = ( $effective === FeatureMap::TIER_FREE );
        echo '<table class="widefat striped" style="max-width:760px;"><thead><tr>';
        echo '<th>' . esc_html__( 'Resource', 'talenttrack' ) . '</th>';
        echo '<th>' . esc_html__( 'Current', 'talenttrack' ) . '</th>';
        echo '<th>' . esc_html__( 'Limit',   'talenttrack' ) . '</th>';
        echo '<th>' . esc_html__( 'Status',  'talenttrack' ) . '</th>';
        echo '</tr></thead><tbody>';
        foreach ( [ 'teams', 'players' ] as $cap_type ) {
            $current = FreeTierCaps::currentCount( $cap_type );
            $limit   = FreeTierCaps::capFor( $cap_type );
            $at_cap  = $caps_apply && $current >= $limit;
            $colour  = $at_cap ? '#b32d2e' : ( $caps_apply && $current >= ( $limit * 0.8 ) ? '#a86322' : '#137333' );
            $status  = ! $caps_apply
                ? __( 'No cap (paid / trial)', 'talenttrack' )
                : ( $at_cap ? __( 'At cap — upgrade to add more', 'talenttrack' ) : __( 'Within cap', 'talenttrack' ) );
            $resource_label = $cap_type === 'teams'
                ? __( 'Teams', 'talenttrack' )
                : __( 'Players', 'talenttrack' );
            echo '<tr>';
            echo '<td>' . esc_html( $resource_label ) . '</td>';
            echo '<td style="font-variant-numeric:tabular-nums;">' . (int) $current . '</td>';
            echo '<td style="font-variant-numeric:tabular-nums;">' . esc_html( $caps_apply ? (string) $limit : '—' ) . '</td>';
            echo '<td style="color:' . esc_attr( $colour ) . ';">' . esc_html( $status ) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';

        // 3. Feature matrix
        echo '<h2 style="margin-top:32px;">' . esc_html__( 'Features by plan', 'talenttrack' ) . '</h2>';
        echo '<p>' . esc_html__( 'Tick = available on that plan. Your current effective plan is highlighted.', 'talenttrack' ) . '</p>';
        echo '<table class="widefat striped" style="max-width:900px;"><thead><tr>';
        echo '<th>' . esc_html__( 'Feature', 'talenttrack' ) . '</th>';
        $tiers = [ FeatureMap::TIER_FREE, FeatureMap::TIER_STANDARD, FeatureMap::TIER_PRO ];
        foreach ( $tiers as $col_tier ) {
            $is_current = ( $col_tier === $effective );
            $style = $is_current ? ' style="background:#fffbe6;"' : '';
            echo '<th' . $style . '>' . esc_html( FeatureMap::tierLabel( $col_tier ) ) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ( self::featureCatalogue() as $feature_key => $feature_label ) {
            echo '<tr>';
            echo '<td>' . esc_html( $feature_label ) . '</td>';
            foreach ( $tiers as $col_tier ) {
                $has = FeatureMap::tierHas( $col_tier, $feature_key );
                $is_current = ( $col_tier === $effective );
                $cell_style = $is_current ? ' style="background:#fffbe6; text-align:center;"' : ' style="text-align:center;"';
                echo '<td' . $cell_style . '>' . ( $has ? '<span style="color:#137333; font-weight:600;">✓</span>' : '<span style="color:#999;">—</span>' ) . '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table>';

        echo '<p style="margin-top:24px; color:#5b6e75; font-size:13px;">'
            . esc_html__( 'Caps and features update immediately when you start a trial or upgrade. The trial gives Standard for 30 days, then a 7-day grace period at Free with the full data still readable.', 'talenttrack' )
            . '</p>';
    }

    /**
     * Catalogue of features rendered in the Plan tab matrix. Order
     * roughly follows `FeatureMap::DEFAULT_MAP`. Internal-only features
     * (radar_charts, undo_bulk) are included so operators understand
     * the full restriction set.
     *
     * @return array<string,string>
     */
    private static function featureCatalogue(): array {
        return [
            'core_evaluations'  => __( 'Evaluations', 'talenttrack' ),
            'core_sessions'     => __( 'Activities', 'talenttrack' ),
            'core_goals'        => __( 'Goals', 'talenttrack' ),
            'core_attendance'   => __( 'Attendance', 'talenttrack' ),
            'core_player_card'  => __( 'Player cards', 'talenttrack' ),
            'core_dashboard'    => __( 'Dashboard', 'talenttrack' ),
            'backup_local'      => __( 'Local backup', 'talenttrack' ),
            'backup_email'      => __( 'Email backup', 'talenttrack' ),
            'onboarding'        => __( 'Onboarding wizard', 'talenttrack' ),
            'demo_data'         => __( 'Demo data generator', 'talenttrack' ),
            'radar_charts'      => __( 'Radar charts', 'talenttrack' ),
            'player_comparison' => __( 'Player comparison', 'talenttrack' ),
            'rate_cards_full'   => __( 'Rate cards (full)', 'talenttrack' ),
            'csv_import'        => __( 'CSV import', 'talenttrack' ),
            'functional_roles'  => __( 'Functional roles', 'talenttrack' ),
            'partial_restore'   => __( 'Partial restore', 'talenttrack' ),
            'undo_bulk'         => __( 'Undo bulk actions', 'talenttrack' ),
            'multi_academy'     => __( 'Multi-academy', 'talenttrack' ),
            'photo_session'     => __( 'Photo-to-activity capture', 'talenttrack' ),
            'trial_module'      => __( 'Trial cases', 'talenttrack' ),
            'scout_access'      => __( 'Scout access', 'talenttrack' ),
            'team_chemistry'    => __( 'Team chemistry', 'talenttrack' ),
            's3_backup'         => __( 'S3 backup', 'talenttrack' ),
        ];
    }

    /**
     * v3.72.3 — render the Admin Center phone-home diagnostic block:
     * install_id, endpoint, last sent timestamp + HTTP code, next
     * scheduled cron, and a "Send now" button. Surfaces the data an
     * operator needs when one install is
     * silent and another from the same code base phones home fine.
     */
    private static function renderPhoneHomeDiagnostics(): void {
        if ( ! class_exists( '\\TT\\Modules\\AdminCenterClient\\InstallId' ) ) return;

        $install_id     = \TT\Modules\AdminCenterClient\InstallId::get();
        $endpoint       = \TT\Modules\AdminCenterClient\Sender::endpoint();
        $next_cron_ts   = wp_next_scheduled( \TT\Modules\AdminCenterClient\Cron\DailyCron::HOOK );
        $last_sent_at   = (int) get_option( 'tt_admin_center_last_sent_at', 0 );
        $last_sent_code = (int) get_option( 'tt_admin_center_last_sent_code', 0 );
        $last_phoned_v  = (string) get_option( 'tt_last_phoned_version', '' );

        $diag_key = 'tt_admin_center_last_diagnostic_' . get_current_user_id();
        $last_diag = get_transient( $diag_key );
        if ( $last_diag ) delete_transient( $diag_key );

        $msg = isset( $_GET['tt_msg'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['tt_msg'] ) ) : '';
        ?>
        <h2 style="margin-top:32px;"><?php esc_html_e( 'Admin Center phone-home', 'talenttrack' ); ?></h2>
        <table class="form-table" style="max-width:760px;">
            <tr><th><?php esc_html_e( 'Install ID', 'talenttrack' ); ?></th><td><code><?php echo esc_html( $install_id ); ?></code></td></tr>
            <tr><th><?php esc_html_e( 'Endpoint', 'talenttrack' ); ?></th><td><code><?php echo esc_html( $endpoint ); ?></code></td></tr>
            <tr><th><?php esc_html_e( 'Last sent', 'talenttrack' ); ?></th><td>
                <?php if ( $last_sent_at > 0 ) : ?>
                    <?php echo esc_html( gmdate( 'Y-m-d H:i:s', $last_sent_at ) ); ?> UTC
                    (<?php echo esc_html( human_time_diff( $last_sent_at ) ); ?> ago) — HTTP <?php echo (int) $last_sent_code; ?>
                <?php else : ?>
                    <em><?php esc_html_e( 'never (no successful send recorded yet)', 'talenttrack' ); ?></em>
                <?php endif; ?>
            </td></tr>
            <tr><th><?php esc_html_e( 'Last phoned version', 'talenttrack' ); ?></th><td>
                <?php echo $last_phoned_v !== '' ? '<code>' . esc_html( $last_phoned_v ) . '</code>' : '<em>' . esc_html__( '(not yet recorded)', 'talenttrack' ) . '</em>'; ?>
            </td></tr>
            <tr><th><?php esc_html_e( 'Next daily cron', 'talenttrack' ); ?></th><td>
                <?php if ( $next_cron_ts ) : ?>
                    <?php echo esc_html( gmdate( 'Y-m-d H:i:s', $next_cron_ts ) ); ?> UTC
                    (<?php echo esc_html( human_time_diff( $next_cron_ts ) ); ?> from now)
                <?php else : ?>
                    <strong style="color:#b32d2e;"><?php esc_html_e( 'NOT scheduled — wp-cron is not registered. This is the most likely cause when an install never phones home.', 'talenttrack' ); ?></strong>
                <?php endif; ?>
            </td></tr>
        </table>

        <?php if ( $msg === 'phone_home_done' && is_array( $last_diag ) ) : ?>
            <div class="notice <?php echo $last_diag['ok'] ? 'notice-success' : 'notice-error'; ?>" style="margin:12px 0;">
                <p>
                    <strong><?php echo $last_diag['ok'] ? esc_html__( 'Phone-home succeeded.', 'talenttrack' ) : esc_html__( 'Phone-home failed.', 'talenttrack' ); ?></strong>
                </p>
                <ul style="margin:6px 0 6px 20px;">
                    <li>HTTP: <strong><?php echo (int) $last_diag['code']; ?></strong></li>
                    <?php if ( ! empty( $last_diag['error'] ) ) : ?>
                        <li><?php esc_html_e( 'Error', 'talenttrack' ); ?>: <code><?php echo esc_html( (string) $last_diag['error'] ); ?></code></li>
                    <?php endif; ?>
                    <li><?php esc_html_e( 'Duration', 'talenttrack' ); ?>: <?php echo (int) $last_diag['duration_ms']; ?> ms</li>
                    <li><?php esc_html_e( 'Endpoint', 'talenttrack' ); ?>: <code><?php echo esc_html( (string) $last_diag['endpoint'] ); ?></code></li>
                    <li><?php esc_html_e( 'Body size', 'talenttrack' ); ?>: <?php echo (int) $last_diag['body_size']; ?> bytes</li>
                </ul>
            </div>
        <?php endif; ?>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'tt_phone_home_now', 'tt_nonce' ); ?>
            <input type="hidden" name="action" value="tt_phone_home_now" />
            <p>
                <button type="submit" class="button button-secondary"><?php esc_html_e( 'Send now', 'talenttrack' ); ?></button>
            </p>
        </form>

        <p style="color:#5b6e75; font-size:12px; max-width:760px;">
            <?php esc_html_e( "If \"Last sent\" is empty and \"Next daily cron\" is not scheduled, wp-cron is likely disabled (DISABLE_WP_CRON in wp-config.php) and no system cron substitute is calling wp-cron.php. If \"Send now\" returns a WP_Error, the install can't reach the Admin Center receiver — check outbound HTTPS / DNS for www.mediamaniacs.nl.", 'talenttrack' ); ?>
        </p>
        <?php
    }

    public static function handleStartTrial(): void {
        if ( ! current_user_can( self::CAP ) ) wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        check_admin_referer( 'tt_license_start_trial', 'tt_license_nonce' );
        // v3.94.1 — trial unlocks Pro, not Standard. See TrialState::start
        // for the rationale; in short: operators expected every Pro feature
        // (trial cases, scout access, team chemistry) to be live during the
        // trial window. Sticking with the per-method default keeps that
        // intent explicit at the call site.
        TrialState::start();
        do_action( 'tt_license_trial_started' );
        wp_safe_redirect( add_query_arg( [ 'page' => self::SLUG, 'tab' => self::TAB_ACCOUNT, 'tt_msg' => 'trial_started' ], admin_url( 'admin.php' ) ) );
        exit;
    }

    public static function handleResetTrial(): void {
        if ( ! current_user_can( self::CAP ) ) wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        if ( ! DevOverride::isAvailable() ) wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        check_admin_referer( 'tt_license_reset_trial', 'tt_license_nonce' );
        TrialState::reset();
        wp_safe_redirect( add_query_arg( [ 'page' => self::SLUG, 'tab' => self::TAB_ACCOUNT, 'tt_msg' => 'trial_reset' ], admin_url( 'admin.php' ) ) );
        exit;
    }
}
