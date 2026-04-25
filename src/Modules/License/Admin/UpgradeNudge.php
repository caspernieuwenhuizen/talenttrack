<?php
namespace TT\Modules\License\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\License\FeatureMap;
use TT\Modules\License\LicenseGate;

/**
 * UpgradeNudge — small helpers that render a "Upgrade to unlock" block
 * in place of a gated feature. Two flavors:
 *
 *   - **inline()**   — short, e.g. for an admin-tab body or a tile
 *                      destination. Returns HTML.
 *   - **capModal()** — renders an info banner when a cap is hit
 *                      (e.g. the user clicked "Add player" past the 25
 *                      cap). Caller renders inside their own form
 *                      flow.
 *
 * Both surfaces include a deep-link to the Account page where the user
 * picks Standard or Pro and is sent to Freemius checkout. No actual
 * Freemius hooks are wired here — the deep-link just opens the Account
 * page; the real upgrade flow is owned by the Freemius SDK once
 * configured.
 */
class UpgradeNudge {

    /**
     * Render an "Upgrade to unlock" block as the body of a gated view.
     *
     * @param string $feature_label  Human-readable feature name (translated)
     * @param string $required_tier  'standard' or 'pro'
     */
    public static function inline( string $feature_label, string $required_tier ): string {
        $required_tier = FeatureMap::normalizeTier( $required_tier );
        $tier_label    = FeatureMap::tierLabel( $required_tier );
        $url           = admin_url( 'admin.php?page=' . AccountPage::SLUG );

        ob_start();
        ?>
        <div class="notice notice-info" style="padding:24px; max-width:680px;">
            <p style="font-size:16px; margin:0 0 8px;">
                <strong><?php
                    /* translators: %s is a feature name like "Radar charts" */
                    printf( esc_html__( '%s is a paid feature.', 'talenttrack' ), esc_html( $feature_label ) );
                ?></strong>
            </p>
            <p style="margin:0 0 12px;">
                <?php
                printf(
                    /* translators: %s is the required tier label, e.g. "Standard" */
                    esc_html__( 'Available on %s and above. Open your account page to start a 30-day trial or upgrade.', 'talenttrack' ),
                    '<strong>' . esc_html( $tier_label ) . '</strong>'
                );
                ?>
            </p>
            <p style="margin:0;">
                <a class="button button-primary" href="<?php echo esc_url( $url ); ?>">
                    <?php esc_html_e( 'Open account page', 'talenttrack' ); ?>
                </a>
            </p>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Render a "you've hit the free-tier cap" notice. Used by
     * controllers that detected `LicenseGate::capsExceeded()` before
     * a create action.
     */
    public static function capHit( string $cap_type ): string {
        $url = admin_url( 'admin.php?page=' . AccountPage::SLUG );
        $message = $cap_type === 'teams'
            ? __( 'You have reached the free-tier limit of 1 team. Upgrade to Standard to add more.', 'talenttrack' )
            : __( 'You have reached the free-tier limit of 25 players. Upgrade to Standard to add more.', 'talenttrack' );

        ob_start();
        ?>
        <div class="notice notice-warning" style="padding:16px; max-width:680px;">
            <p style="margin:0 0 8px;"><strong><?php echo esc_html( $message ); ?></strong></p>
            <p style="margin:0;">
                <a class="button button-primary" href="<?php echo esc_url( $url ); ?>">
                    <?php esc_html_e( 'Upgrade to continue', 'talenttrack' ); ?>
                </a>
            </p>
        </div>
        <?php
        return (string) ob_get_clean();
    }
}
