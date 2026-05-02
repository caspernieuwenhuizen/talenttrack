<?php
namespace TT\Modules\Translations\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Translations\Cache\TranslationsCacheRepository;
use TT\Modules\Translations\Cache\TranslationsUsageRepository;
use TT\Modules\Translations\TranslationLayer;

/**
 * TranslationsConfigTab — wp-admin Configuration tab for the
 * translation layer (#0025).
 *
 * Renders the opt-in form, persists settings via tt_config, handles
 * the "Clear cache" admin-post action, and exposes a tab key + label
 * the ConfigurationPage picks up.
 *
 * The form enforces:
 *   - Enabling requires the sub-processor checkbox AND credentials
 *     for the chosen primary engine. Validation fails the save and
 *     flashes an inline error.
 *   - Disabling triggers a cache + source-meta truncate (per spec's
 *     GDPR posture).
 */
final class TranslationsConfigTab {

    public const TAB_KEY     = 'translations';
    public const SAVE_ACTION = 'tt_save_translations_config';
    public const CLEAR_ACTION = 'tt_clear_translations_cache';

    public static function init(): void {
        add_action( 'admin_post_' . self::SAVE_ACTION,  [ __CLASS__, 'handleSave' ] );
        add_action( 'admin_post_' . self::CLEAR_ACTION, [ __CLASS__, 'handleClear' ] );
        add_filter( 'tt_config_tabs', [ __CLASS__, 'registerTab' ] );
        add_action( 'tt_config_tab_' . self::TAB_KEY, [ __CLASS__, 'render' ] );
    }

    /**
     * @param array<string,string> $tabs
     * @return array<string,string>
     */
    public static function registerTab( array $tabs ): array {
        $tabs[ self::TAB_KEY ] = __( 'Translations', 'talenttrack' );
        return $tabs;
    }

    public static function render(): void {
        $keys = TranslationLayer::configKeys();
        $enabled            = TranslationLayer::isEnabled();
        $primary            = TranslationLayer::primaryEngineName();
        $fallback           = TranslationLayer::fallbackEngineName();
        $deepl_set          = QueryHelpers::get_config( $keys['deepl_key'], '' ) !== '';
        $google_set         = QueryHelpers::get_config( $keys['google_service_account'], '' ) !== '';
        $site_default       = TranslationLayer::siteDefaultLang();
        $cap                = TranslationLayer::monthlyCharCap();
        $threshold          = TranslationLayer::thresholdPercentage();
        $confirmed          = TranslationLayer::subprocessorConfirmed();
        $usage_primary      = TranslationLayer::usageThisMonth( $primary );
        $usage_fallback     = $fallback ? TranslationLayer::usageThisMonth( $fallback ) : 0;
        $cache_size         = ( new TranslationsCacheRepository() )->size();
        $threshold_hit_at   = ( new TranslationsUsageRepository() )->thresholdHitAt( $primary );

        $engines = [
            'deepl'  => __( 'DeepL', 'talenttrack' ),
            'google' => __( 'Google Translate', 'talenttrack' ),
        ];
        $fallback_options = [ '' => __( '— None —', 'talenttrack' ) ] + $engines;
        $error = isset( $_GET['tt_t_err'] ) ? sanitize_key( (string) $_GET['tt_t_err'] ) : '';
        ?>
        <h2><?php esc_html_e( 'Auto-translation', 'talenttrack' ); ?></h2>
        <p class="description" style="max-width:780px;">
            <?php esc_html_e( 'Lazily translate user-entered free text (goal titles, evaluation notes, activity descriptions, …) at render time when a viewer\'s locale differs from the source. Default OFF. Translations are cached and re-used; the cap below limits per-month engine spend.', 'talenttrack' ); ?>
        </p>

        <?php if ( $error !== '' ) : ?>
            <div class="notice notice-error inline" style="margin:16px 0; max-width:780px;">
                <p><?php echo esc_html( self::errorMessage( $error ) ); ?></p>
            </div>
        <?php endif; ?>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:780px;">
            <?php wp_nonce_field( self::SAVE_ACTION, 'tt_translations_nonce' ); ?>
            <input type="hidden" name="action" value="<?php echo esc_attr( self::SAVE_ACTION ); ?>" />
            <table class="form-table">
                <tr>
                    <th><?php esc_html_e( 'Enable auto-translation', 'talenttrack' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="enabled" value="1" <?php checked( $enabled ); ?> />
                            <?php esc_html_e( 'Translate user-entered free text at render time', 'talenttrack' ); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'GDPR Article 28', 'talenttrack' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="subprocessor_confirmed" value="1" <?php checked( $confirmed ); ?> />
                            <?php esc_html_e( 'I confirm the chosen engine acts as a sub-processor on our behalf and our DPA is in place.', 'talenttrack' ); ?>
                        </label>
                        <p class="description" style="margin-top:6px;">
                            <?php esc_html_e( 'Required when enabling. See the disclosure block below for current DPA links.', 'talenttrack' ); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Primary engine', 'talenttrack' ); ?></th>
                    <td>
                        <?php foreach ( $engines as $key => $label ) : ?>
                            <label style="margin-right:14px;">
                                <input type="radio" name="primary_engine" value="<?php echo esc_attr( $key ); ?>" <?php checked( $primary, $key ); ?> />
                                <?php echo esc_html( $label ); ?>
                            </label>
                        <?php endforeach; ?>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Fallback engine', 'talenttrack' ); ?></th>
                    <td>
                        <select name="fallback_engine">
                            <?php foreach ( $fallback_options as $key => $label ) : ?>
                                <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $fallback, $key ); ?>>
                                    <?php echo esc_html( $label ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description"><?php esc_html_e( 'Used only when the primary engine returns a recoverable error (rate limit / 5xx).', 'talenttrack' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'DeepL API key', 'talenttrack' ); ?></th>
                    <td>
                        <input type="password" name="deepl_key" class="regular-text" autocomplete="new-password" placeholder="<?php echo $deepl_set ? esc_attr__( '(set — leave blank to keep)', 'talenttrack' ) : esc_attr__( 'xxxxxxxx-xxxx-…', 'talenttrack' ); ?>" />
                        <?php if ( $deepl_set ) : ?>
                            <button type="submit" formaction="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" name="action" value="<?php echo esc_attr( self::SAVE_ACTION ); ?>_clear_deepl" class="button-link" style="margin-left:8px;color:#b32d2e;">
                                <?php esc_html_e( 'Clear', 'talenttrack' ); ?>
                            </button>
                        <?php endif; ?>
                        <p class="description"><?php esc_html_e( 'Free-tier keys end with `:fx`. The plugin auto-routes to api-free.deepl.com for free keys.', 'talenttrack' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Google service-account JSON', 'talenttrack' ); ?></th>
                    <td>
                        <textarea name="google_service_account" rows="6" class="large-text code" placeholder='<?php echo $google_set ? esc_attr__( '(set — leave blank to keep)', 'talenttrack' ) : '{"type":"service_account",…}'; ?>'></textarea>
                        <p class="description"><?php esc_html_e( 'Paste the full service-account JSON. The Cloud Translation API must be enabled on the project.', 'talenttrack' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Site default content language', 'talenttrack' ); ?></th>
                    <td>
                        <input type="text" name="site_default_lang" value="<?php echo esc_attr( $site_default ); ?>" maxlength="10" />
                        <p class="description"><?php esc_html_e( 'Used as the source-language fallback when auto-detection confidence is below the floor (0.6). ISO 639-1 short code (nl, en, fr, de, es, …).', 'talenttrack' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Monthly character cap', 'talenttrack' ); ?></th>
                    <td>
                        <input type="number" name="monthly_cap" min="1000" step="1000" value="<?php echo (int) $cap; ?>" />
                        <p class="description"><?php
                            printf(
                                /* translators: %d is the configured cap. */
                                esc_html__( 'Soft cap. At 100%% of this number the layer stops calling the engine and renders source text. Default %d covers most single-club deployments under DeepL\'s free tier (500,000 chars).', 'talenttrack' ),
                                (int) TranslationLayer::DEFAULT_MONTHLY_CAP
                            );
                        ?></p>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Notify admin at', 'talenttrack' ); ?></th>
                    <td>
                        <input type="number" name="threshold_pct" min="1" max="100" value="<?php echo (int) $threshold; ?>" />%
                        <p class="description"><?php esc_html_e( 'A persistent dashboard notice appears the first time you cross this percentage in a given month.', 'talenttrack' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Sub-processor disclosure', 'talenttrack' ); ?></th>
                    <td>
                        <p style="background:#f6f7f9; padding:10px 12px; border-left:3px solid #1a4a8a; margin:0;">
                            <strong>DeepL SE</strong> — <a href="https://www.deepl.com/privacy/" target="_blank" rel="noopener noreferrer">deepl.com/privacy</a><br/>
                            <strong>Google LLC</strong> — <a href="https://cloud.google.com/terms/data-processing-addendum" target="_blank" rel="noopener noreferrer">cloud.google.com/terms/data-processing-addendum</a><br/>
                            <em><?php esc_html_e( 'Verify the current DPA links at the provider before relying on these.', 'talenttrack' ); ?></em>
                        </p>
                    </td>
                </tr>
            </table>

            <?php submit_button( __( 'Save translation settings', 'talenttrack' ) ); ?>
        </form>

        <h3 style="margin-top:32px;"><?php esc_html_e( 'Usage this month', 'talenttrack' ); ?></h3>
        <table class="widefat striped" style="max-width:780px;">
            <thead><tr>
                <th><?php esc_html_e( 'Engine', 'talenttrack' ); ?></th>
                <th><?php esc_html_e( 'Characters billed', 'talenttrack' ); ?></th>
                <th><?php esc_html_e( '% of cap', 'talenttrack' ); ?></th>
                <th><?php esc_html_e( 'Threshold hit at', 'talenttrack' ); ?></th>
            </tr></thead>
            <tbody>
                <tr>
                    <td><?php echo esc_html( $engines[ $primary ] ?? $primary ); ?> <span class="description">(<?php esc_html_e( 'primary', 'talenttrack' ); ?>)</span></td>
                    <td><?php echo esc_html( number_format_i18n( $usage_primary ) ); ?></td>
                    <td><?php echo $cap > 0 ? esc_html( (int) round( $usage_primary * 100 / $cap ) . '%' ) : '—'; ?></td>
                    <td><?php echo $threshold_hit_at ? esc_html( $threshold_hit_at ) : '—'; ?></td>
                </tr>
                <?php if ( $fallback ) : ?>
                    <tr>
                        <td><?php echo esc_html( $engines[ $fallback ] ?? $fallback ); ?> <span class="description">(<?php esc_html_e( 'fallback', 'talenttrack' ); ?>)</span></td>
                        <td><?php echo esc_html( number_format_i18n( $usage_fallback ) ); ?></td>
                        <td>—</td>
                        <td>—</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <h3 style="margin-top:32px;"><?php esc_html_e( 'Cache', 'talenttrack' ); ?></h3>
        <p>
            <?php
            printf(
                /* translators: %s is a number. */
                esc_html__( '%s cached translations.', 'talenttrack' ),
                '<strong>' . esc_html( number_format_i18n( $cache_size ) ) . '</strong>'
            );
            ?>
        </p>
        <p>
            <a href="<?php echo esc_url( wp_nonce_url(
                admin_url( 'admin-post.php?action=' . self::CLEAR_ACTION ),
                self::CLEAR_ACTION
            ) ); ?>" class="button" onclick="return confirm('<?php echo esc_js( __( 'Clear all cached translations? Each subsequent read will re-call the engine.', 'talenttrack' ) ); ?>');">
                <?php esc_html_e( 'Clear cache now', 'talenttrack' ); ?>
            </a>
        </p>
        <?php
    }

    public static function handleSave(): void {
        if ( ! current_user_can( 'tt_edit_translations' ) ) wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        check_admin_referer( self::SAVE_ACTION, 'tt_translations_nonce' );

        $keys      = TranslationLayer::configKeys();
        $enabled   = ! empty( $_POST['enabled'] );
        $confirmed = ! empty( $_POST['subprocessor_confirmed'] );
        $primary   = sanitize_key( (string) ( $_POST['primary_engine'] ?? 'deepl' ) );
        if ( ! in_array( $primary, [ 'deepl', 'google' ], true ) ) $primary = 'deepl';
        $fallback  = sanitize_key( (string) ( $_POST['fallback_engine'] ?? '' ) );
        if ( ! in_array( $fallback, [ '', 'deepl', 'google' ], true ) ) $fallback = '';

        $deepl_key  = trim( (string) wp_unslash( $_POST['deepl_key'] ?? '' ) );
        $google_sa  = trim( (string) wp_unslash( $_POST['google_service_account'] ?? '' ) );
        $site_lang  = TranslationLayer::shortCode( (string) wp_unslash( $_POST['site_default_lang'] ?? '' ) );
        $cap        = max( 1000, (int) ( $_POST['monthly_cap'] ?? TranslationLayer::DEFAULT_MONTHLY_CAP ) );
        $threshold  = max( 1, min( 100, (int) ( $_POST['threshold_pct'] ?? TranslationLayer::DEFAULT_THRESHOLD_PCT ) ) );

        // Persist credentials only when supplied. Empty = keep existing.
        if ( $deepl_key !== '' ) QueryHelpers::set_config( $keys['deepl_key'], $deepl_key );
        if ( $google_sa !== '' ) QueryHelpers::set_config( $keys['google_service_account'], $google_sa );
        QueryHelpers::set_config( $keys['primary_engine'],         $primary );
        QueryHelpers::set_config( $keys['fallback_engine'],        $fallback );
        QueryHelpers::set_config( $keys['site_default_lang'],      $site_lang );
        QueryHelpers::set_config( $keys['monthly_cap'],            (string) $cap );
        QueryHelpers::set_config( $keys['threshold_pct'],          (string) $threshold );
        QueryHelpers::set_config( $keys['subprocessor_confirmed'], $confirmed ? '1' : '0' );

        // Validate before flipping enabled to ON.
        if ( $enabled ) {
            if ( ! $confirmed ) {
                self::redirectWithError( 'subprocessor_required' );
                return;
            }
            $has_creds = ( $primary === 'deepl'  && QueryHelpers::get_config( $keys['deepl_key'], '' ) !== '' )
                       || ( $primary === 'google' && QueryHelpers::get_config( $keys['google_service_account'], '' ) !== '' );
            if ( ! $has_creds ) {
                self::redirectWithError( 'credentials_required' );
                return;
            }
        }

        $was_enabled = TranslationLayer::isEnabled();
        QueryHelpers::set_config( $keys['enabled'], $enabled ? '1' : '0' );

        // Opt-out → erase cache + source meta (GDPR posture).
        if ( $was_enabled && ! $enabled ) {
            TranslationLayer::purgeAllCaches();
        }

        wp_safe_redirect( admin_url( 'admin.php?page=tt-config&tab=' . self::TAB_KEY . '&tt_msg=saved' ) );
        exit;
    }

    public static function handleClear(): void {
        if ( ! current_user_can( 'tt_edit_translations' ) ) wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        check_admin_referer( self::CLEAR_ACTION );
        TranslationLayer::purgeAllCaches();
        wp_safe_redirect( admin_url( 'admin.php?page=tt-config&tab=' . self::TAB_KEY . '&tt_msg=saved' ) );
        exit;
    }

    private static function redirectWithError( string $code ): void {
        wp_safe_redirect( admin_url(
            'admin.php?page=tt-config&tab=' . self::TAB_KEY . '&tt_t_err=' . rawurlencode( $code )
        ) );
        exit;
    }

    private static function errorMessage( string $code ): string {
        switch ( $code ) {
            case 'subprocessor_required':
                return __( 'Tick the Article 28 sub-processor confirmation before enabling.', 'talenttrack' );
            case 'credentials_required':
                return __( 'Add credentials for the selected primary engine before enabling.', 'talenttrack' );
        }
        return __( 'Translation settings could not be saved.', 'talenttrack' );
    }
}
