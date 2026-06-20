<?php
namespace TT\Core;

if ( ! defined( 'ABSPATH' ) ) exit;

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
        // Tile labels grouped by owning module — these describe what the
        // module provides without a hand-maintained per-module blurb.
        $provides_by_module = [];
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
            $provides_by_module[ ltrim( $owner, '\\' ) ][] = $label;
        }

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
