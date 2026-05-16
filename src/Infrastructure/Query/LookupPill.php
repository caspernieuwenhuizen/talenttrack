<?php
namespace TT\Infrastructure\Query;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * LookupPill — renders a colour-coded inline pill for a `tt_lookups`
 * row, using `meta.color` (falling back to a neutral grey) and the
 * translated name from `LookupTranslator`.
 *
 * Centralised here so admin lists, frontend lists, and any future
 * surface that surfaces a lookup-typed value all render the same
 * visual vocabulary. Returns escaped HTML; callers may emit directly.
 */
final class LookupPill {

    private const FALLBACK_COLOR = '#5b6e75';

    /**
     * Semantic default colours keyed by `lookup_type/normalised_name`.
     * Used when no `tt_lookups` row matches or the matched row has no
     * `meta.color`. Operator-seeded `meta.color` still wins. The map
     * exists to give common statuses a sensible default rather than the
     * neutral grey fallback when the lookup vocabulary hasn't been
     * customised — e.g. an active player should read as green.
     */
    private const SEMANTIC_DEFAULTS = [
        'player_status/active' => '#16a34a',
    ];

    /**
     * Render a pill for `(lookup_type, stored_name)`. Optional fallback
     * label is used when the lookup row has been removed (e.g. row was
     * deleted but rows still reference it).
     *
     * @param string  $lookup_type     `activity_type`, `activity_status`, …
     * @param string  $stored_name     The value stored on the entity row.
     * @param string  $fallback_label  Free-text label shown when no lookup row matches.
     */
    public static function render( string $lookup_type, string $stored_name, string $fallback_label = '' ): string {
        if ( $stored_name === '' && $fallback_label === '' ) return '';

        $row   = self::resolveRow( $lookup_type, $stored_name );
        $label = $row ? LookupTranslator::name( $row )
                      : ( $fallback_label !== '' ? $fallback_label : $stored_name );
        $color = $row ? self::colorFromMeta( $row, $lookup_type, $stored_name )
                      : self::defaultColor( $lookup_type, $stored_name );

        return sprintf(
            '<span class="tt-pill" style="display:inline-block;padding:2px 10px;border-radius:999px;background:%s;color:#fff;font-size:11px;font-weight:600;line-height:1.6;letter-spacing:0.02em;">%s</span>',
            esc_attr( $color ),
            esc_html( $label )
        );
    }

    private static function resolveRow( string $lookup_type, string $stored_name ): ?object {
        if ( $stored_name === '' ) return null;
        static $cache = [];
        static $normalised_cache = [];
        if ( ! isset( $cache[ $lookup_type ] ) ) {
            $cache[ $lookup_type ] = [];
            $normalised_cache[ $lookup_type ] = [];
            foreach ( QueryHelpers::get_lookups( $lookup_type ) as $row ) {
                $cache[ $lookup_type ][ (string) $row->name ] = $row;
                // v3.71.2 — secondary index by normalised name so the
                // snake_case values goal/PDP tables actually store
                // (`on_hold`, `in_progress`) match the lookup-table
                // rows that are seeded as Title Case With Spaces
                // (`On Hold`, `In Progress`). Without this fallback
                // the goal_status pill silently rendered the raw
                // value untranslated, which is what the user saw as
                // "on_hold" sometimes showing as "In de wacht" and
                // sometimes as "on_hold".
                $normalised_cache[ $lookup_type ][ self::normaliseName( (string) $row->name ) ] = $row;
            }
        }
        if ( isset( $cache[ $lookup_type ][ $stored_name ] ) ) {
            return $cache[ $lookup_type ][ $stored_name ];
        }
        $key = self::normaliseName( $stored_name );
        return $normalised_cache[ $lookup_type ][ $key ] ?? null;
    }

    /**
     * v3.71.2 — collapse the snake_case ↔ Title Case With Spaces
     * difference between database-stored values (typically snake_case
     * via `sanitize_key()` paths) and lookup-row names (seeded as
     * human-readable strings). Lowercases + replaces `_` with ` ` +
     * trims duplicate whitespace, so `on_hold` and `On Hold` both
     * normalise to `on hold`.
     */
    private static function normaliseName( string $name ): string {
        $n = strtolower( str_replace( [ '_', '-' ], ' ', $name ) );
        return trim( preg_replace( '/\s+/', ' ', $n ) );
    }

    private static function colorFromMeta( object $row, string $lookup_type, string $stored_name ): string {
        $meta_raw = $row->meta ?? '';
        $meta     = is_string( $meta_raw ) && $meta_raw !== ''
            ? (array) json_decode( $meta_raw, true )
            : [];
        $color    = is_string( $meta['color'] ?? null ) ? trim( (string) $meta['color'] ) : '';
        return $color !== '' ? $color : self::defaultColor( $lookup_type, $stored_name );
    }

    private static function defaultColor( string $lookup_type, string $stored_name ): string {
        $key = $lookup_type . '/' . self::normaliseName( $stored_name );
        return self::SEMANTIC_DEFAULTS[ $key ] ?? self::FALLBACK_COLOR;
    }
}
