<?php
namespace TT\Modules\ReportStudio\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * BlockRegistry — every Studio block, by key (#4568).
 */
final class BlockRegistry {

    /** @var array<string,ReportBlock> */
    private static array $blocks = [];

    public static function register( ReportBlock $block ): void {
        self::$blocks[ $block->key() ] = $block;
    }

    public static function find( string $key ): ?ReportBlock {
        return self::$blocks[ $key ] ?? null;
    }

    /** @return array<string,ReportBlock> */
    public static function all(): array {
        return self::$blocks;
    }

    /**
     * The blocks this reader may place in this context: the scope and the
     * audience fit, the reader holds the block's capability, and the block
     * can return data. A block that cannot is not listed.
     *
     * @return list<ReportBlock>
     */
    public static function availableFor( ReportContext $context ): array {
        $out = [];
        foreach ( self::$blocks as $block ) {
            if ( ! self::fits( $block, $context ) ) continue;
            if ( ! user_can( $context->userId, $block->capability() ) ) continue;
            if ( ! $block->available( $context ) ) continue;
            $out[] = $block;
        }
        return $out;
    }

    public static function fits( ReportBlock $block, ReportContext $context ): bool {
        return in_array( $context->scope, $block->scopes(), true )
            && in_array( $context->audience, $block->audiences(), true );
    }

    /** For tests that register their own blocks. */
    public static function clear(): void {
        self::$blocks = [];
    }
}
