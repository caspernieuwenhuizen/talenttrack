<?php
namespace TT\Infrastructure\Config;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * ConfigService — reads and writes key-value config from tt_config.
 *
 * Replaces the scattered static helpers from v1.x. Injected via the container.
 *
 * Tenant-scoped via composite primary key `(club_id, config_key)` since
 * #0052 PR-A. `CurrentClub::id()` returns `1` today; a future SaaS auth
 * layer hooks the `tt_current_club_id` filter and this class will pick
 * up per-tenant values without code changes.
 */
class ConfigService {

    /** @var array<string,string> */
    private $cache = [];

    public function get( string $key, string $default = '' ): string {
        $cache_key = $this->cacheKey( $key );
        if ( array_key_exists( $cache_key, $this->cache ) ) {
            return $this->cache[ $cache_key ];
        }
        global $wpdb;
        /** @var string|null $val */
        $val = $wpdb->get_var( $wpdb->prepare(
            "SELECT config_value FROM {$wpdb->prefix}tt_config
              WHERE club_id = %d AND config_key = %s",
            CurrentClub::id(), $key
        ));
        $result = ( $val !== null ) ? (string) $val : $default;
        $this->cache[ $cache_key ] = $result;
        return $result;
    }

    public function set( string $key, string $value ): void {
        global $wpdb;
        $wpdb->replace( $wpdb->prefix . 'tt_config', [
            'club_id'      => CurrentClub::id(),
            'config_key'   => $key,
            'config_value' => $value,
        ]);
        $this->cache[ $this->cacheKey( $key ) ] = $value;
    }

    /**
     * Per-club cache namespace so multiple clubs in the same request
     * (test or future SaaS) don't return stale reads.
     */
    private function cacheKey( string $config_key ): string {
        return CurrentClub::id() . ':' . $config_key;
    }

    /**
     * Read a JSON-encoded value and decode.
     *
     * @return array<mixed>
     */
    public function getJson( string $key, array $default = [] ): array {
        $val = $this->get( $key, '' );
        $decoded = json_decode( $val, true );
        return is_array( $decoded ) ? $decoded : $default;
    }

    public function getFloat( string $key, float $default = 0.0 ): float {
        return (float) $this->get( $key, (string) $default );
    }

    public function getInt( string $key, int $default = 0 ): int {
        return (int) $this->get( $key, (string) $default );
    }

    public function getBool( string $key, bool $default = false ): bool {
        $val = $this->get( $key, $default ? '1' : '0' );
        return $val === '1' || strtolower( $val ) === 'true';
    }
}
