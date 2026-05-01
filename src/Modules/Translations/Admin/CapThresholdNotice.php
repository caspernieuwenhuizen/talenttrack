<?php
namespace TT\Modules\Translations\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Translations\Cache\TranslationsUsageRepository;
use TT\Modules\Translations\TranslationLayer;

/**
 * CapThresholdNotice — persistent dashboard banner for the soft
 * cost cap (#0025).
 *
 * Two states:
 *   - Threshold crossed but cap not yet hit: a warning notice with
 *     usage % + raise-cap link. Fires once per month (gated by the
 *     `threshold_hit_at` row on `tt_translations_usage`).
 *   - Cap fully hit: a more urgent error notice that says translations
 *     are paused until the cap is raised or the month rolls over.
 *
 * Both are dismissible per-user; dismissing doesn't reset the
 * underlying state.
 */
final class CapThresholdNotice {

    public static function init(): void {
        add_action( 'admin_notices', [ __CLASS__, 'render' ] );
    }

    public static function render(): void {
        if ( ! current_user_can( 'tt_edit_translations' ) ) return;
        if ( ! TranslationLayer::isEnabled() ) return;

        $primary    = TranslationLayer::primaryEngineName();
        $usage_repo = new TranslationsUsageRepository();
        $used       = $usage_repo->charsThisMonth( $primary );
        $cap        = TranslationLayer::monthlyCharCap();
        if ( $cap <= 0 ) return;

        $config_url = admin_url( 'admin.php?page=tt-config&tab=' . TranslationsConfigTab::TAB_KEY );

        if ( $used >= $cap ) {
            ?>
            <div class="notice notice-error">
                <p>
                    <strong><?php esc_html_e( 'TalentTrack — translation cap reached.', 'talenttrack' ); ?></strong>
                    <?php
                    printf(
                        /* translators: 1=used chars, 2=cap, 3=month name. */
                        esc_html__( '%1$s of %2$s characters used this month (%3$s). Translations are paused; viewers see source text until the cap is raised or the month rolls over.', 'talenttrack' ),
                        '<strong>' . esc_html( number_format_i18n( $used ) ) . '</strong>',
                        esc_html( number_format_i18n( $cap ) ),
                        esc_html( wp_date( 'F Y' ) )
                    );
                    ?>
                    <a href="<?php echo esc_url( $config_url ); ?>" style="margin-left:6px;"><?php esc_html_e( 'Raise the cap →', 'talenttrack' ); ?></a>
                </p>
            </div>
            <?php
            return;
        }

        if ( $usage_repo->thresholdHitAt( $primary ) ) {
            $pct = (int) round( $used * 100 / $cap );
            ?>
            <div class="notice notice-warning is-dismissible">
                <p>
                    <strong><?php esc_html_e( 'TalentTrack — translation usage at threshold.', 'talenttrack' ); ?></strong>
                    <?php
                    printf(
                        /* translators: 1=percent, 2=used chars, 3=cap. */
                        esc_html__( '%1$s%% used (%2$s / %3$s) for the configured monthly cap.', 'talenttrack' ),
                        (int) $pct,
                        esc_html( number_format_i18n( $used ) ),
                        esc_html( number_format_i18n( $cap ) )
                    );
                    ?>
                    <a href="<?php echo esc_url( $config_url ); ?>" style="margin-left:6px;"><?php esc_html_e( 'Review settings →', 'talenttrack' ); ?></a>
                </p>
            </div>
            <?php
        }
    }
}
