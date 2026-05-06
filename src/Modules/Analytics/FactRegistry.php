<?php
namespace TT\Modules\Analytics;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Analytics\Domain\Fact;

/**
 * FactRegistry — central catalogue of facts (#0083 Child 1).
 *
 * Same registration shape as `WidgetRegistry` and
 * `KpiDataSourceRegistry`: append-only, keyed by the fact key,
 * idempotent (last write wins). Modules register their facts in
 * `boot()`; the framework reads from here at query time.
 *
 * The list-of-facts is the definition of "what TalentTrack can
 * report on" — the Analytics module owns the registry but does not
 * own the registrations. Each module declares its own facts
 * (Activities → attendance + activities; Evaluations → evaluations;
 * Goals → goals; Trials → trial_decisions; Prospects → prospects;
 * Journey → journey_events). #0083 Child 1 ships the initial 8
 * registrations centrally inside `AnalyticsModule::boot()` for
 * sequencing simplicity; a follow-up moves each registration into
 * its owning module's `boot()`.
 *
 * `clear()` is for tests only. Production never calls it; the
 * registry is built once during plugin boot and read for the
 * lifetime of the request.
 */
final class FactRegistry {

    /** @var array<string, Fact> */
    private static array $facts = [];

    public static function register( Fact $fact ): void {
        self::$facts[ $fact->key ] = $fact;
    }

    public static function find( string $key ): ?Fact {
        return self::$facts[ $key ] ?? null;
    }

    /** @return array<string, Fact> */
    public static function all(): array {
        return self::$facts;
    }

    /**
     * Facts whose `entityScope` matches `$scope`. Used by
     * `KpiRegistry::forEntity()` (#0083 Child 2) to scope KPIs
     * to a per-entity view.
     *
     * @return array<string, Fact>
     */
    public static function forEntity( string $scope ): array {
        $out = [];
        foreach ( self::$facts as $key => $fact ) {
            if ( $fact->entityScope === $scope ) $out[ $key ] = $fact;
        }
        return $out;
    }

    public static function clear(): void {
        self::$facts = [];
    }
}
