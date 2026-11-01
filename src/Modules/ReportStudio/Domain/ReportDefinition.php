<?php
namespace TT\Modules\ReportStudio\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * ReportDefinition (#4569) — what a draft or a version holds: the blocks in
 * their order, each with its key and options.
 *
 *     { "blocks": [ { "id": "b1", "key": "playing_time", "options": { … } } ] }
 *
 * A block key must be a registered block. Its options are checked against the
 * block when its data is read, because which options a block offers depends
 * on the subject it is read about.
 */
final class ReportDefinition {

    /** The most blocks one definition holds. */
    public const MAX_BLOCKS = 60;

    /** The largest stored definition, in bytes of JSON. */
    public const MAX_BYTES = 65536;

    /**
     * The definition to store, or why it cannot be. Null, an empty string or
     * an empty object clears the definition.
     *
     * @return array{blocks:list<array{id:string, key:string, options:array<mixed>}>}|StudioError
     */
    public static function normalise( mixed $raw ): array|StudioError {
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
            $extra = array_values( array_diff( array_map( 'strval', array_keys( $block ) ), [ 'id', 'key', 'options' ] ) );
            if ( $extra !== [] ) {
                /* translators: %s: comma-separated field names */
                return self::invalid( sprintf( __( 'A block does not accept: %s.', 'talenttrack' ), implode( ', ', $extra ) ) );
            }
            $key = is_string( $block['key'] ?? null ) ? (string) $block['key'] : '';
            if ( BlockRegistry::find( $key ) === null ) {
                return self::invalid( __( 'There is no block with that key.', 'talenttrack' ) );
            }
            $options = $block['options'] ?? [];
            if ( ! is_array( $options ) ) return self::invalid( __( 'The options must be an object.', 'talenttrack' ) );
            $id = is_string( $block['id'] ?? null ) && $block['id'] !== '' ? sanitize_key( (string) $block['id'] ) : 'b' . ( $i + 1 );

            $out[] = [ 'id' => $id, 'key' => $key, 'options' => $options ];
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
     * @return list<array{id:string, key:string, options:array<mixed>}>
     */
    public static function blocks( array $definition ): array {
        $out = [];
        foreach ( is_array( $definition['blocks'] ?? null ) ? $definition['blocks'] : [] as $i => $block ) {
            if ( ! is_array( $block ) || ! is_string( $block['key'] ?? null ) ) continue;
            $out[] = [
                'id'      => is_string( $block['id'] ?? null ) ? (string) $block['id'] : 'b' . ( (int) $i + 1 ),
                'key'     => (string) $block['key'],
                'options' => is_array( $block['options'] ?? null ) ? $block['options'] : [],
            ];
        }
        return $out;
    }

    private static function invalid( string $message ): StudioError {
        return new StudioError( 'invalid_field', $message, 400, [ 'field' => 'definition' ] );
    }
}
