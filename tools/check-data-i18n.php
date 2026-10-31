<?php
/**
 * Data i18n report (#4511): text stored in data that a Dutch screen shows
 * in English. Read-only.
 *
 * Needs WordPress loaded, so it runs through WP-CLI:
 *
 *   wp eval-file wp-content/plugins/talenttrack/tools/check-data-i18n.php
 *   wp eval-file wp-content/plugins/talenttrack/tools/check-data-i18n.php de_DE
 *
 * The same report is `wp tt i18n-data [--locale=<locale>]`.
 */

if ( ! defined( 'ABSPATH' ) || ! class_exists( '\\TT\\Modules\\I18n\\DataTranslationAudit' ) ) {
    fwrite( STDERR, "check-data-i18n: run through WP-CLI: wp eval-file tools/check-data-i18n.php [locale]\n" );
    exit( 2 );
}

$tt_locale = isset( $args[0] ) && is_string( $args[0] ) && $args[0] !== '' ? $args[0] : 'nl_NL';
echo \TT\Modules\I18n\DataTranslationAudit::report( $tt_locale, dirname( __DIR__ ) );
