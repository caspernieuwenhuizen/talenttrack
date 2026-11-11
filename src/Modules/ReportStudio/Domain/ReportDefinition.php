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
 *
 * In a report laid out as a grid (#4962) a block also has its place,
 * `"grid": { "x": 0, "y": 0, "w": 6, "h": 2 }`, checked by `ReportGrid`: inside
 * the grid, whole cells, no two blocks in one cell. A block sent without a
 * place is put in the first free one. A report in rows refuses a place.
 *
 * @phpstan-import-type Place from ReportGrid
 */
final class ReportDefinition {

    /** The most blocks one definition holds. */
    public const MAX_BLOCKS = 40;

    /**
     * The most blocks a dashboard holds (#4962). A dashboard can be a
     * persona's home, the first screen after every login, so what it reads
     * stays small.
     */
    public const MAX_DASHBOARD_BLOCKS = 12;

    /** The largest stored definition, in bytes of JSON. */
    public const MAX_BYTES = 65536;

    public const WIDTH_FULL = 'full';
    public const WIDTH_HALF = 'half';
    public const WIDTHS     = [ self::WIDTH_FULL, self::WIDTH_HALF ];

    /**
     * The definition to store, or why it cannot be. Null, an empty string or
     * an empty object clears the definition. `$layout`, `$scope` and
     * `$audience` are the report's (#4572): a block bound to another layout,
     * or one that does not fit the report's subject or audience, is refused
     * on save rather than stored and then dropped when the report is read.
     * `$kind` caps a dashboard at `MAX_DASHBOARD_BLOCKS`.
     *
     * @return array{blocks:list<array{id:string, key:string, options:array<mixed>, width:string, grid?:Place}>}|StudioError
     */
    public static function normalise( mixed $raw, ?string $layout = null, ?string $scope = null, ?string $audience = null, ?string $kind = null ): array|StudioError {
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
        if ( $kind === ReportStartRules::KIND_DASHBOARD && count( $blocks ) > self::MAX_DASHBOARD_BLOCKS ) {
            /* translators: %d: maximum number of blocks */
            return self::invalid( sprintf( __( 'A dashboard holds at most %d blocks.', 'talenttrack' ), self::MAX_DASHBOARD_BLOCKS ) );
        }
        $is_grid = $layout === ReportStartRules::LAYOUT_GRID;

        $out    = [];
        $places = [];
        foreach ( $blocks as $i => $block ) {
            if ( ! is_array( $block ) ) return self::invalid( __( 'Every block must be an object.', 'talenttrack' ) );
            $extra = array_values( array_diff( array_map( 'strval', array_keys( $block ) ), [ 'id', 'key', 'options', 'width', 'grid' ] ) );
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
            // #4964 — a block that doesn't work for a group keeps the code a
            // refused block always had, says why in the library's words, and
            // names itself in the details (additive to v1).
            if ( $scope === ReportContext::SCOPE_GROUP && ! in_array( $scope, $registry->scopes(), true ) ) {
                return new StudioError( 'invalid_field', BlockRegistry::scopeReason( $scope ), 400, [ 'field' => 'definition', 'block' => $i, 'reason' => 'block_scope', 'key' => $key ] );
            }
            if ( ( $scope !== null && ! in_array( $scope, $registry->scopes(), true ) )
                || ( $audience !== null && ! in_array( $audience, $registry->audiences(), true ) ) ) {
                return new StudioError( 'invalid_field', __( 'This block cannot be used for this subject or audience.', 'talenttrack' ), 400, [ 'field' => 'definition', 'block' => $i ] );
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

            $entry = [ 'id' => $id, 'key' => $key, 'options' => $options, 'width' => $width ];
            if ( array_key_exists( 'grid', $block ) ) {
                if ( ! $is_grid ) {
                    return new StudioError( 'invalid_field', __( 'Only a report laid out as a grid places its blocks in a grid.', 'talenttrack' ), 400, [ 'field' => 'definition', 'block' => $i ] );
                }
                $place = ReportGrid::place( $block['grid'] );
                if ( is_string( $place ) ) {
                    return new StudioError( 'invalid_field', $place, 400, [ 'field' => 'definition', 'block' => $i ] );
                }
                $entry['grid'] = $place;
                $places[ $i ]  = $place;
            }
            $out[] = $entry;
        }

        if ( $is_grid ) {
            $placed  = array_values( $places );
            $overlap = ReportGrid::firstOverlap( $placed );
            if ( $overlap !== null ) {
                $index = array_keys( $places )[ $overlap ];
                return new StudioError( 'invalid_field', __( 'Two blocks cannot share a place in the grid.', 'talenttrack' ), 400, [ 'field' => 'definition', 'block' => $index ] );
            }
            $filled = [];
            foreach ( $out as $i => $entry ) {
                if ( ! isset( $entry['grid'] ) ) {
                    $free = ReportGrid::freePlace( $placed );
                    if ( $free === null ) {
                        return new StudioError( 'invalid_field', __( 'The grid has no room left for this block.', 'talenttrack' ), 400, [ 'field' => 'definition', 'block' => $i ] );
                    }
                    $entry['grid'] = $free;
                    $placed[]      = $free;
                }
                $filled[] = $entry;
            }
            $out = $filled;
        }

        $definition = [ 'blocks' => $out ];
        if ( strlen( (string) wp_json_encode( $definition ) ) > self::MAX_BYTES ) {
            /* translators: %d: maximum size in bytes */
            return self::invalid( sprintf( __( 'A definition may be at most %d bytes.', 'talenttrack' ), self::MAX_BYTES ) );
        }
        return $definition;
    }

    /**
     * The blocks of a stored definition, skipping anything malformed. For a
     * grid (`$layout`), each block carries its place: the stored one when it
     * is valid and free, else the first free one.
     *
     * @param array<mixed> $definition
     * @return list<array{id:string, key:string, options:array<mixed>, width:string, grid?:Place}>
     */
    public static function blocks( array $definition, ?string $layout = null ): array {
        $out = [];
        foreach ( is_array( $definition['blocks'] ?? null ) ? $definition['blocks'] : [] as $i => $block ) {
            if ( ! is_array( $block ) || ! is_string( $block['key'] ?? null ) ) continue;
            $out[] = [
                'id'      => is_string( $block['id'] ?? null ) ? (string) $block['id'] : 'b' . ( (int) $i + 1 ),
                'key'     => (string) $block['key'],
                'options' => is_array( $block['options'] ?? null ) ? $block['options'] : [],
                'width'   => ( $block['width'] ?? '' ) === self::WIDTH_HALF ? self::WIDTH_HALF : self::WIDTH_FULL,
                'stored'  => $block['grid'] ?? null,
            ];
        }

        $is_grid = $layout === ReportStartRules::LAYOUT_GRID;
        $taken   = [];
        $result  = [];
        foreach ( $out as $block ) {
            $stored = $block['stored'];
            unset( $block['stored'] );
            if ( $is_grid ) {
                $place = ReportGrid::place( $stored );
                if ( is_array( $place ) && ! self::collides( $place, $taken ) ) {
                    $block['grid'] = $place;
                } else {
                    $free = ReportGrid::freePlace( $taken );
                    if ( $free === null ) continue;
                    $block['grid'] = $free;
                }
                $taken[] = $block['grid'];
            }
            $result[] = $block;
        }
        return $result;
    }

    /**
     * @param Place       $place
     * @param list<Place> $taken
     */
    private static function collides( array $place, array $taken ): bool {
        foreach ( $taken as $other ) {
            if ( ReportGrid::overlaps( $other, $place ) ) return true;
        }
        return false;
    }

    private static function invalid( string $message ): StudioError {
        return new StudioError( 'invalid_field', $message, 400, [ 'field' => 'definition' ] );
    }
}
