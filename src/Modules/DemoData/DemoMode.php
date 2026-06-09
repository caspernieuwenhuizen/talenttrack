<?php
namespace TT\Modules\DemoData;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * DemoMode — read/write the site-level tt_demo_mode toggle.
 *
 * Values:
 *   'off'     — normal operation. Demo-tagged rows hidden from every
 *               read path the plugin owns. (Default.)
 *   'on'      — demo mode. ONLY demo-tagged rows are visible. Real
 *               club data is invisible but untouched on disk.
 *   'neutral' — special: used by the demo admin page itself so it
 *               can always see all demo rows regardless of toggle
 *               state. Never set site-wide.
 */
class DemoMode {

    public const OFF       = 'off';
    public const ON        = 'on';
    public const NEUTRAL   = 'neutral';
    // #1272 PR3 — terminal state set by DemoConversionService after a
    // successful run. Cannot be unset via the toggle UI. `tagIfActive`
    // and `current()` both treat it as "no demo mode ever again on
    // this install".
    public const CONVERTED = 'converted';

    private const OPTION          = 'tt_demo_mode';
    private const OPTION_CONVERTED_AT = 'tt_demo_converted_at';

    public static function current(): string {
        $value = (string) get_option( self::OPTION, self::OFF );
        return in_array( $value, [ self::OFF, self::ON, self::CONVERTED ], true ) ? $value : self::OFF;
    }

    public static function set( string $mode ): void {
        // #1272 PR3 — once converted, the toggle is permanently disabled.
        // Re-enabling demo mode would re-tag freshly-created rows and
        // dilute the operator's just-cleaned production set.
        if ( self::isConverted() ) return;
        if ( ! in_array( $mode, [ self::OFF, self::ON ], true ) ) {
            return;
        }
        update_option( self::OPTION, $mode );
    }

    public static function isOn(): bool {
        return self::current() === self::ON;
    }

    /**
     * #1272 PR3 — true once `DemoConversionService::run()` has marked
     * the install converted. Once true, never returns false on this
     * install (terminal state).
     */
    public static function isConverted(): bool {
        return self::current() === self::CONVERTED;
    }

    /**
     * #1272 PR3 — called by DemoConversionService after a successful
     * conversion run. Writes the terminal state + timestamp.
     */
    public static function markConverted(): void {
        update_option( self::OPTION, self::CONVERTED );
        update_option( self::OPTION_CONVERTED_AT, gmdate( 'Y-m-d H:i:s' ) );
    }

    /**
     * Returns the UTC datetime the install was marked converted, or
     * empty string when never converted.
     */
    public static function convertedAt(): string {
        if ( ! self::isConverted() ) return '';
        $val = get_option( self::OPTION_CONVERTED_AT, '' );
        return is_string( $val ) ? $val : '';
    }

    /**
     * Short-lived request-scoped override: force neutral so the demo
     * admin page sees the full demo dataset even when the site toggle
     * is off. Set by DemoDataPage at render time, consumed by
     * apply_demo_scope().
     */
    private static ?string $request_override = null;

    public static function overrideForRequest( string $mode ): void {
        self::$request_override = in_array( $mode, [ self::OFF, self::ON, self::NEUTRAL ], true )
            ? $mode
            : null;
    }

    public static function clearOverride(): void {
        self::$request_override = null;
    }

    public static function effective(): string {
        if ( self::$request_override !== null ) {
            return self::$request_override;
        }
        // v3.110.156 — `demo_only_install` operator flag overrides the
        // runtime mode toggle. When set, every read path treats the
        // install as demo-ON (filter passes only tagged rows) and
        // every write through `tagIfActive` tags the row. Closes the
        // recurring backfill-then-untag migration loop by giving
        // operators an explicit per-install "this is demo data only"
        // signal that's independent of accidental toggle flips.
        if ( self::isDemoOnlyInstall() ) {
            return self::ON;
        }
        return self::current();
    }

    /**
     * v3.110.156 — read the `feature.demo_only_install` toggle.
     * Self-gated on the FeatureToggleService class existing so very
     * early boot paths (or installs missing the feature module) don't
     * fatal — returns false in that case, preserving the historic
     * runtime-toggle-only behaviour.
     */
    public static function isDemoOnlyInstall(): bool {
        if ( ! class_exists( '\\TT\\Infrastructure\\FeatureToggles\\FeatureToggleService' )
          || ! class_exists( '\\TT\\Infrastructure\\Config\\ConfigService' ) ) {
            return false;
        }
        $service = new \TT\Infrastructure\FeatureToggles\FeatureToggleService(
            new \TT\Infrastructure\Config\ConfigService()
        );
        return $service->isEnabled( 'demo_only_install' );
    }

    /**
     * v3.76.2 — when demo mode is currently ON, tag a freshly-created
     * row in `tt_demo_tags` so it's visible to demo-scoped queries.
     * Without this, records created by an operator while demonstrating
     * the product disappear the moment they save: `apply_demo_scope`
     * filters to demo-tagged rows only, and operator-saved rows have
     * no tag.
     *
     * Idempotent: skip when demo mode is OFF, when the tag table
     * doesn't exist (pre-migration safety), or when the row is already
     * tagged. Designed to be a fire-and-forget call from save handlers
     * — failure is silent.
     *
     * Recognised entity types match `apply_demo_scope`:
     * `team`, `player`, `person`, `activity`, `evaluation`, `goal`.
     * Unknown types still get tagged; the scope helper just won't
     * filter on them.
     */
    public static function tagIfActive( string $entity_type, int $entity_id, string $batch_id = 'user-created' ): void {
        if ( $entity_type === '' || $entity_id <= 0 ) return;
        if ( self::effective() !== self::ON ) return;

        global $wpdb;
        $tag_table = $wpdb->prefix . 'tt_demo_tags';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tag_table ) ) !== $tag_table ) return;

        $club_id = class_exists( '\\TT\\Infrastructure\\Tenancy\\CurrentClub' )
            ? (int) \TT\Infrastructure\Tenancy\CurrentClub::id()
            : 1;

        $exists = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$tag_table}
              WHERE club_id = %d AND entity_type = %s AND entity_id = %d",
            $club_id, $entity_type, $entity_id
        ) );
        if ( $exists > 0 ) return;

        $wpdb->insert( $tag_table, [
            'club_id'     => $club_id,
            'batch_id'    => $batch_id,
            'entity_type' => $entity_type,
            'entity_id'   => $entity_id,
            'extra_json'  => null,
        ] );
    }
}
