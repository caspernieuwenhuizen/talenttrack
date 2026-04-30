<?php
namespace TT\Modules\AdminCenterClient;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Infrastructure\Usage\UsageTracker;
use TT\Modules\License\FreemiusAdapter;

/**
 * PayloadBuilder — single source of truth for the phone-home payload
 * shape (#0065 / TTA #0001).
 *
 * Assembly is aggregation-only — no per-player rows, no free text,
 * no PII beyond `wp_options:admin_email`. The shape and the privacy
 * boundary are asserted in `tests/PayloadShapeTest.php` and
 * `tests/PayloadPrivacyTest.php`; if a future change leaks a
 * forbidden field, those tests fail the build.
 *
 * Schema-version stays at "1.0". New fields are append-only.
 */
final class PayloadBuilder {

    public const PROTOCOL_VERSION = '1.0';

    public const TRIGGER_DAILY           = 'daily';
    public const TRIGGER_ACTIVATED       = 'activated';
    public const TRIGGER_DEACTIVATED     = 'deactivated';
    public const TRIGGER_VERSION_CHANGED = 'version_changed';

    public static function build( string $trigger ): array {
        global $wpdb;

        $install_id = InstallId::get();
        $site_url   = self::siteUrl();

        return [
            'protocol_version' => self::PROTOCOL_VERSION,
            'install_id'       => $install_id,
            'trigger'          => $trigger,
            'sent_at'          => gmdate( 'Y-m-d\TH:i:s\Z' ),

            'site_url'                  => $site_url,
            'contact_email'             => (string) get_option( 'admin_email', '' ),
            'freemius_license_key_hash' => self::freemiusLicenseKeyHash(),

            'plugin_version' => defined( 'TT_VERSION' ) ? (string) TT_VERSION : '',
            'wp_version'     => self::wpVersion(),
            'php_version'    => PHP_VERSION,
            'db_version'     => self::dbVersion( $wpdb ),
            'locale'         => (string) get_locale(),
            'timezone'       => (string) wp_timezone_string(),

            'club_count'             => self::clubCount(),
            'team_count'             => self::countTable( $wpdb, 'tt_teams' ),
            'player_count_active'    => self::activePlayerCount( $wpdb ),
            'player_count_archived'  => self::archivedPlayerCount( $wpdb ),
            'staff_count'            => self::activeStaffCount( $wpdb ),
            'dau_7d_avg'             => self::dau7dAvg(),
            'wau_count'              => UsageTracker::uniqueActiveUsers( 7 ),
            'mau_count'              => UsageTracker::uniqueActiveUsers( 30 ),
            'last_login_date'        => self::lastLoginDate(),

            'error_counts_24h'      => self::errorCounts24h(),
            'error_count_total_24h' => self::errorTotal24h(),

            'license_tier'      => self::licenseTier(),
            'license_status'    => self::licenseStatus(),
            'license_renews_at' => self::licenseRenewsAt(),

            'module_status' => [
                'spond'   => self::moduleStatusSpond(),
                'comms'   => self::moduleStatusComms(),
                'exports' => self::moduleStatusExports(),
            ],

            'feature_flags_enabled' => self::featureFlagsEnabled(),
            'custom_caps_in_use'    => self::customCapsInUse(),
        ];
    }

    private static function siteUrl(): string {
        return rtrim( (string) get_site_url(), '/' );
    }

    private static function wpVersion(): string {
        global $wp_version;
        return (string) ( $wp_version ?? '' );
    }

    private static function dbVersion( $wpdb ): string {
        if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'db_version' ) ) {
            return '';
        }
        $v = (string) $wpdb->db_version();
        return $v !== '' ? 'mysql ' . $v : '';
    }

    private static function clubCount(): int {
        return 1;
    }

    private static function countTable( $wpdb, string $table ): int {
        if ( ! is_object( $wpdb ) ) return 0;
        $tbl = $wpdb->prefix . $table;
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tbl}" );
    }

    private static function activePlayerCount( $wpdb ): int {
        $tbl = $wpdb->prefix . 'tt_players';
        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$tbl} WHERE archived_at IS NULL"
        );
    }

    private static function archivedPlayerCount( $wpdb ): int {
        $tbl = $wpdb->prefix . 'tt_players';
        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$tbl} WHERE archived_at IS NOT NULL"
        );
    }

    private static function activeStaffCount( $wpdb ): int {
        $tbl = $wpdb->prefix . 'tt_people';
        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$tbl} WHERE archived_at IS NULL AND status = 'active'"
        );
    }

    private static function dau7dAvg(): float {
        $series = UsageTracker::dailyActiveUsers( 7 );
        if ( ! is_array( $series ) || empty( $series ) ) return 0.0;
        $total = 0;
        foreach ( $series as $row ) {
            if ( is_array( $row ) ) {
                $total += (int) ( $row['count'] ?? 0 );
            }
        }
        return round( $total / count( $series ), 2 );
    }

    private static function lastLoginDate(): ?string {
        global $wpdb;
        $tbl = $wpdb->prefix . 'tt_usage_events';
        $val = $wpdb->get_var(
            "SELECT MAX(DATE(created_at)) FROM {$tbl} WHERE event_type = 'login'"
        );
        return is_string( $val ) && $val !== '' ? $val : null;
    }

    /**
     * Top error classes by count over the last 24h. Source: tt_audit_log
     * rows whose action begins with `error.` — same convention TT's
     * `Logger::error()` writes. Names only, no message bodies.
     */
    private static function errorCounts24h(): array {
        global $wpdb;
        $tbl = $wpdb->prefix . 'tt_audit_log';
        $rows = $wpdb->get_results(
            "SELECT action, COUNT(*) AS c
               FROM {$tbl}
              WHERE action LIKE 'error.%'
                AND created_at >= DATE_SUB( UTC_TIMESTAMP(), INTERVAL 1 DAY )
              GROUP BY action
              ORDER BY c DESC
              LIMIT 20",
            ARRAY_A
        );
        $out = [];
        if ( is_array( $rows ) ) {
            foreach ( $rows as $r ) {
                $key = (string) ( $r['action'] ?? '' );
                if ( $key !== '' ) {
                    $out[ $key ] = (int) ( $r['c'] ?? 0 );
                }
            }
        }
        return $out;
    }

    private static function errorTotal24h(): int {
        return (int) array_sum( self::errorCounts24h() );
    }

    /**
     * SHA-256 of the Freemius license key, or null when no license is
     * present. v1's HMAC secret does *not* depend on this — it's
     * informational only, used by future billing-oversight to
     * reconcile against Freemius. The receiver accepts null.
     */
    private static function freemiusLicenseKeyHash(): ?string {
        if ( ! class_exists( FreemiusAdapter::class ) ) return null;
        if ( ! FreemiusAdapter::isConfigured() ) return null;
        $key = (string) apply_filters( 'tt_freemius_license_key', '' );
        if ( $key === '' ) return null;
        return hash( 'sha256', $key );
    }

    private static function licenseTier(): ?string {
        if ( ! class_exists( FreemiusAdapter::class ) || ! FreemiusAdapter::isConfigured() ) {
            return null;
        }
        $tier = (string) FreemiusAdapter::tier();
        return $tier !== '' ? $tier : null;
    }

    private static function licenseStatus(): ?string {
        if ( ! class_exists( FreemiusAdapter::class ) || ! FreemiusAdapter::isConfigured() ) {
            return null;
        }
        $status = (string) apply_filters( 'tt_freemius_license_status', '' );
        return $status !== '' ? $status : null;
    }

    private static function licenseRenewsAt(): ?string {
        if ( ! class_exists( FreemiusAdapter::class ) || ! FreemiusAdapter::isConfigured() ) {
            return null;
        }
        $renews = (string) apply_filters( 'tt_freemius_license_renews_at', '' );
        return $renews !== '' ? $renews : null;
    }

    /**
     * `module_status.spond` populates once #0062's Spond JSON-API
     * fetcher ships and an install configures it. Until then the
     * receiver gets `null` — accepted per TTA #0001.
     */
    private static function moduleStatusSpond(): ?array {
        global $wpdb;
        $tbl = $wpdb->prefix . 'tt_spond_state';
        $exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s",
                $tbl
            )
        );
        if ( ! $exists ) return null;

        $configured     = (bool) $wpdb->get_var( "SELECT 1 FROM {$tbl} LIMIT 1" );
        $last_status    = (string) ( $wpdb->get_var( "SELECT last_sync_status FROM {$tbl} ORDER BY id DESC LIMIT 1" ) ?: 'never' );
        $last_at        = $wpdb->get_var( "SELECT last_sync_at FROM {$tbl} ORDER BY id DESC LIMIT 1" );
        $events_synced  = (int) ( $wpdb->get_var(
            "SELECT COALESCE(SUM(events_synced), 0) FROM {$tbl} WHERE last_sync_at >= DATE_SUB( UTC_TIMESTAMP(), INTERVAL 7 DAY )"
        ) ?: 0 );

        return [
            'configured'       => $configured,
            'last_sync_status' => $last_status,
            'last_sync_at'     => is_string( $last_at ) && $last_at !== '' ? $last_at : null,
            'events_synced_7d' => $events_synced,
        ];
    }

    private static function moduleStatusComms(): ?array {
        global $wpdb;
        $tbl = $wpdb->prefix . 'tt_comms_sends';
        $exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s",
                $tbl
            )
        );
        if ( ! $exists ) return null;

        $sends = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$tbl} WHERE created_at >= DATE_SUB( UTC_TIMESTAMP(), INTERVAL 7 DAY )"
        );
        return [ 'sends_7d' => $sends ];
    }

    private static function moduleStatusExports(): ?array {
        global $wpdb;
        $tbl = $wpdb->prefix . 'tt_export_runs';
        $exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s",
                $tbl
            )
        );
        if ( ! $exists ) return null;

        $runs = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$tbl} WHERE created_at >= DATE_SUB( UTC_TIMESTAMP(), INTERVAL 7 DAY )"
        );
        return [ 'runs_7d' => $runs ];
    }

    /**
     * TT's own feature flag names (not custom-cap names). The list is
     * a known, fixed vocabulary so it doesn't leak business logic.
     */
    private static function featureFlagsEnabled(): array {
        $known = [
            'persona_dashboard',
            'wizards',
            'theme_inheritance',
            'custom_css_frontend',
            'custom_css_admin',
        ];
        $out = [];
        foreach ( $known as $flag ) {
            if ( get_option( 'tt_feature_' . $flag ) === '1' ) {
                $out[] = $flag;
            }
        }
        return $out;
    }

    /**
     * True if any custom (non-TT-shipped) capability is detected on a
     * WP role. Intentionally returns boolean only: cap *names* could
     * leak business logic ("ttacme_secret_thing"), the operator only
     * needs to know "is this install diverging from defaults".
     */
    private static function customCapsInUse(): bool {
        $tt_caps_prefix = 'tt_';
        $roles          = wp_roles()->roles ?? [];
        foreach ( $roles as $role ) {
            $caps = is_array( $role['capabilities'] ?? null ) ? $role['capabilities'] : [];
            foreach ( $caps as $cap => $granted ) {
                if ( ! $granted ) continue;
                if ( str_starts_with( (string) $cap, $tt_caps_prefix ) ) continue;
                if ( in_array( $cap, [ 'read', 'edit_posts', 'manage_options', 'switch_themes', 'edit_themes', 'activate_plugins', 'edit_plugins', 'edit_users', 'edit_files', 'manage_categories', 'moderate_comments', 'unfiltered_html', 'upload_files', 'level_0', 'level_1', 'level_2', 'level_3', 'level_4', 'level_5', 'level_6', 'level_7', 'level_8', 'level_9', 'level_10' ], true ) ) continue;
                if ( strpos( (string) $cap, 'edit_' ) === 0 || strpos( (string) $cap, 'delete_' ) === 0 || strpos( (string) $cap, 'publish_' ) === 0 || strpos( (string) $cap, 'read_' ) === 0 ) continue;
                return true;
            }
        }
        return false;
    }
}
