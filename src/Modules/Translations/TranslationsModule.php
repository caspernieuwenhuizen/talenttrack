<?php
namespace TT\Modules\Translations;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\Container;
use TT\Core\ModuleInterface;

/**
 * TranslationsModule (#0025) — opt-in auto-translation layer.
 *
 * Boot wiring:
 *   - Configuration → Translations tab handlers (admin only).
 *   - User profile preference radio (admin profile screen + filter
 *     for personal_options on frontend "My account" view).
 *   - Privacy policy paragraph appended via wp_add_privacy_policy_content.
 *   - Cap-threshold admin notice (admin only, dashboard-wide).
 *
 * The TranslationLayer service has no constructor dependencies — it
 * resolves config via QueryHelpers and cache via repositories on demand
 * — so this module's boot is mostly hook attachment.
 */
final class TranslationsModule implements ModuleInterface {

    public function getName(): string {
        return 'translations';
    }

    public function register( Container $container ): void {}

    public function boot( Container $container ): void {
        if ( is_admin() ) {
            Admin\TranslationsConfigTab::init();
            Admin\CapThresholdNotice::init();
            Admin\UserProfilePreference::init();
        }

        // Append a sub-processor paragraph to the WP privacy policy
        // editor so admins can copy it into the published page. Fires
        // on every page load — wp_add_privacy_policy_content() is
        // idempotent (de-dupes by content key).
        add_action( 'admin_init', [ self::class, 'registerPrivacyContent' ] );
    }

    public static function registerPrivacyContent(): void {
        if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) return;
        if ( ! TranslationLayer::isEnabled() ) return;

        $primary  = TranslationLayer::primaryEngineName();
        $fallback = TranslationLayer::fallbackEngineName();
        $names    = array_filter( array_map( static function ( string $key ): string {
            switch ( $key ) {
                case 'deepl':  return 'DeepL SE';
                case 'google': return 'Google LLC';
            }
            return '';
        }, [ $primary, $fallback ] ) );
        $list = $names ? implode( ' / ', array_unique( $names ) ) : 'the configured translation provider';

        $body = sprintf(
            /* translators: %s is one or more sub-processor names. */
            __( 'When auto-translation is enabled, free-text content authored by users on this site may be transmitted to %s for translation when viewers request a different locale. The provider acts as a sub-processor under Article 28 GDPR; the controller (this site) authorises the relationship by enabling the feature in the plugin\'s Configuration → Translations tab. Source content is hashed and cached so repeat reads do not re-transmit. See the linked DPA for the provider\'s data handling commitments.', 'talenttrack' ),
            $list
        );
        wp_add_privacy_policy_content( __( 'TalentTrack — Auto-translation', 'talenttrack' ), wpautop( $body ) );
    }
}
