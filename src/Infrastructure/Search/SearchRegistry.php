<?php
namespace TT\Infrastructure\Search;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Search\Providers\ActivitySearchProvider;
use TT\Infrastructure\Search\Providers\EvaluationSearchProvider;
use TT\Infrastructure\Search\Providers\GoalSearchProvider;
use TT\Infrastructure\Search\Providers\MessageSearchProvider;
use TT\Infrastructure\Search\Providers\CompositeSearchProvider;
use TT\Infrastructure\Search\Providers\PlayerNoteSearchProvider;
use TT\Infrastructure\Search\Providers\HelpSearchProvider;
use TT\Infrastructure\Search\Providers\PersonSearchProvider;
use TT\Infrastructure\Search\Providers\PlayerSearchProvider;
use TT\Infrastructure\Search\Providers\SectionSearchProvider;
use TT\Infrastructure\Search\Providers\SettingSearchProvider;
use TT\Infrastructure\Search\Providers\StaffEvaluationSearchProvider;
use TT\Infrastructure\Search\Providers\StaffGoalSearchProvider;
use TT\Infrastructure\Search\Providers\TeamSearchProvider;

/**
 * SearchRegistry (#4671) — the kinds search can find, by key, in the order
 * hits of equal score are listed.
 *
 * Phase 1 kinds: sections (`view`), players, teams, people, activities,
 * settings and help topics. #4673 added the family's evaluations, goals and
 * messages; #4936 the staff's evaluations and goals, as a second scope of
 * the same kinds, and player notes; #4942 the library for staff: exercises,
 * training plans, methodology and courses. A kind added later registers a
 * {@see SearchProvider} here and inherits the whole contract: scoped in SQL,
 * confirmed per row, counted over the same scope.
 *
 * #4936 — one kind, several scopes. A resource that different readers reach
 * by different rules (a family its own children, staff the players they
 * read) is one kind served by an ordered list of providers whose scopes do
 * not overlap, composed by {@see CompositeSearchProvider}: hits merged by
 * score, counts added up, each hit confirmed by the provider that found it.
 * The kind never says who is asking.
 */
final class SearchRegistry {

    /** @var array<string, SearchProvider>|null */
    private static ?array $providers = null;

    /** @return array<string, SearchProvider> */
    public static function all(): array {
        if ( self::$providers === null ) {
            $by_kind = [];
            foreach ( [
                new SectionSearchProvider(),
                new PlayerSearchProvider(),
                new TeamSearchProvider(),
                new PersonSearchProvider(),
                new ActivitySearchProvider(),
                new EvaluationSearchProvider(),
                new StaffEvaluationSearchProvider(),
                new GoalSearchProvider(),
                new StaffGoalSearchProvider(),
                new MessageSearchProvider(),
                new PlayerNoteSearchProvider(),
                new SettingSearchProvider(),
                new ExerciseSearchProvider(),
                new TrainingPlanSearchProvider(),
                new MethodologySearchProvider(),
                new CourseSearchProvider(),
                new HelpSearchProvider(),
            ] as $provider ) {
                $by_kind[ $provider->kind() ][] = $provider;
            }
            self::$providers = [];
            foreach ( $by_kind as $kind => $providers ) {
                self::$providers[ $kind ] = count( $providers ) === 1 ? $providers[0] : new CompositeSearchProvider( $kind, $providers );
            }
        }
        return self::$providers;
    }

    public static function get( string $kind ): ?SearchProvider {
        return self::all()[ $kind ] ?? null;
    }

    /**
     * The providers that serve a kind, in order: the composite's parts, or
     * the one provider. Empty for an unknown kind.
     *
     * @return list<SearchProvider>
     */
    public static function providersOf( string $kind ): array {
        $provider = self::get( $kind );
        if ( $provider === null ) return [];
        return $provider instanceof CompositeSearchProvider ? $provider->providers() : [ $provider ];
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
