<?php
/**
 * The logic behind tools/check-settings-entries.php (#4670), kept apart so a
 * test can run it on a planted route.
 *
 * A settings route is:
 *   - sub:<value>  every `case` of the `?config_sub=` switch in
 *                  FrontendConfigurationView::render();
 *   - tile:<slug>  every `kind: setup` tile registered in a settings
 *                  section of config/navigation_groups.php;
 *   - view:<slug>  every tile-less `?tt_view=` slug the Configuration module
 *                  owns (`registerSlugOwnership( '<slug>', self::M_CONFIG )`).
 *
 * Each needs an entry in config/settings_entries.php, or a line in its
 * `not_entries` saying why not.
 */

declare( strict_types = 1 );

if ( ! function_exists( '__' ) ) {
    function __( string $text, string $domain = 'default' ): string { return $text; }
}
if ( ! function_exists( '_x' ) ) {
    function _x( string $text, string $context, string $domain = 'default' ): string { return $text; }
}

/** @return array<string,mixed> */
function tt_settings_require( string $file ): array {
    /** @var mixed $data */
    $data = require $file;
    return is_array( $data ) ? $data : [];
}

/**
 * Every settings route in the source tree.
 *
 * @return list<string>
 */
function tt_settings_routes( string $root ): array {
    $routes = [];

    $view = (string) @file_get_contents( $root . '/src/Shared/Frontend/FrontendConfigurationView.php' );
    $from = strpos( $view, 'switch ( $sub )' );
    $to   = $from === false ? false : strpos( $view, "self::renderHeader( __( 'Configuration'", $from );
    if ( $from !== false && $to !== false ) {
        preg_match_all( "/case '([a-z0-9_-]+)':/", substr( $view, $from, $to - $from ), $m );
        foreach ( $m[1] as $sub ) $routes[] = 'sub:' . $sub;
    }

    $groups   = tt_settings_require( $root . '/config/navigation_groups.php' );
    $settings = [];
    foreach ( $groups as $group ) {
        if ( is_array( $group ) && ( $group['surface'] ?? '' ) === 'settings' ) $settings[ (string) $group['key'] ] = true;
    }

    $it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/src', FilesystemIterator::SKIP_DOTS ) );
    foreach ( $it as $file ) {
        if ( ! $file->isFile() || substr( $file->getFilename(), -4 ) !== '.php' || $file->getFilename() === 'TileRegistry.php' ) continue;
        $code = (string) @file_get_contents( $file->getPathname() );

        if ( preg_match_all( "/registerSlugOwnership\(\s*'([a-z0-9-]+)'\s*,\s*self::M_CONFIG\s*\)/", $code, $own ) ) {
            foreach ( $own[1] as $slug ) $routes[] = 'view:' . $slug;
        }

        if ( strpos( $code, 'TileRegistry::register(' ) === false ) continue;
        preg_match_all( "/(\\\$[a-z_]+_group)\s*=\s*'([a-z_]+)';/", $code, $vm, PREG_SET_ORDER );
        $vars = [];
        foreach ( $vm as $v ) $vars[ $v[1] ] = $v[2];

        $parts = explode( 'TileRegistry::register(', $code );
        array_shift( $parts );
        foreach ( $parts as $part ) {
            $end  = strpos( $part, ']);' );
            $end2 = strpos( $part, '] );' );
            if ( $end === false || ( $end2 !== false && $end2 < $end ) ) $end = $end2;
            $block = $end === false ? $part : substr( $part, 0, $end );

            if ( ! preg_match( "/'kind'\s*=.\s*'setup'/", $block ) ) continue;
            $group = '';
            if ( preg_match( "/'group'\s*=.\s*'([a-z_]+)'/", $block, $g ) ) {
                $group = $g[1];
            } elseif ( preg_match( "/'group'\s*=.\s*(\\\$[a-z_]+)/", $block, $g ) ) {
                $group = $vars[ $g[1] ] ?? '';
            }
            if ( ! isset( $settings[ $group ] ) ) continue;

            $slug = preg_match( "/'view_slug'\s*=.\s*'([^']+)'/", $block, $s ) ? $s[1] : '';
            if ( $slug === '' && preg_match( "/'slug'\s*=.\s*'([^']+)'/", $block, $s ) ) $slug = $s[1];
            if ( $slug !== '' ) $routes[] = 'tile:' . $slug;
        }
    }

    $routes = array_values( array_unique( $routes ) );
    sort( $routes );
    return $routes;
}

/**
 * The routes the entries cover, plus the ones exempted by name.
 *
 * @param array<string,mixed> $config config/settings_entries.php
 * @return array<string,bool>
 */
function tt_settings_covered( array $config ): array {
    $covered = [];
    foreach ( (array) ( $config['entries'] ?? [] ) as $entry ) {
        if ( ! is_array( $entry ) ) continue;
        foreach ( [ 'sub', 'view', 'tile' ] as $field ) {
            $value = (string) ( $entry[ $field ] ?? '' );
            if ( $value === '' ) continue;
            $covered[ $field . ':' . $value ] = true;
            // A view entry and a tile entry reach the same screen.
            if ( $field === 'view' ) $covered[ 'tile:' . $value ] = true;
            if ( $field === 'tile' ) $covered[ 'view:' . $value ] = true;
        }
    }
    foreach ( array_keys( (array) ( $config['not_entries'] ?? [] ) ) as $route ) {
        $covered[ (string) $route ] = true;
    }
    return $covered;
}

/**
 * @param list<string>        $routes
 * @param array<string,mixed> $config
 * @return list<string> routes without an entry
 */
function tt_settings_missing( array $routes, array $config ): array {
    $covered = tt_settings_covered( $config );
    return array_values( array_filter( $routes, static fn ( string $r ): bool => ! isset( $covered[ $r ] ) ) );
}

/**
 * Entry keys used twice, or entries naming a section that is not a settings
 * section — both break "every setting once".
 *
 * @param array<string,mixed> $config
 * @param list<string>        $sections settings section keys
 * @return list<string>
 */
function tt_settings_problems( array $config, array $sections ): array {
    $problems = [];
    $seen     = [];
    foreach ( (array) ( $config['entries'] ?? [] ) as $entry ) {
        if ( ! is_array( $entry ) ) continue;
        $key = (string) ( $entry['key'] ?? '' );
        if ( $key === '' ) { $problems[] = 'an entry has no key'; continue; }
        if ( isset( $seen[ $key ] ) ) $problems[] = "entry '$key' is declared twice";
        $seen[ $key ] = true;
        if ( ! in_array( (string) ( $entry['section'] ?? '' ), $sections, true ) ) {
            $problems[] = "entry '$key' names section '" . (string) ( $entry['section'] ?? '' ) . "', which is not a settings section";
        }
    }
    return $problems;
}
