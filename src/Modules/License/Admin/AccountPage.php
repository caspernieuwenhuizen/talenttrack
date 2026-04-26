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
 * Shows current tier, trial/grace state with day countdown, free-tier
 * usage vs caps, and CTAs to start a trial or open the Freemius
 * checkout. Visible to anyone with `tt_edit_settings` (the admin tier
 * — coaches don't need to see billing).
 *
 * On installs without Freemius credentials defined yet, the page
 * surfaces a "Monetization not yet configured" banner instead of the
 * upgrade CTA. The trial CTA still works (it's plugin-internal).
 */
class AccountPage {

    public const SLUG = 'tt-account';
    public const CAP  = 'tt_edit_settings';

    public static function init(): void {
        add_action( 'admin_post_tt_license_start_trial', [ self::class, 'handleStartTrial' ] );
        add_action( 'admin_post_tt_license_reset_trial', [ self::class, 'handleResetTrial' ] );
    }

    public static function render(): void {
        if ( ! current_user_can( self::CAP ) ) {
            wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        }
        $tier        = LicenseGate::tier();
        $eff_tier    = LicenseGate::effectiveTier();
        $in_trial    = LicenseGate::isInTrial();
        $in_grace    = LicenseGate::isInGrace();
        $trial_days  = LicenseGate::trialDaysRemaining();
        $grace_days  = LicenseGate::graceDaysRemaining();
        $configured  = FreemiusAdapter::isConfigured();
        $override    = DevOverride::active();
        $trial_data  = TrialState::read();
        $teams_used  = FreeTierCaps::currentCount( FreeTierCaps::CAP_TEAMS );
        $players_used = FreeTierCaps::currentCount( FreeTierCaps::CAP_PLAYERS );
        $teams_cap   = FreeTierCaps::teamCap();
        $players_cap = FreeTierCaps::playerCap();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'TalentTrack — Account', 'talenttrack' ); ?></h1>

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
                        <?php esc_html_e( 'Start 30-day Standard trial', 'talenttrack' ); ?>
                    </button>
                </form>
                <p class="description" style="margin-top:6px;">
                    <?php esc_html_e( 'Unlocks every Standard-tier feature for 30 days, then 14 days of read-only grace, then back to Free.', 'talenttrack' ); ?>
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
        </div>
        <?php
    }

    public static function handleStartTrial(): void {
        if ( ! current_user_can( self::CAP ) ) wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        check_admin_referer( 'tt_license_start_trial', 'tt_license_nonce' );
        TrialState::start( FeatureMap::TIER_STANDARD );
        do_action( 'tt_license_trial_started' );
        wp_safe_redirect( add_query_arg( [ 'page' => self::SLUG, 'tt_msg' => 'trial_started' ], admin_url( 'admin.php' ) ) );
        exit;
    }

    public static function handleResetTrial(): void {
        if ( ! current_user_can( self::CAP ) ) wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        if ( ! DevOverride::isAvailable() ) wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        check_admin_referer( 'tt_license_reset_trial', 'tt_license_nonce' );
        TrialState::reset();
        wp_safe_redirect( add_query_arg( [ 'page' => self::SLUG, 'tt_msg' => 'trial_reset' ], admin_url( 'admin.php' ) ) );
        exit;
    }
}
