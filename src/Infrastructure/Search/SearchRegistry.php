<?php
namespace TT\Infrastructure\Search;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Search\Providers\ActivitySearchProvider;
use TT\Infrastructure\Search\Providers\HelpSearchProvider;
use TT\Infrastructure\Search\Providers\PersonSearchProvider;
use TT\Infrastructure\Search\Providers\PlayerSearchProvider;
use TT\Infrastructure\Search\Providers\SectionSearchProvider;
use TT\Infrastructure\Search\Providers\SettingSearchProvider;
use TT\Infrastructure\Search\Providers\TeamSearchProvider;

/**
 * SearchRegistry (#4671) — the kinds search can find, by key, in the order
 * hits of equal score are listed.
 *
 * Phase 1 kinds: sections (`view`), players, teams, people, activities,
 * settings and help topics. A kind added later registers a
 * {@see SearchProvider} here and inherits the whole contract: scoped in SQL,
 * confirmed per row, counted over the same scope.
 */
final class SearchRegistry {

    /** @var array<string, SearchProvider>|null */
    private static ?array $providers = null;

    /** @return array<string, SearchProvider> */
    public static function all(): array {
        if ( self::$providers === null ) {
            self::$providers = [];
            foreach ( [
                new SectionSearchProvider(),
                new PlayerSearchProvider(),
                new TeamSearchProvider(),
                new PersonSearchProvider(),
                new ActivitySearchProvider(),
                new SettingSearchProvider(),
                new HelpSearchProvider(),
            ] as $provider ) {
                self::$providers[ $provider->kind() ] = $provider;
            }
        }
        return self::$providers;
    }

    public static function get( string $kind ): ?SearchProvider {
        return self::all()[ $kind ] ?? null;
    }

    /** Add or replace a kind. */
    public static function register( SearchProvider $provider ): void {
        self::all();
        self::$providers[ $provider->kind() ] = $provider;
    }

    /** @return list<string> */
    public static function kinds(): array {
        return array_keys( self::all() );
    }

    /** Back to the phase 1 set. Tests use this. */
    public static function reset(): void {
        self::$providers = null;
    }
}
