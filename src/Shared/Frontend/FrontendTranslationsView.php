<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Translations\Cache\TranslationsCacheRepository;
use TT\Modules\Translations\Cache\TranslationsUsageRepository;
use TT\Modules\Translations\TranslationLayer;
use TT\Shared\Frontend\Components\FormSaveButton;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;

/**
 * FrontendTranslationsView — frontend port of the wp-admin
 * Configuration → Translations tab (#1935, child of #1533).
 *
 * Renders the auto-translation engine configuration (enable, primary /
 * fallback engine, credentials, site default language, monthly cap,
 * notify threshold, GDPR sub-processor confirmation), the read-only
 * usage table (characters billed / % of cap / threshold-hit date), and
 * a "Clear cache" action — all without bouncing to wp-admin.
 *
 * The view only COMPOSES data: every decision (validation, keep-on-blank
 * credentials, the GDPR opt-out purge, cache truncation) lives in
 * TranslationLayer and runs server-side via TranslationsRestController.
 * Saving POSTs `/translations/settings`; Clear cache POSTs
 * `/translations/clear-cache`. See assets/js/frontend-translations.js.
 *
 * Capability: view gated on `tt_view_translations`; the write endpoints
 * gate on `tt_edit_translations` (matrix caps — never role strings).
 * Secrets (DeepL key, Google JSON) are never echoed back; the form
 * shows a "(set)" indicator and leaves the input blank ("leave blank to
 * keep").
 */
class FrontendTranslationsView extends FrontendViewBase {

    private const VIEW_CAP = 'tt_view_translations';
    private const EDIT_CAP = 'tt_edit_translations';

    public static function render( int $user_id, bool $is_admin ): void {
        if ( ! current_user_can( self::VIEW_CAP ) ) {
            FrontendBreadcrumbs::fromDashboard( __( 'Translations', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'You do not have permission to view this section.', 'talenttrack' ) . '</p>';
            return;
        }

        self::enqueueAssets();
        self::enqueueViewAssets();
        FrontendBreadcrumbs::fromDashboard( __( 'Translations', 'talenttrack' ) );
        self::renderHeader( __( 'Translations', 'talenttrack' ) );

        $can_edit = current_user_can( self::EDIT_CAP );

        $keys           = TranslationLayer::configKeys();
        $enabled        = TranslationLayer::isEnabled();
        $primary        = TranslationLayer::primaryEngineName();
        $fallback       = TranslationLayer::fallbackEngineName();
        $deepl_set      = QueryHelpers::get_config( $keys['deepl_key'], '' ) !== '';
        $google_set     = QueryHelpers::get_config( $keys['google_service_account'], '' ) !== '';
        $site_default   = TranslationLayer::siteDefaultLang();
        $cap            = TranslationLayer::monthlyCharCap();
        $threshold      = TranslationLayer::thresholdPercentage();
        $confirmed      = TranslationLayer::subprocessorConfirmed();
        $usage_primary  = TranslationLayer::usageThisMonth( $primary );
        $usage_fallback = $fallback ? TranslationLayer::usageThisMonth( $fallback ) : 0;
        $cache_size     = ( new TranslationsCacheRepository() )->size();
        $threshold_hit  = ( new TranslationsUsageRepository() )->thresholdHitAt( $primary );

        $engines = [
            'deepl'  => __( 'DeepL', 'talenttrack' ),
            'google' => __( 'Google Translate', 'talenttrack' ),
        ];
        $fallback_options = [ '' => __( '— None —', 'talenttrack' ) ] + $engines;

        // Cancel target: the Configuration view this tile lives under.
        $cancel_url = add_query_arg(
            [ 'tt_view' => 'configuration' ],
            remove_query_arg( [ 'tt_view' ] )
        );
        ?>
        <div class="tt-translations" data-tt-translations>
            <p class="tt-translations__intro">
                <?php esc_html_e( 'Lazily translate user-entered free text (goal titles, evaluation notes, activity descriptions, …) at render time when a viewer\'s locale differs from the source. Default OFF. Translations are cached and re-used; the cap below limits per-month engine spend.', 'talenttrack' ); ?>
            </p>

            <div class="tt-translations__form-msg" data-tt-translations-msg role="status" aria-live="polite"></div>

            <form data-tt-translations-form>
                <div class="tt-translations__panel">
                    <div class="tt-translations__field">
                        <p class="tt-translations__legend"><?php esc_html_e( 'Enable auto-translation', 'talenttrack' ); ?></p>
                        <label class="tt-translations__choice">
                            <input type="checkbox" name="enabled" value="1" <?php checked( $enabled ); ?> <?php disabled( ! $can_edit ); ?> />
                            <span><?php esc_html_e( 'Translate user-entered free text at render time', 'talenttrack' ); ?></span>
                        </label>
                    </div>

                    <div class="tt-translations__field">
                        <p class="tt-translations__legend"><?php esc_html_e( 'GDPR Article 28', 'talenttrack' ); ?></p>
                        <label class="tt-translations__choice">
                            <input type="checkbox" name="subprocessor_confirmed" value="1" <?php checked( $confirmed ); ?> <?php disabled( ! $can_edit ); ?> />
                            <span><?php esc_html_e( 'I confirm the chosen engine acts as a sub-processor on our behalf and our DPA is in place.', 'talenttrack' ); ?></span>
                        </label>
                        <p class="tt-translations__hint"><?php esc_html_e( 'Required when enabling. See the disclosure below for current DPA links.', 'talenttrack' ); ?></p>
                    </div>

                    <div class="tt-translations__field">
                        <p class="tt-translations__legend"><?php esc_html_e( 'Primary engine', 'talenttrack' ); ?></p>
                        <div class="tt-translations__choices">
                            <?php foreach ( $engines as $key => $label ) : ?>
                                <label class="tt-translations__choice">
                                    <input type="radio" name="primary_engine" value="<?php echo esc_attr( $key ); ?>" <?php checked( $primary, $key ); ?> <?php disabled( ! $can_edit ); ?> />
                                    <span><?php echo esc_html( $label ); ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="tt-translations__field tt-translations__field--inline">
                        <label class="tt-translations__legend" for="tt-tr-fallback"><?php esc_html_e( 'Fallback engine', 'talenttrack' ); ?></label>
                        <div>
                            <select id="tt-tr-fallback" class="tt-translations__input tt-translations__input--narrow" name="fallback_engine" <?php disabled( ! $can_edit ); ?>>
                                <?php foreach ( $fallback_options as $key => $label ) : ?>
                                    <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $fallback, $key ); ?>><?php echo esc_html( $label ); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="tt-translations__hint"><?php esc_html_e( 'Used only when the primary engine returns a recoverable error (rate limit / 5xx).', 'talenttrack' ); ?></p>
                        </div>
                    </div>

                    <div class="tt-translations__field tt-translations__field--inline">
                        <label class="tt-translations__legend" for="tt-tr-deepl">
                            <?php esc_html_e( 'DeepL API key', 'talenttrack' ); ?>
                            <?php if ( $deepl_set ) : ?>
                                <span class="tt-translations__set-flag"><?php esc_html_e( '(set)', 'talenttrack' ); ?></span>
                            <?php endif; ?>
                        </label>
                        <div>
                            <input type="password" id="tt-tr-deepl" class="tt-translations__input" name="deepl_key"
                                autocomplete="new-password"
                                placeholder="<?php echo $deepl_set ? esc_attr__( 'Leave blank to keep current key', 'talenttrack' ) : esc_attr__( 'xxxxxxxx-xxxx-…', 'talenttrack' ); ?>"
                                <?php disabled( ! $can_edit ); ?> />
                            <p class="tt-translations__hint"><?php esc_html_e( 'Free-tier keys end with `:fx`. The plugin auto-routes to api-free.deepl.com for free keys.', 'talenttrack' ); ?></p>
                        </div>
                    </div>

                    <div class="tt-translations__field tt-translations__field--inline">
                        <label class="tt-translations__legend" for="tt-tr-google">
                            <?php esc_html_e( 'Google service-account JSON', 'talenttrack' ); ?>
                            <?php if ( $google_set ) : ?>
                                <span class="tt-translations__set-flag"><?php esc_html_e( '(set)', 'talenttrack' ); ?></span>
                            <?php endif; ?>
                        </label>
                        <div>
                            <textarea id="tt-tr-google" class="tt-translations__textarea" name="google_service_account" rows="6"
                                placeholder="<?php echo $google_set ? esc_attr__( 'Leave blank to keep current credentials', 'talenttrack' ) : esc_attr( '{"type":"service_account",…}' ); ?>"
                                <?php disabled( ! $can_edit ); ?>></textarea>
                            <p class="tt-translations__hint"><?php esc_html_e( 'Paste the full service-account JSON. The Cloud Translation API must be enabled on the project.', 'talenttrack' ); ?></p>
                        </div>
                    </div>

                    <div class="tt-translations__field tt-translations__field--inline">
                        <label class="tt-translations__legend" for="tt-tr-lang"><?php esc_html_e( 'Site default content language', 'talenttrack' ); ?></label>
                        <div>
                            <input type="text" id="tt-tr-lang" class="tt-translations__input tt-translations__input--narrow" name="site_default_lang"
                                value="<?php echo esc_attr( $site_default ); ?>" maxlength="10" autocomplete="off"
                                <?php disabled( ! $can_edit ); ?> />
                            <p class="tt-translations__hint"><?php esc_html_e( 'Source-language fallback when auto-detection confidence is below the floor. ISO 639-1 short code (nl, en, fr, de, es, …).', 'talenttrack' ); ?></p>
                        </div>
                    </div>

                    <div class="tt-translations__field tt-translations__field--inline">
                        <label class="tt-translations__legend" for="tt-tr-cap"><?php esc_html_e( 'Monthly character cap', 'talenttrack' ); ?></label>
                        <div>
                            <input type="number" inputmode="numeric" id="tt-tr-cap" class="tt-translations__input tt-translations__input--narrow" name="monthly_cap"
                                min="1000" step="1000" value="<?php echo (int) $cap; ?>"
                                <?php disabled( ! $can_edit ); ?> />
                            <p class="tt-translations__hint"><?php
                                printf(
                                    /* translators: %d is the default cap. */
                                    esc_html__( 'Soft cap. At 100%% of this number the layer stops calling the engine and renders source text. Default %d covers most single-club deployments under DeepL\'s free tier.', 'talenttrack' ),
                                    (int) TranslationLayer::DEFAULT_MONTHLY_CAP
                                );
                            ?></p>
                        </div>
                    </div>

                    <div class="tt-translations__field tt-translations__field--inline">
                        <label class="tt-translations__legend" for="tt-tr-threshold"><?php esc_html_e( 'Notify admin at (%)', 'talenttrack' ); ?></label>
                        <div>
                            <input type="number" inputmode="numeric" id="tt-tr-threshold" class="tt-translations__input tt-translations__input--narrow" name="threshold_pct"
                                min="1" max="100" value="<?php echo (int) $threshold; ?>"
                                <?php disabled( ! $can_edit ); ?> />
                            <p class="tt-translations__hint"><?php esc_html_e( 'A persistent dashboard notice appears the first time you cross this percentage in a given month.', 'talenttrack' ); ?></p>
                        </div>
                    </div>

                    <div class="tt-translations__field">
                        <p class="tt-translations__legend"><?php esc_html_e( 'Sub-processor disclosure', 'talenttrack' ); ?></p>
                        <p class="tt-translations__disclosure">
                            <strong>DeepL SE</strong> — <a href="https://www.deepl.com/privacy/" target="_blank" rel="noopener noreferrer">deepl.com/privacy</a><br />
                            <strong>Google LLC</strong> — <a href="https://cloud.google.com/terms/data-processing-addendum" target="_blank" rel="noopener noreferrer">cloud.google.com/terms/data-processing-addendum</a><br />
                            <em><?php esc_html_e( 'Verify the current DPA links at the provider before relying on these.', 'talenttrack' ); ?></em>
                        </p>
                    </div>
                </div>

                <?php if ( $can_edit ) : ?>
                    <?php echo FormSaveButton::render( [
                        'label'        => __( 'Save translation settings', 'talenttrack' ),
                        'label_saving' => __( 'Saving…', 'talenttrack' ),
                        'label_saved'  => __( 'Saved', 'talenttrack' ),
                        'cancel_url'   => $cancel_url,
                        'cancel_label' => __( 'Cancel', 'talenttrack' ),
                    ] ); ?>
                <?php endif; ?>
            </form>

            <section class="tt-translations__section">
                <h2><?php esc_html_e( 'Usage this month', 'talenttrack' ); ?></h2>
                <table class="tt-translations__usage">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Engine', 'talenttrack' ); ?></th>
                            <th><?php esc_html_e( 'Characters billed', 'talenttrack' ); ?></th>
                            <th><?php esc_html_e( '% of cap', 'talenttrack' ); ?></th>
                            <th><?php esc_html_e( 'Threshold hit at', 'talenttrack' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>
                                <?php echo esc_html( $engines[ $primary ] ?? $primary ); ?>
                                <span class="tt-translations__role">(<?php esc_html_e( 'primary', 'talenttrack' ); ?>)</span>
                            </td>
                            <td><?php echo esc_html( number_format_i18n( $usage_primary ) ); ?></td>
                            <td><?php echo $cap > 0 ? esc_html( (int) round( $usage_primary * 100 / $cap ) . '%' ) : '—'; ?></td>
                            <td><?php echo $threshold_hit ? esc_html( $threshold_hit ) : '—'; ?></td>
                        </tr>
                        <?php if ( $fallback ) : ?>
                            <tr>
                                <td>
                                    <?php echo esc_html( $engines[ $fallback ] ?? $fallback ); ?>
                                    <span class="tt-translations__role">(<?php esc_html_e( 'fallback', 'talenttrack' ); ?>)</span>
                                </td>
                                <td><?php echo esc_html( number_format_i18n( $usage_fallback ) ); ?></td>
                                <td>—</td>
                                <td>—</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </section>

            <section class="tt-translations__section">
                <h2><?php esc_html_e( 'Cache', 'talenttrack' ); ?></h2>
                <p>
                    <?php
                    printf(
                        /* translators: %s is a number of cached translations. */
                        esc_html__( '%s cached translations.', 'talenttrack' ),
                        '<strong>' . esc_html( number_format_i18n( $cache_size ) ) . '</strong>'
                    );
                    ?>
                </p>
                <?php if ( $can_edit ) : ?>
                    <button type="button" class="tt-btn tt-btn-secondary" data-tt-translations-clear>
                        <?php esc_html_e( 'Clear cache now', 'talenttrack' ); ?>
                    </button>
                    <div class="tt-translations__form-msg" data-tt-translations-clear-msg role="status" aria-live="polite"></div>
                <?php endif; ?>
            </section>
        </div>
        <?php
    }

    private static function enqueueViewAssets(): void {
        wp_enqueue_style(
            'tt-frontend-translations',
            TT_PLUGIN_URL . 'assets/css/frontend-translations.css',
            [ 'tt-frontend-app-chrome' ],
            TT_VERSION
        );
        wp_enqueue_script(
            'tt-frontend-translations',
            TT_PLUGIN_URL . 'assets/js/frontend-translations.js',
            [],
            TT_VERSION,
            true
        );
        wp_localize_script(
            'tt-frontend-translations',
            'TT_Translations',
            [
                'i18n' => [
                    'saved'         => __( 'Settings saved.', 'talenttrack' ),
                    'cache_cleared' => __( 'Cache cleared.', 'talenttrack' ),
                    'error'         => __( 'Could not save. Please try again.', 'talenttrack' ),
                    'network_error' => __( 'Network error. Please try again.', 'talenttrack' ),
                    'clear_confirm' => __( 'Clear all cached translations? Each subsequent read will re-call the engine.', 'talenttrack' ),
                ],
            ]
        );
    }
}
