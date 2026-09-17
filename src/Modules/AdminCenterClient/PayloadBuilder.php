<?php
namespace TT\Modules\AdminCenterClient;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Infrastructure\Usage\UsageTracker;
use TT\Modules\License\Entitlement;

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
            // Always null since Freemius was retired — an install holds
            // no marketplace license key to hash. The key itself stays
            // because the v1 shape is locked and exhaustive
            // (tests/fixtures/admin-center-payload.schema.php), so
            // dropping it would break the receiver's contract. The HMAC
            // secret never depended on it.
            'freemius_license_key_hash' => null,

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
            // Subscription status and renewal date live with the control
            // plane, not on the install. Kept for schema compatibility;
            // the receiver knows both better than we do.
            'license_status'    => null,
            'license_renews_at' => null,

            'module_status' => [
                'spond'   => self::moduleStatusSpond(),
                'comms'   => self::moduleStatusComms(),
                'exports' => self::moduleStatusExports(),
            ],

            'feature_flags_enabled' => self::featureFlagsEnabled(),
            'custom_caps_in_use'    => self::customCapsInUse(),

            // #3494 — what the club records, not whether anybody logged
            // in. Club-wide counts only: never a player id, a name or text.
            'value_signals' => self::valueSignals( $wpdb ),
        ];
    }

    /**
     * #3494 — five club-wide counts of recorded work, for the control
     * plane's health score.
     *
     * `dau_7d_avg` and friends answer "is anybody logging in", which is not
     * what a renewal turns on: three coaches signing in weekly and recording
     * nothing is a club that is leaving. These count the records the
     * product exists to hold.
     *
     * **Counts only.** Every value is a single integer over the whole club.
     * "Which players have no evaluation" is a genuinely useful operator
     * question, and it is not one the mothership may answer — the privacy
     * self-check asserts this block carries nothing but integers.
     *
     * A missing table (an install that has not reached a migration) counts
     * as 0 rather than failing the ping.
     *
     * @return array{attendance_recorded_30d:int, minutes_recorded_30d:int, evaluations_recorded_30d:int, pdps_active:int, goals_active:int}
     */
    public static function valueSignals( $wpdb ): array {
        $out = [
            'attendance_recorded_30d'  => 0,
            'minutes_recorded_30d'     => 0,
            'evaluations_recorded_30d' => 0,
            'pdps_active'              => 0,
            'goals_active'             => 0,
        ];
        if ( ! is_object( $wpdb ) ) return $out;

        $p     = $wpdb->prefix;
        $since = gmdate( 'Y-m-d', time() - 30 * DAY_IN_SECONDS );

        // A register is `record_type = 'actual'`: a planned roster is not
        // a register, and counting it would call a club that only plans
        // its trainings a club that records them.
        if ( self::tableExists( $wpdb, 'tt_attendance' ) && self::tableExists( $wpdb, 'tt_activities' ) ) {
            $out['attendance_recorded_30d'] = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(DISTINCT act.id)
                   FROM {$p}tt_activities act
                   JOIN {$p}tt_attendance att ON att.activity_id = act.id AND att.record_type = 'actual'
                  WHERE act.archived_at IS NULL AND act.session_date >= %s AND act.session_date <= %s",
                $since,
                gmdate( 'Y-m-d' )
            ) );
            $out['minutes_recorded_30d'] = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(DISTINCT act.id)
                   FROM {$p}tt_activities act
                   JOIN {$p}tt_attendance att ON att.activity_id = act.id AND att.record_type = 'actual' AND att.minutes_played IS NOT NULL
                  WHERE act.archived_at IS NULL AND act.session_date >= %s AND act.session_date <= %s",
                $since,
                gmdate( 'Y-m-d' )
            ) );
        }

        if ( self::tableExists( $wpdb, 'tt_evaluations' ) ) {
            $out['evaluations_recorded_30d'] = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$p}tt_evaluations WHERE archived_at IS NULL AND created_at >= %s",
                $since . ' 00:00:00'
            ) );
        }

        if ( self::tableExists( $wpdb, 'tt_pdp_files' ) ) {
            $out['pdps_active'] = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$p}tt_pdp_files WHERE archived_at IS NULL AND status NOT IN ('completed','archived')"
            );
        }

        // The stored status, not a derived bucket: the two have disagreed
        // before (#3396). Open is anything not completed or cancelled.
        if ( self::tableExists( $wpdb, 'tt_goals' ) ) {
            $out['goals_active'] = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$p}tt_goals WHERE archived_at IS NULL AND ( status IS NULL OR status NOT IN ('completed','cancelled') )"
            );
        }

        return $out;
    }

    private static function tableExists( $wpdb, string $table ): bool {
        return (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . $table ) ) === $wpdb->prefix . $table;
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
     * The tier this install is entitled to, as last answered by the
     * control plane — null when no entitlement has been recorded. This
     * is what the install believes it bought, which is exactly what
     * the Admin Center wants to reconcile against.
     */
    private static function licenseTier(): ?string {
        if ( ! class_exists( Entitlement::class ) ) return null;
        return Entitlement::tier();
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
