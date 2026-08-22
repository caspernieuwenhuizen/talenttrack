<?php
namespace TT\Shared\Content;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\FeatureRegistry;
use TT\Core\ModuleRegistry;
use TT\Modules\License\FeatureMap;
use TT\Modules\License\LicenseGate;

/**
 * ContentGate — can this install, and this reader, have this content?
 *
 * Four keys, read off a front-matter block:
 *
 *     module:     TT\Modules\Methodology\MethodologyModule
 *     feature:    knowledge_courses
 *     tier:       standard
 *     capability: tt_view_knowledge
 *
 * Two corpora need exactly this, resolved exactly the same way: the help
 * topics under `docs/` (#2546) and the courses under `courses/` (#2645).
 * Both already carry these keys. Building the resolution twice would give
 * the plugin two answers to "can this person see this", and they would
 * drift — the first time a fifth gate is added, or the tier order changes,
 * or somebody fixes a bug in one of them.
 *
 * Lives in `Shared` rather than in either module because neither owns it,
 * and a third consumer is plausible: the Training module's exercise library
 * carries the same liveness question.
 *
 * ## Conventions this deliberately inherits
 *
 * **An absent key is not a gate.** Content with no `module:` is never
 * module-gated. Most content has none of these keys and must behave
 * exactly as it did before the gate existed.
 *
 * **An unknown key value leaves content visible.** A typo in
 * `feature: knowlege_courses` must not silently hide a topic — that is a
 * bug you find months later, if ever. `ModuleRegistry::isEnabled()` and
 * `FeatureRegistry::isEnabled()` already return true for keys they do not
 * know, and this follows them. The corpus lints are what catch the typo.
 *
 * **Nothing here is cached.** Module and feature state are mutable at
 * runtime and capability is per-user; a cached verdict means a module
 * toggle does not take effect until the next plugin update. The registries
 * do their own caching at the right granularity.
 */
final class ContentGate {

    /** The install does not run the owning module. */
    public const REASON_MODULE = 'module_disabled';

    /** The owning sub-feature is switched off. */
    public const REASON_FEATURE = 'feature_disabled';

    /** The licence tier is below what the content declares. */
    public const REASON_TIER = 'tier_insufficient';

    /** The reader lacks the declared capability. */
    public const REASON_CAPABILITY = 'capability_missing';

    /**
     * Resolve a front-matter block.
     *
     * Gates run install-wide first and per-reader last, so the verdict
     * names the most fundamental reason rather than the first one that
     * happens to match: on a `free` install, a `pro` topic is unavailable,
     * not "denied to this user who also lacks the capability".
     *
     * @param array<string, mixed> $front_matter Parsed keys; extras ignored.
     * @param int|null             $user_id      Null means the current user.
     */
    public static function verdict( array $front_matter, ?int $user_id = null ): GateVerdict {
        $module = self::key( $front_matter, 'module' );
        if ( $module !== '' && ! ModuleRegistry::isEnabled( $module ) ) {
            return GateVerdict::unavailable( self::REASON_MODULE, [ 'module' => $module ] );
        }

        $feature = self::key( $front_matter, 'feature' );
        if ( $feature !== '' && ! FeatureRegistry::isEnabled( $feature ) ) {
            return GateVerdict::unavailable( self::REASON_FEATURE, [ 'feature' => $feature ] );
        }

        $tier = self::key( $front_matter, 'tier' );
        if ( $tier !== '' && ! self::tierAllows( $tier ) ) {
            return GateVerdict::unavailable( self::REASON_TIER, [
                'required' => $tier,
                'current'  => self::currentTier(),
            ] );
        }

        $capability = self::key( $front_matter, 'capability' );
        if ( $capability !== '' && ! self::userCan( $capability, $user_id ) ) {
            return GateVerdict::denied( self::REASON_CAPABILITY, [ 'capability' => $capability ] );
        }

        return GateVerdict::available();
    }

    /**
     * Shorthand for the common case: should this be listed at all?
     *
     * @param array<string, mixed> $front_matter
     */
    public static function isVisible( array $front_matter, ?int $user_id = null ): bool {
        return self::verdict( $front_matter, $user_id )->isAvailable();
    }

    /**
     * Does the install's tier meet the one the content asks for?
     *
     * Rank comes from `FeatureMap::tiers()`, which is ordered ascending, so
     * adding a tier between two existing ones needs no change here. A tier
     * value the map does not know is not a gate — same reason an unknown
     * feature key is not.
     */
    private static function tierAllows( string $required ): bool {
        $tiers = self::knownTiers();

        $required_rank = array_search( strtolower( $required ), $tiers, true );
        if ( $required_rank === false ) {
            return true;
        }

        $current_rank = array_search( self::currentTier(), $tiers, true );
        if ( $current_rank === false ) {
            // The install reports a tier the map does not know. Failing
            // open is the right direction: hiding a licensed academy's
            // content because of a tier-name mismatch is the worse bug.
            return true;
        }

        return $current_rank >= $required_rank;
    }

    /**
     * The tier to compare against.
     *
     * `effectiveTier()` rather than `tier()`, so an install in its grace
     * period sees what a `free` install sees. Documentation for a feature
     * that has stopped working during grace is documentation for something
     * that is not there.
     */
    private static function currentTier(): string {
        if ( ! class_exists( LicenseGate::class ) ) {
            return '';
        }

        return strtolower( (string) LicenseGate::effectiveTier() );
    }

    /** @return list<string> ascending */
    private static function knownTiers(): array {
        if ( ! class_exists( FeatureMap::class ) ) {
            return [];
        }

        return array_values( array_map( 'strtolower', FeatureMap::tiers() ) );
    }

    /**
     * Capability check for a specific reader.
     *
     * `user_can()` when a user is named, because the docs surface resolves
     * visibility for an explicit user id rather than always for whoever is
     * logged in.
     */
    private static function userCan( string $capability, ?int $user_id ): bool {
        if ( $user_id === null ) {
            return current_user_can( $capability );
        }

        return $user_id > 0 && user_can( $user_id, $capability );
    }

    /**
     * One front-matter key as a trimmed string.
     *
     * Lists collapse to their first entry, matching `DocFrontMatter::string()`
     * — these four keys are single-valued, and a corpus author writing
     * `tier: [standard]` meant `standard`.
     *
     * @param array<string, mixed> $front_matter
     */
    private static function key( array $front_matter, string $name ): string {
        $raw = $front_matter[ $name ] ?? '';

        if ( is_array( $raw ) ) {
            $raw = $raw[0] ?? '';
        }

        return is_string( $raw ) ? trim( $raw ) : '';
    }
}
