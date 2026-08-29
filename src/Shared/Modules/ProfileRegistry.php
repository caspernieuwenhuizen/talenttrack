<?php
namespace TT\Shared\Modules;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * ProfileRegistry (#3035) — the shipped install profiles, read from
 * `config/profiles.php`.
 *
 * Stateless on purpose. This class knows what a profile *says*; it knows
 * nothing about what an install currently has switched on, and it never
 * writes. `ProfileService` is the half that compares the two and applies
 * the difference.
 *
 * Profiles are not runtime-editable — changing what Basics means is a
 * release, the same argument `FeatureMap` makes for entitlement.
 */
class ProfileRegistry {

    /**
     * Every shipped profile, keyed by slug, in declaration order.
     *
     * @return array<string, array{label:string, description:string, modules:array<string,bool>, features:array<string,bool>}>
     */
    public static function all(): array {
        $file = defined( 'TT_PLUGIN_DIR' ) ? TT_PLUGIN_DIR . 'config/profiles.php' : '';
        if ( $file === '' || ! is_readable( $file ) ) return [];

        /** @var mixed $declared */
        $declared = require $file;
        if ( ! is_array( $declared ) ) return [];

        $out = [];
        foreach ( $declared as $slug => $profile ) {
            if ( ! is_array( $profile ) ) continue;
            $out[ (string) $slug ] = [
                'label'       => (string) ( $profile['label'] ?? $slug ),
                'description' => (string) ( $profile['description'] ?? '' ),
                'modules'     => self::boolMap( $profile['modules'] ?? [] ),
                'features'    => self::boolMap( $profile['features'] ?? [] ),
            ];
        }
        return $out;
    }

    /**
     * One profile, or null when the slug names none.
     *
     * @return array{label:string, description:string, modules:array<string,bool>, features:array<string,bool>}|null
     */
    public static function get( string $slug ): ?array {
        $all = self::all();
        return $all[ $slug ] ?? null;
    }

    public static function exists( string $slug ): bool {
        return self::get( $slug ) !== null;
    }

    /**
     * Module classes are written with a leading-backslash-free FQCN
     * everywhere else in the codebase; normalise so a hand-edited profile
     * that writes `\TT\…` still lines up with `config/modules.php`.
     *
     * @param mixed $raw
     * @return array<string,bool>
     */
    private static function boolMap( $raw ): array {
        if ( ! is_array( $raw ) ) return [];
        $out = [];
        foreach ( $raw as $key => $value ) {
            $out[ ltrim( (string) $key, '\\' ) ] = (bool) $value;
        }
        return $out;
    }
}
