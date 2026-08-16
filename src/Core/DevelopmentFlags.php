<?php
namespace TT\Core;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * DevelopmentFlags (#2409) — resolves whether a surface is "under
 * development", from either of the two cosmetic flags:
 *
 *  - the per-feature flag (#2387, `tt_feature_state.under_development`), or
 *  - the per-module flag (#2409, `tt_module_state.under_development`).
 *
 * One rule, consulted by every renderer: the view-level pill
 * (`DevelopmentPill`) and the dashboard tile badges ask the same question and
 * therefore cannot disagree. Neither flag ever gates a surface — a flagged
 * feature or module stays fully live.
 *
 * Lives in Core rather than in a view: deciding whether a slug is flagged is
 * a domain question (CLAUDE.md §4), and a future non-WordPress front end
 * needs the same answer the rendered HTML gets.
 */
final class DevelopmentFlags {

    /**
     * Is the `tt_view=` slug owned by a flagged feature, or by a flagged
     * module? A slug owned by neither returns false — the common case.
     */
    public static function forViewSlug( string $slug ): bool {
        if ( $slug === '' ) return false;

        if ( class_exists( '\\TT\\Core\\FeatureRegistry' )
            && FeatureRegistry::underDevelopmentForViewSlug( $slug ) ) {
            return true;
        }

        if ( ! class_exists( '\\TT\\Shared\\Tiles\\TileRegistry' ) ) return false;
        $module = \TT\Shared\Tiles\TileRegistry::moduleForViewSlug( $slug );
        if ( $module === null || $module === '' ) return false;

        return ModuleRegistry::isUnderDevelopment( $module );
    }
}
