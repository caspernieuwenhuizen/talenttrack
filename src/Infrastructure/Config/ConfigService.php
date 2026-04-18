<?php
namespace TT\Infrastructure\Config;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * ConfigService — reads and writes key-value config from tt_config.
 *
 * Replaces the scattered static helpers from v1.x. Injected via the container.
 */
class ConfigService {

    /** @var array<string,string> */
    private $cache = [];

    public function get( string $key, string $default = '' ): string {
        if ( array_key_exists( $key, $this->cache ) ) {
            return $this->cache[ $key ];
        }
        global $wpdb;
        /** @var string|null $val */
        $val = $wpdb->get_var( $wpdb->prepare(
            "SELECT config_value FROM {$wpdb->prefix}tt_config WHERE config_key = %s", $key
        ));
        $result = ( $val !== null ) ? (string) $val : $default;
        $this->cache[ $key ] = $result;
        return $result;
    }

    public function set( string $key, string $value ): void {
        global $wpdb;
        $wpdb->replace( $wpdb->prefix . 'tt_config', [
            'config_key' => $key, 'config_value' => $value,
        ]);
        $this->cache[ $key ] = $value;
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
