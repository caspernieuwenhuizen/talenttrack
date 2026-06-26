<?php
namespace TT\Modules\TeamDevelopment\Repositories;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Config\ConfigService;

/**
 * ChemistryConfig (#1912) — the five component weights for the reworked
 * pair-chemistry formula, stored in tt_config (CLAUDE.md tenant-config
 * rule) rather than a bespoke table.
 *
 * Weights: Compatibility / Familiarity / Development / Behaviour /
 * Performance. Default 35 / 25 / 10 / 15 / 15 (sum 100).
 */
class ChemistryConfig {

    private const KEY = 'chemistry_component_weights';

    public const COMPONENTS = [ 'compatibility', 'familiarity', 'development', 'behaviour', 'performance' ];

    private const DEFAULTS = [
        'compatibility' => 35,
        'familiarity'   => 25,
        'development'   => 10,
        'behaviour'     => 15,
        'performance'   => 15,
    ];

    private ConfigService $config;

    public function __construct( ?ConfigService $config = null ) {
        $this->config = $config ?? new ConfigService();
    }

    /**
     * @return array<string, int> component => weight (sums to 100)
     */
    public function weights(): array {
        $stored = $this->config->getJson( self::KEY, [] );
        if ( empty( $stored ) ) return self::DEFAULTS;

        $out = [];
        foreach ( self::COMPONENTS as $c ) {
            $out[ $c ] = isset( $stored[ $c ] ) ? (int) $stored[ $c ] : self::DEFAULTS[ $c ];
        }
        return $this->normalise( $out );
    }

    /**
     * Persist weights, normalised to sum 100.
     *
     * @param array<string, mixed> $weights
     */
    public function saveWeights( array $weights ): array {
        $clean = [];
        foreach ( self::COMPONENTS as $c ) {
            $clean[ $c ] = max( 0, (int) ( $weights[ $c ] ?? 0 ) );
        }
        $clean = $this->normalise( $clean );
        $this->config->set( self::KEY, (string) wp_json_encode( $clean ) );
        return $clean;
    }

    /**
     * Scale to sum 100 (preserving ratios); falls back to defaults when the
     * input sums to zero.
     *
     * @param array<string, int> $weights
     * @return array<string, int>
     */
    private function normalise( array $weights ): array {
        $sum = array_sum( $weights );
        if ( $sum <= 0 ) return self::DEFAULTS;
        if ( $sum === 100 ) return $weights;

        $out   = [];
        $running = 0;
        $last  = array_key_last( $weights );
        foreach ( $weights as $k => $v ) {
            if ( $k === $last ) {
                $out[ $k ] = 100 - $running; // absorb rounding into the last
            } else {
                $out[ $k ] = (int) round( $v / $sum * 100 );
                $running  += $out[ $k ];
            }
        }
        return $out;
    }
}
