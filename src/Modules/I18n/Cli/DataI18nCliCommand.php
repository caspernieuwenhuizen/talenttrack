<?php
namespace TT\Modules\I18n\Cli;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\I18n\DataTranslationAudit;

/**
 * DataI18nCliCommand (#4511) — `wp tt i18n-data`.
 *
 * Lists text stored in data that a screen in the given locale shows
 * untranslated: shipped lookup rows with no label for the locale,
 * `tt_config` text still at the English default, and demo generators with
 * no Dutch variant. Read-only.
 *
 * Registered behind `class_exists( 'WP_CLI' )` in I18nModule.
 */
final class DataI18nCliCommand {

    /**
     * List data that renders untranslated in a locale.
     *
     * ## OPTIONS
     *
     * [--locale=<locale>]
     * : The locale to check. Default nl_NL.
     *
     * ## EXAMPLES
     *
     *     wp tt i18n-data
     *     wp tt i18n-data --locale=de_DE
     *
     * @param array<int,string>   $args
     * @param array<string,mixed> $assoc_args
     */
    public function __invoke( $args, $assoc_args ): void {
        $locale = isset( $assoc_args['locale'] ) ? (string) $assoc_args['locale'] : 'nl_NL';
        \WP_CLI::line( rtrim( DataTranslationAudit::report( $locale, TT_PLUGIN_DIR ) ) );
    }
}
