<?php
namespace TT\Modules\ReportStudio\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Analytics\Domain\UnknownFactKeyException;

/**
 * ReportDefinition (#4569) — what a draft or a version holds: the blocks in
 * their order, each with its key, its options and its width on the canvas.
 *
 *     { "blocks": [ { "id": "b1", "key": "playing_time", "options": { … }, "width": "half" } ] }
 *
 * A block key must be a registered block. Its options are checked against the
 * block when its data is read, because which options a block offers depends
 * on the subject it is read about. A block is a full row unless it says
 * `half` (#4571).
 */
final class ReportDefinition {

    /** The most blocks one definition holds. */
    public const MAX_BLOCKS = 40;

    /** The largest stored definition, in bytes of JSON. */
    public const MAX_BYTES = 65536;

    public const WIDTH_FULL = 'full';
    public const WIDTH_HALF = 'half';
    public const WIDTHS     = [ self::WIDTH_FULL, self::WIDTH_HALF ];

    /**
     * The definition to store, or why it cannot be. Null, an empty string or
     * an empty object clears the definition. `$layout` is the report's
     * layout: a block bound to other layouts is refused (#4572).
     *
     * @return array{blocks:list<array{id:string, key:string, options:array<mixed>, width:string}>}|StudioError
     */
    public static function normalise( mixed $raw, ?string $layout = null ): array|StudioError {
        if ( $raw === null || $raw === '' || $raw === [] ) return [ 'blocks' => [] ];
        if ( ! is_array( $raw ) ) return self::invalid( __( 'The definition must be an object.', 'talenttrack' ) );

        $unknown = array_values( array_diff( array_map( 'strval', array_keys( $raw ) ), [ 'blocks' ] ) );
        if ( $unknown !== [] ) {
            /* translators: %s: comma-separated field names */
            return self::invalid( sprintf( __( 'A definition does not accept: %s.', 'talenttrack' ), implode( ', ', $unknown ) ) );
        }

        $blocks = $raw['blocks'] ?? [];
        if ( ! is_array( $blocks ) || ! array_is_list( $blocks ) ) {
            return self::invalid( __( 'The blocks must be a list.', 'talenttrack' ) );
        }
        if ( count( $blocks ) > self::MAX_BLOCKS ) {
            /* translators: %d: maximum number of blocks */
            return self::invalid( sprintf( __( 'A report holds at most %d blocks.', 'talenttrack' ), self::MAX_BLOCKS ) );
        }

        $out = [];
        foreach ( $blocks as $i => $block ) {
            if ( ! is_array( $block ) ) return self::invalid( __( 'Every block must be an object.', 'talenttrack' ) );
            $extra = array_values( array_diff( array_map( 'strval', array_keys( $block ) ), [ 'id', 'key', 'options', 'width' ] ) );
            if ( $extra !== [] ) {
                /* translators: %s: comma-separated field names */
                return self::invalid( sprintf( __( 'A block does not accept: %s.', 'talenttrack' ), implode( ', ', $extra ) ) );
            }
            $key      = is_string( $block['key'] ?? null ) ? (string) $block['key'] : '';
            $registry = BlockRegistry::find( $key );
            if ( $registry === null ) {
                return self::invalid( __( 'There is no block with that key.', 'talenttrack' ) );
            }
            if ( $layout !== null && $registry instanceof LayoutBound && ! in_array( $layout, $registry->layouts(), true ) ) {
                return new StudioError( 'invalid_field', $registry->layoutReason(), 400, [ 'field' => 'definition', 'block' => $i ] );
            }
            $options = $block['options'] ?? [];
            if ( ! is_array( $options ) ) return self::invalid( __( 'The options must be an object.', 'talenttrack' ) );
            // #4860 — a block naming an analytics field that no longer
            // exists is refused on save; a renamed one is stored by its new
            // name.
            if ( $registry instanceof ChecksFactKeys ) {
                $keyed = [];
                foreach ( $options as $k => $v ) $keyed[ (string) $k ] = $v;
                try {
                    $options = $registry->resolveStoredKeys( $keyed );
                } catch ( UnknownFactKeyException $e ) {
                    $refusal = BlockEngine::unknownField( $e );
                    return new StudioError( $refusal->code, $refusal->message, 400, $e->details() + [ 'field' => 'definition', 'block' => $i ] );
                }
            }
            $id = is_string( $block['id'] ?? null ) && $block['id'] !== '' ? sanitize_key( (string) $block['id'] ) : 'b' . ( $i + 1 );
            $width = $block['width'] ?? self::WIDTH_FULL;
            if ( ! is_string( $width ) || ! in_array( $width, self::WIDTHS, true ) ) {
                return self::invalid( __( 'A block is full or half width.', 'talenttrack' ) );
            }

            $out[] = [ 'id' => $id, 'key' => $key, 'options' => $options, 'width' => $width ];
        }

        $definition = [ 'blocks' => $out ];
        if ( strlen( (string) wp_json_encode( $definition ) ) > self::MAX_BYTES ) {
            /* translators: %d: maximum size in bytes */
            return self::invalid( sprintf( __( 'A definition may be at most %d bytes.', 'talenttrack' ), self::MAX_BYTES ) );
        }
        return $definition;
    }

    /**
     * The blocks of a stored definition, skipping anything malformed.
     *
     * @param array<mixed> $definition
     * @return list<array{id:string, key:string, options:array<mixed>, width:string}>
     */
    public static function blocks( array $definition ): array {
        $out = [];
        foreach ( is_array( $definition['blocks'] ?? null ) ? $definition['blocks'] : [] as $i => $block ) {
            if ( ! is_array( $block ) || ! is_string( $block['key'] ?? null ) ) continue;
            $out[] = [
                'id'      => is_string( $block['id'] ?? null ) ? (string) $block['id'] : 'b' . ( (int) $i + 1 ),
                'key'     => (string) $block['key'],
                'options' => is_array( $block['options'] ?? null ) ? $block['options'] : [],
                'width'   => ( $block['width'] ?? '' ) === self::WIDTH_HALF ? self::WIDTH_HALF : self::WIDTH_FULL,
            ];
        }
        return $out;
    }

    private static function invalid( string $message ): StudioError {
        return new StudioError( 'invalid_field', $message, 400, [ 'field' => 'definition' ] );
    }
}
