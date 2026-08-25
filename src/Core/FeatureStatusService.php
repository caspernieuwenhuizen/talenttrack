<?php
namespace TT\Core;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Shared\Modules\ModuleMetadata;
use TT\Shared\Tiles\TileRegistry;

/**
 * FeatureStatusService — read model for the all-personas "what's
 * switched on" status surface (#1486).
 *
 * The management page (`?tt_view=modules`) is the write surface, gated
 * by `tt_manage_modules`. This service feeds the read-only status view +
 * its REST endpoint, both of which any logged-in user reaches. All the
 * shaping — which modules to surface, their human labels, what each
 * provides, their feature children — lives here so the view only
 * composes (CLAUDE.md §4).
 *
 * Only modules that actually present something to a user are listed: a
 * module is surfaced when it owns at least one dashboard tile or one
 * sub-feature. Pure-infrastructure modules (no tile, no feature) are
 * never shown — their on/off state is meaningless to an end user.
 *
 * Two read models live here, deliberately not collapsed into one:
 * {@see overview()} is the complete, unfiltered audit shape behind
 * `GET /feature-status`, and {@see catalog()} is the reader-facing
 * capability catalog behind the Features view and `/feature-catalog`.
 * They answer different questions, so they filter differently.
 */
class FeatureStatusService {

    /**
     * @return list<array{
     *   label: string,
     *   enabled: bool,
     *   always_on: bool,
     *   provides: list<string>,
     *   features: list<array{key:string, label:string, description:string, enabled:bool}>
     * }>
     */
    public static function overview(): array {
        $provides_by_module = self::providesByModule();

        $out = [];
        foreach ( ModuleRegistry::allWithState() as $m ) {
            $class    = ltrim( (string) $m['class'], '\\' );
            $provides = $provides_by_module[ $class ] ?? [];
            $features = FeatureRegistry::forModule( $class );

            if ( empty( $provides ) && empty( $features ) ) continue;

            $out[] = [
                'label'     => self::humanize( $class ),
                'enabled'   => ! empty( $m['enabled'] ),
                'always_on' => ! empty( $m['always_on'] ),
                'provides'  => array_values( array_unique( $provides ) ),
                'features'  => $features,
            ];
        }

        usort( $out, static fn( $a, $b ) => strcmp( (string) $a['label'], (string) $b['label'] ) );
        return $out;
    }

    /**
     * #2878 — the capability catalog behind `?tt_view=features`: the same
     * module state as {@see overview()}, but shaped for a reader deciding
     * what their academy could be using, rather than for an auditor
     * checking every flag.
     *
     * Three differences from `overview()`, all decided in #2878:
     *
     *  - **Human copy, not derived copy.** Label, description and icon
     *    come from ModuleMetadata, so a card reads as a written sentence
     *    instead of a de-CamelCased class name.
     *  - **Two exclusions.** Always-on core (auth, configuration,
     *    authorization) is plumbing nobody chooses, and the
     *    Advanced / developer category is not a capability worth
     *    offering. Both drop out of the catalog entirely — including out
     *    of the counts a caller derives from it.
     *  - **Under-development work is not advertised.** A module or
     *    feature that is off *and* flagged is omitted. One that is on
     *    *and* flagged is kept, because its surface is already live on
     *    the dashboard and hiding it here would only confuse.
     *
     * `overview()` keeps its unfiltered shape so `/feature-status` stays
     * a truthful v1 payload; this is a separate read model, not a
     * replacement (CLAUDE.md §4).
     *
     * @return list<array{
     *   category: string,
     *   category_label: string,
     *   in_use: list<array{label:string, description:string, icon:string, enabled:bool, under_development:bool, provides:list<string>, features:list<array{key:string,label:string,description:string,enabled:bool,under_development:bool}>}>,
     *   available: list<array{label:string, description:string, icon:string, enabled:bool, under_development:bool, provides:list<string>, features:list<array{key:string,label:string,description:string,enabled:bool,under_development:bool}>}>
     * }>
     */
    public static function catalog(): array {
        $provides_by_module = self::providesByModule();

        $grouped = [];

        foreach ( ModuleRegistry::allWithState() as $m ) {
            $class = ltrim( (string) $m['class'], '\\' );

            if ( ! empty( $m['always_on'] ) ) continue;

            $meta = ModuleMetadata::for( $class );
            // ModuleMetadata::for() falls back to CAT_ADVANCED for a module
            // with no entry, so this guard drops the un-described alongside
            // the deliberately-advanced. Both are the right call here: a
            // module nobody has written a description for has nothing to
            // say on a page whose whole job is saying what things are for.
            $category = (string) $meta['category'];
            if ( $category === ModuleMetadata::CAT_ADVANCED ) continue;

            $enabled = ! empty( $m['enabled'] );
            $module_dev = ! empty( $m['under_development'] );
            if ( ! $enabled && $module_dev ) continue;

            $features = [];
            foreach ( FeatureRegistry::forModule( $class ) as $feature ) {
                if ( empty( $feature['enabled'] ) && ! empty( $feature['under_development'] ) ) continue;
                $features[] = $feature;
            }

            $provides = $provides_by_module[ $class ] ?? [];
            // Same rule as overview(): a module that presents nothing to a
            // user has no on/off state worth reading.
            if ( empty( $provides ) && empty( $features ) ) continue;

            $band = $enabled ? 'in_use' : 'available';
            $grouped[ $category ][ $band ][] = [
                'label'             => (string) $meta['label'],
                'description'       => (string) $meta['description'],
                'icon'              => (string) $meta['icon'],
                'enabled'           => $enabled,
                'under_development' => $module_dev,
                'provides'          => array_values( array_unique( $provides ) ),
                'features'          => $features,
            ];
        }

        $by_label = static fn( $a, $b ) => strcmp( (string) $a['label'], (string) $b['label'] );

        $out = [];
        foreach ( ModuleMetadata::categories() as $key => $category_label ) {
            $in_use    = $grouped[ $key ]['in_use'] ?? [];
            $available = $grouped[ $key ]['available'] ?? [];
            if ( empty( $in_use ) && empty( $available ) ) continue;

            usort( $in_use, $by_label );
            usort( $available, $by_label );

            $out[] = [
                'category'       => (string) $key,
                'category_label' => (string) $category_label,
                'in_use'         => $in_use,
                'available'      => $available,
            ];
        }

        return $out;
    }

    /**
     * Tile labels grouped by owning module — these describe what a module
     * provides without a hand-maintained per-module blurb.
     *
     * @return array<string, list<string>> module class => tile labels
     */
    private static function providesByModule(): array {
        $out = [];
        foreach ( TileRegistry::allRegistered() as $tile ) {
            $owner = (string) ( $tile['module_class'] ?? '' );
            if ( $owner === '' ) continue;
            $label = '';
            if ( isset( $tile['labels'] ) && is_array( $tile['labels'] ) && isset( $tile['labels']['*'] ) ) {
                $label = (string) $tile['labels']['*'];
            } elseif ( isset( $tile['label'] ) ) {
                $label = (string) $tile['label'];
            }
            if ( $label === '' ) continue;
            $out[ ltrim( $owner, '\\' ) ][] = $label;
        }
        return $out;
    }

    /**
     * "TT\Modules\TeamDevelopment\TeamDevelopmentModule" → "Team
     * Development". Strips the namespace + the `Module` suffix and
     * spaces out the CamelCase so the label reads naturally to a user.
     */
    private static function humanize( string $class ): string {
        $parts = explode( '\\', $class );
        $last  = (string) end( $parts );
        $last  = preg_replace( '/Module$/', '', $last );
        $last  = is_string( $last ) ? $last : '';
        $spaced = preg_replace( '/(?<!^)([A-Z])/', ' $1', $last );
        return trim( is_string( $spaced ) ? $spaced : $last );
    }
}
