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
        $color = $row ? self::colorFromMeta( $row ) : self::FALLBACK_COLOR;

        return sprintf(
            '<span class="tt-pill" style="display:inline-block;padding:2px 10px;border-radius:999px;background:%s;color:#fff;font-size:11px;font-weight:600;line-height:1.6;letter-spacing:0.02em;">%s</span>',
            esc_attr( $color ),
            esc_html( $label )
        );
    }

    private static function resolveRow( string $lookup_type, string $stored_name ): ?object {
        if ( $stored_name === '' ) return null;
        static $cache = [];
        if ( ! isset( $cache[ $lookup_type ] ) ) {
            $cache[ $lookup_type ] = [];
            foreach ( QueryHelpers::get_lookups( $lookup_type ) as $row ) {
                $cache[ $lookup_type ][ (string) $row->name ] = $row;
            }
        }
        return $cache[ $lookup_type ][ $stored_name ] ?? null;
    }

    private static function colorFromMeta( object $row ): string {
        $meta_raw = $row->meta ?? '';
        $meta     = is_string( $meta_raw ) && $meta_raw !== ''
            ? (array) json_decode( $meta_raw, true )
            : [];
        $color    = is_string( $meta['color'] ?? null ) ? trim( (string) $meta['color'] ) : '';
        return $color !== '' ? $color : self::FALLBACK_COLOR;
    }
}
