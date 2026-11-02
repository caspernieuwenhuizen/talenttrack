<?php
namespace TT\Shared\Configuration;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\ModuleRegistry;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Shared\Frontend\Components\RecordLink;
use TT\Shared\Tiles\NavigationGroups;
use TT\Shared\Tiles\TileRegistry;

/**
 * SettingsRegistry (#4670) — every settings screen, tab and notable setting
 * in one list, each filed under one of the ten Configuration sections.
 *
 * The Configuration landing, its filter box, `GET /settings-entries` and the
 * global search all read it, so a setting is found in the same place by all
 * of them. The core entries live in `config/settings_entries.php`; a module
 * may add its own with {@see register()}. Entries contributed through the
 * deprecated `tt_config_tile_groups` filter are adapted into the `academy`
 * section, unless an entry already leads to the same screen.
 *
 * Access is decided here, not in the view: an entry the viewer may not open
 * is never returned. A switched-off module's entries are returned only to a
 * viewer who may switch modules, marked off, under Features & plan.
 */
final class SettingsRegistry {

    /** Module state of an entry that belongs to no module. */
    public const STATE_NONE = '';
    public const STATE_ON   = 'on';
    public const STATE_OFF  = 'off';

    /** A section with fewer entries than this folds into the next one. */
    public const MIN_SECTION_SIZE = 3;

    /** Sections that only an academy administrator sees. */
    private const SECTION_CAPS = [ 'system' => 'tt_edit_settings' ];

    /** Where a switched-off module's entries are listed, and who may see them. */
    private const OFF_SECTION = 'features_plan';
    private const OFF_CAP     = 'tt_manage_modules';

    /** @var list<array<string,mixed>> Entries added by modules. */
    private static array $registered = [];

    /**
     * Add an entry. Same shape as an entry in `config/settings_entries.php`.
     * A key already taken, or a section that is not a settings section, is
     * ignored: a setting has one home.
     *
     * @param array<string,mixed> $entry
     */
    public static function register( array $entry ): void {
        $key     = (string) ( $entry['key'] ?? '' );
        $section = (string) ( $entry['section'] ?? '' );
        if ( $key === '' || NavigationGroups::surface( $section ) !== NavigationGroups::SURFACE_SETTINGS ) return;
        foreach ( self::declared() as $existing ) {
            if ( (string) $existing['key'] === $key ) return;
        }
        self::$registered[] = $entry;
    }

    /** Drop module-registered entries. Tests use this between scenarios. */
    public static function clear(): void {
        self::$registered = [];
    }

    /** @return array<string,mixed> The parsed `config/settings_entries.php`. */
    public static function config(): array {
        $file = defined( 'TT_PLUGIN_DIR' ) ? TT_PLUGIN_DIR . 'config/settings_entries.php' : '';
        if ( $file === '' || ! is_readable( $file ) ) return [];
        /** @var mixed $declared */
        $declared = require $file;
        return is_array( $declared ) ? $declared : [];
    }

    /**
     * Every declared entry, core first, before access is applied.
     *
     * @return list<array<string,mixed>>
     */
    public static function declared(): array {
        $config  = self::config();
        $entries = is_array( $config['entries'] ?? null ) ? $config['entries'] : [];
        $out     = [];
        foreach ( $entries as $entry ) {
            if ( is_array( $entry ) ) $out[] = $entry;
        }
        return array_merge( $out, self::$registered );
    }

    /** The question a settings section answers, or ''. */
    public static function question( string $section ): string {
        $config    = self::config();
        $questions = is_array( $config['sections'] ?? null ) ? $config['sections'] : [];
        return (string) ( $questions[ $section ] ?? '' );
    }

    /**
     * The entries this viewer may open, in section order then declaration
     * order. Each: key, section, label, description, keywords, url,
     * module_state and external.
     *
     * @return list<array{key:string, section:string, label:string, description:string, keywords:string, url:string, module_state:string, external:bool}>
     */
    public static function entriesFor( int $user_id ): array {
        $setup = self::visibleSetupTiles( $user_id );
        $out   = [];
        $taken = [];

        foreach ( self::declared() as $entry ) {
            $resolved = self::resolve( $entry, $user_id, $setup );
            if ( $resolved === null || isset( $taken[ $resolved['key'] ] ) ) continue;
            $taken[ $resolved['key'] ] = true;
            $out[] = $resolved;
        }
        foreach ( self::contributed( $user_id ) as $resolved ) {
            $out[] = $resolved;
        }

        $order = array_flip( NavigationGroups::keysFor( NavigationGroups::SURFACE_SETTINGS ) );
        $index = array_flip( array_column( $out, 'key' ) );
        usort( $out, static function ( array $a, array $b ) use ( $order, $index ): int {
            $s = ( $order[ $a['section'] ] ?? 99 ) <=> ( $order[ $b['section'] ] ?? 99 );
            return $s !== 0 ? $s : ( $index[ $a['key'] ] <=> $index[ $b['key'] ] );
        } );
        return $out;
    }

    /**
     * The entries grouped for the Configuration landing: the settings
     * sections in order, each with its label and question. A section with
     * fewer than MIN_SECTION_SIZE entries folds into the next one (the last
     * into the one before), so nobody sees a section holding one card.
     *
     * @return list<array{key:string, label:string, question:string, entries:list<array<string,mixed>>}>
     */
    public static function sectionsFor( int $user_id ): array {
        $by_section = [];
        foreach ( self::entriesFor( $user_id ) as $entry ) {
            $by_section[ $entry['section'] ][] = $entry;
        }

        $sections = [];
        foreach ( NavigationGroups::keysFor( NavigationGroups::SURFACE_SETTINGS ) as $key ) {
            if ( empty( $by_section[ $key ] ) ) continue;
            $sections[] = [
                'key'      => $key,
                'label'    => NavigationGroups::label( $key ),
                'question' => self::question( $key ),
                'entries'  => $by_section[ $key ],
            ];
        }
        return self::fold( $sections );
    }

    /**
     * @param list<array{key:string, label:string, question:string, entries:list<array<string,mixed>>}> $sections
     * @return list<array{key:string, label:string, question:string, entries:list<array<string,mixed>>}>
     */
    public static function fold( array $sections ): array {
        if ( count( $sections ) < 2 ) return $sections;

        $out   = [];
        $carry = [];
        foreach ( $sections as $section ) {
            $section['entries'] = array_merge( $carry, $section['entries'] );
            $carry              = [];
            if ( count( $section['entries'] ) < self::MIN_SECTION_SIZE ) {
                $carry = $section['entries'];
                continue;
            }
            $out[] = $section;
        }
        if ( $carry !== [] ) {
            if ( $out === [] ) {
                $last            = $sections[ count( $sections ) - 1 ];
                $last['entries'] = $carry;
                $out[]           = $last;
            } else {
                $out[ count( $out ) - 1 ]['entries'] = array_merge( $out[ count( $out ) - 1 ]['entries'], $carry );
            }
        }
        return $out;
    }

    /**
     * Does the entry match what was typed? Label, description, keywords and
     * the section name, without regard to case or accents.
     *
     * @param array<string,mixed> $entry
     */
    public static function matches( array $entry, string $query ): bool {
        $needle = self::foldText( trim( $query ) );
        if ( $needle === '' ) return true;
        return strpos( self::haystack( $entry ), $needle ) !== false;
    }

    /**
     * The folded text an entry is matched against. The view puts it on the
     * row so the filter box needs no second copy of the rule.
     *
     * @param array<string,mixed> $entry
     */
    public static function haystack( array $entry ): string {
        return self::foldText( implode( ' ', [
            (string) ( $entry['label'] ?? '' ),
            (string) ( $entry['description'] ?? '' ),
            (string) ( $entry['keywords'] ?? '' ),
            NavigationGroups::label( (string) ( $entry['section'] ?? '' ) ),
        ] ) );
    }

    /** Lowercase, accents removed, whitespace collapsed. */
    public static function foldText( string $text ): string {
        $text = function_exists( 'remove_accents' ) ? remove_accents( $text ) : $text;
        $text = function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
        return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
    }

    // ---- resolution ----

    /**
     * @param array<string,mixed>                $entry
     * @param array<string,array<string,mixed>>  $setup visible setup tiles by slug
     * @return array{key:string, section:string, label:string, description:string, keywords:string, url:string, module_state:string, external:bool}|null
     */
    private static function resolve( array $entry, int $user_id, array $setup ): ?array {
        $key     = (string) ( $entry['key'] ?? '' );
        $section = (string) ( $entry['section'] ?? '' );
        if ( $key === '' || NavigationGroups::surface( $section ) !== NavigationGroups::SURFACE_SETTINGS ) return null;

        $tile_slug = (string) ( $entry['tile'] ?? '' );
        $tile      = $tile_slug !== '' ? self::registeredTile( $tile_slug ) : null;
        if ( $tile_slug !== '' && $tile === null ) return null;

        $module = $tile !== null ? (string) ( $tile['module_class'] ?? '' ) : (string) ( $entry['module'] ?? '' );
        $module = ltrim( $module, '\\' );
        $state  = $module === '' ? self::STATE_NONE : ( ModuleRegistry::isEnabled( $module ) ? self::STATE_ON : self::STATE_OFF );

        if ( $tile !== null ) {
            $labels = is_array( $tile['labels'] ?? null ) ? $tile['labels'] : [];
            $label  = (string) ( $setup[ $tile_slug ]['label'] ?? ( $labels['*'] ?? '' ) );
            $desc  = (string) ( $tile['description'] ?? '' );
        } else {
            $label = (string) ( $entry['label'] ?? '' );
            $desc  = (string) ( $entry['description'] ?? '' );
        }
        if ( $label === '' ) return null;

        if ( $state === self::STATE_OFF ) {
            // A switched-off module's settings stay findable for whoever can
            // switch it back on, and for nobody else.
            if ( ! user_can( $user_id, self::OFF_CAP ) ) return null;
            $section = self::OFF_SECTION;
            $url     = self::viewUrl( 'modules' );
        } else {
            if ( $tile !== null ) {
                if ( ! isset( $setup[ $tile_slug ] ) ) return null;
                $url = self::tileUrl( $setup[ $tile_slug ], $user_id );
            } else {
                if ( ! self::allowed( $entry, $user_id ) ) return null;
                $url = self::entryUrl( $entry );
            }
            if ( $url === '' ) return null;
            $section_cap = self::SECTION_CAPS[ $section ] ?? '';
            if ( $section_cap !== '' && ! user_can( $user_id, $section_cap ) ) return null;
        }

        $anchor = (string) ( $entry['anchor'] ?? '' );
        if ( $anchor !== '' && $state !== self::STATE_OFF ) $url .= '#' . rawurlencode( $anchor );

        return [
            'key'          => $key,
            'section'      => $section,
            'label'        => $label,
            'description'  => $desc,
            'keywords'     => (string) ( $entry['keywords'] ?? '' ),
            'url'          => $url,
            'module_state' => $state,
            'external'     => strpos( $url, '/wp-admin/' ) !== false,
        ];
    }

    /** @param array<string,mixed> $entry */
    private static function allowed( array $entry, int $user_id ): bool {
        if ( $user_id <= 0 ) return false;
        $cap = (string) ( $entry['cap'] ?? '' );
        if ( $cap !== '' ) return user_can( $user_id, $cap );
        $matrix_cap = (string) ( $entry['matrix_cap'] ?? '' );
        if ( $matrix_cap !== '' ) return AuthorizationService::userCanOrMatrix( $user_id, $matrix_cap );
        switch ( (string) ( $entry['check'] ?? '' ) ) {
            case 'can_create_player':
                return AuthorizationService::canCreatePlayer( $user_id );
        }
        // No rule is no access: an entry must say who may open it.
        return false;
    }

    /** @param array<string,mixed> $entry */
    private static function entryUrl( array $entry ): string {
        $args = is_array( $entry['args'] ?? null ) ? $entry['args'] : [];
        $sub  = (string) ( $entry['sub'] ?? '' );
        if ( $sub !== '' ) {
            return (string) add_query_arg( array_merge( [ 'tt_view' => 'configuration', 'config_sub' => $sub ], $args ), RecordLink::dashboardUrl() );
        }
        $view = (string) ( $entry['view'] ?? '' );
        if ( $view !== '' ) {
            return (string) add_query_arg( array_merge( [ 'tt_view' => $view ], $args ), RecordLink::dashboardUrl() );
        }
        $page = (string) ( $entry['admin_page'] ?? '' );
        if ( $page !== '' ) {
            return (string) add_query_arg( array_merge( [ 'page' => $page ], $args ), admin_url( 'admin.php' ) );
        }
        return '';
    }

    private static function viewUrl( string $slug ): string {
        return (string) add_query_arg( [ 'tt_view' => $slug ], RecordLink::dashboardUrl() );
    }

    /** @param array<string,mixed> $tile */
    private static function tileUrl( array $tile, int $user_id ): string {
        $url = (string) ( $tile['url'] ?? '' );
        if ( $url === '' && isset( $tile['url_callback'] ) && is_callable( $tile['url_callback'] ) ) {
            $url = (string) ( $tile['url_callback'] )( $user_id );
        }
        $slug = (string) ( $tile['view_slug'] ?? '' );
        if ( $url === '' && $slug !== '' ) $url = self::viewUrl( $slug );
        return $url;
    }

    /**
     * Setup tiles this viewer can see, by slug, with their persona label.
     *
     * @return array<string,array<string,mixed>>
     */
    private static function visibleSetupTiles( int $user_id ): array {
        $out = [];
        foreach ( TileRegistry::tilesForUser( $user_id )['setup'] as $tile ) {
            $slug = (string) ( $tile['slug'] ?? '' );
            if ( $slug !== '' && ! isset( $out[ $slug ] ) ) $out[ $slug ] = $tile;
        }
        return $out;
    }

    /** @return array<string,mixed>|null The first registration of a tile slug, visible or not. */
    private static function registeredTile( string $slug ): ?array {
        foreach ( TileRegistry::allRegistered() as $tile ) {
            if ( (string) ( $tile['slug'] ?? '' ) === $slug ) return $tile;
        }
        return null;
    }

    /**
     * Entries contributed through the deprecated `tt_config_tile_groups`
     * filter. Anything leading to a screen an entry already covers is
     * dropped, compared on the screen rather than the URL, because the two
     * are built from different bases.
     *
     * @return list<array{key:string, section:string, label:string, description:string, keywords:string, url:string, module_state:string, external:bool}>
     */
    private static function contributed( int $user_id ): array {
        $covered = [];
        foreach ( self::declared() as $entry ) {
            foreach ( [ 'view', 'sub', 'admin_page', 'tile' ] as $field ) {
                $value = (string) ( $entry[ $field ] ?? '' );
                if ( $value !== '' ) $covered[ $value ] = true;
            }
        }

        $out    = [];
        $groups = (array) apply_filters( 'tt_config_tile_groups', [] );
        foreach ( $groups as $group ) {
            if ( ! is_array( $group ) ) continue;
            $tiles = is_array( $group['tiles'] ?? null ) ? $group['tiles'] : [];
            foreach ( $tiles as $tile ) {
                if ( ! is_array( $tile ) ) continue;
                $url = (string) ( $tile['url'] ?? '' );
                if ( $url === '' || isset( $covered[ self::screenOf( $url ) ] ) ) continue;
                $cap = (string) ( $tile['cap'] ?? 'tt_view_settings' );
                if ( ! user_can( $user_id, $cap ) ) continue;
                $label = (string) ( $tile['label'] ?? '' );
                if ( $label === '' ) continue;
                $out[] = [
                    'key'          => 'contributed-' . sanitize_key( $label ),
                    'section'      => 'academy',
                    'label'        => $label,
                    'description'  => (string) ( $tile['description'] ?? '' ),
                    'keywords'     => '',
                    'url'          => $url,
                    'module_state' => self::STATE_NONE,
                    'external'     => strpos( $url, '/wp-admin/' ) !== false,
                ];
            }
        }
        return $out;
    }

    /** The screen a URL opens: its `config_sub`, `tt_view` or wp-admin `page`. */
    private static function screenOf( string $url ): string {
        $query = (string) wp_parse_url( $url, PHP_URL_QUERY );
        parse_str( $query, $args );
        foreach ( [ 'config_sub', 'tt_view', 'page' ] as $param ) {
            if ( isset( $args[ $param ] ) && is_string( $args[ $param ] ) && $args[ $param ] !== '' ) return $args[ $param ];
        }
        return $url;
    }
}
