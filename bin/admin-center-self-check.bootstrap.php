<?php
/**
 * bin/admin-center-self-check.bootstrap.php (#0065 / TTA #0001)
 *
 * Lightweight WP-API stubs so admin-center-self-check.php can run
 * without booting WordPress. Each stub returns a value shaped the
 * way PayloadBuilder expects to consume.
 */

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! defined( 'TT_VERSION' ) )    define( 'TT_VERSION', '3.65.0-test' );
if ( ! defined( 'DAY_IN_SECONDS' ) ) define( 'DAY_IN_SECONDS', 86400 );
if ( ! defined( 'ARRAY_A' ) )         define( 'ARRAY_A', 'ARRAY_A' );
if ( ! defined( 'ARRAY_N' ) )         define( 'ARRAY_N', 'ARRAY_N' );
if ( ! defined( 'OBJECT' ) )          define( 'OBJECT',  'OBJECT' );

global $wp_options_stub, $wp_version, $wpdb, $wp_roles_stub;
$wp_options_stub = [
    'admin_email'           => 'admin@example.test',
    'tt_install_id'         => 'a1b2c3d4-1234-4abc-89de-1234567890ab',
    'tt_last_phoned_version' => TT_VERSION,
];
$wp_version = '6.7.1';

// ---- Tiny wpdb stub -------------------------------------------------

if ( ! class_exists( 'WpdbStub' ) ) {
    class WpdbStub {
        public string $prefix = 'wp_';
        public function db_version(): string { return '8.0.34'; }
        public function get_var( $sql ) {
            $sql = (string) $sql;
            if ( stripos( $sql, 'information_schema.tables' ) !== false ) return '0';
            if ( stripos( $sql, 'MAX(DATE(created_at))' ) !== false )      return '2026-04-29';
            if ( preg_match( '/SELECT COUNT\(\*\)/i', $sql ) )             return '0';
            if ( preg_match( '/SELECT SUM/i', $sql ) )                     return '0';
            return null;
        }
        public function get_results( $sql, $output = null ) { return []; }
        public function prepare( $sql ) { return $sql; }
    }
}
$wpdb = new WpdbStub();

// ---- WP function stubs ---------------------------------------------

if ( ! function_exists( 'get_option' ) ) {
    function get_option( $key, $default = false ) {
        global $wp_options_stub;
        return $wp_options_stub[ $key ] ?? $default;
    }
}
if ( ! function_exists( 'update_option' ) ) {
    function update_option( $key, $value, $autoload = null ) {
        global $wp_options_stub;
        $wp_options_stub[ $key ] = $value;
        return true;
    }
}
if ( ! function_exists( 'get_locale' ) )       { function get_locale() { return 'nl_NL'; } }
if ( ! function_exists( 'wp_timezone_string' ) ) { function wp_timezone_string() { return 'Europe/Amsterdam'; } }
if ( ! function_exists( 'get_site_url' ) )     { function get_site_url() { return 'https://academy.example.test'; } }
if ( ! function_exists( 'apply_filters' ) ) {
    function apply_filters( $hook, $value ) { return $value; }
}

// wp_roles() — minimal stub returning roles with no custom caps.
if ( ! function_exists( 'wp_roles' ) ) {
    function wp_roles() {
        global $wp_roles_stub;
        if ( ! $wp_roles_stub ) {
            $wp_roles_stub = (object) [
                'roles' => [
                    'administrator' => [
                        'capabilities' => [ 'read' => true, 'edit_posts' => true, 'manage_options' => true ],
                    ],
                ],
            ];
        }
        return $wp_roles_stub;
    }
}

if ( ! function_exists( 'wp_json_encode' ) ) {
    function wp_json_encode( $data, $options = 0, $depth = 512 ) {
        return json_encode( $data, $options, $depth );
    }
}

// ---- TT helpers reached for indirectly -----------------------------

if ( ! class_exists( 'TT\\Infrastructure\\Tenancy\\CurrentClub' ) ) {
    eval( 'namespace TT\\Infrastructure\\Tenancy; class CurrentClub { public static function id(): int { return 1; } }' );
}
if ( ! class_exists( 'TT\\Infrastructure\\Usage\\UsageTracker' ) ) {
    eval( '
        namespace TT\\Infrastructure\\Usage;
        class UsageTracker {
            public static function uniqueActiveUsers( int $days ): int { return 0; }
            public static function dailyActiveUsers( int $days ): array { return [ [ "date" => "2026-04-29", "count" => 0 ] ]; }
        }
    ' );
}
if ( ! class_exists( 'TT\\Modules\\License\\FreemiusAdapter' ) ) {
    eval( '
        namespace TT\\Modules\\License;
        class FreemiusAdapter {
            public static function isConfigured(): bool { return false; }
            public static function tier(): string { return "free"; }
        }
    ' );
}
if ( ! class_exists( 'TT\\Infrastructure\\Logging\\Logger' ) ) {
    eval( '
        namespace TT\\Infrastructure\\Logging;
        class Logger {
            public function warning( string $msg, array $ctx = [] ): void {}
            public function error( string $msg, array $ctx = [] ): void {}
        }
    ' );
}
