<?php
namespace TT\Core;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Lightweight service container.
 *
 * Stores either resolved instances or factory closures keyed by string.
 * Factories are resolved once and cached (singleton semantics).
 *
 * Usage:
 *   $c->bind('config', fn($c) => new ConfigService());
 *   $cfg = $c->get('config');
 */
class Container {

    /** @var array<string, callable|mixed> */
    private $bindings = [];

    /** @var array<string, mixed> */
    private $resolved = [];

    /**
     * Bind a factory (closure) or a concrete value to a key.
     */
    public function bind( string $key, $factory_or_value ): void {
        $this->bindings[ $key ] = $factory_or_value;
        unset( $this->resolved[ $key ] );
    }

    /**
     * Retrieve an entry. Factories are resolved once and cached.
     *
     * @return mixed
     */
    public function get( string $key ) {
        if ( array_key_exists( $key, $this->resolved ) ) {
            return $this->resolved[ $key ];
        }
        if ( ! array_key_exists( $key, $this->bindings ) ) {
            throw new \RuntimeException( sprintf( 'Container entry "%s" not found.', $key ) );
        }
        $entry = $this->bindings[ $key ];
        $value = is_callable( $entry ) ? $entry( $this ) : $entry;
        $this->resolved[ $key ] = $value;
        return $value;
    }

    public function has( string $key ): bool {
        return array_key_exists( $key, $this->bindings );
    }
}
