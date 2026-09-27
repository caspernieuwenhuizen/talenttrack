<?php
namespace TT\Modules\Analytics\Reports;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * LevelBlockOptions (#4133, epic #4094) — the options of a section whose only
 * choice is Summary / Details: attendance and minutes share.
 *
 * - **Summary** — the squad figure, who is below the line, and (attendance)
 *   the absences by kind.
 * - **Details** — the bar per player. The default, because that is what both
 *   sections printed before the choice existed, so a report nobody touched
 *   prints as it did.
 *
 * The default is recorded as absence (`BlockOptionsInterface`).
 */
final class LevelBlockOptions implements BlockOptionsInterface {

    public const LEVEL = SectionLevel::KEY;

    public const DEFAULT_LEVEL = SectionLevel::DETAILS;

    /**
     * @param array<string,mixed> $raw
     * @return array<string,mixed>
     */
    public static function normalise( array $raw ): array {
        $level = SectionLevel::parse( $raw[ self::LEVEL ] ?? null );
        return $level !== null && $level !== self::DEFAULT_LEVEL ? [ self::LEVEL => $level ] : [];
    }

    /**
     * @param array<string,mixed> $raw
     * @return list<string>
     */
    public static function unknownKeys( array $raw ): array {
        return array_values( array_filter(
            array_map( 'strval', array_keys( $raw ) ),
            static fn( string $key ): bool => $key !== self::LEVEL
        ) );
    }

    /**
     * The level a composition asked for, defaulted.
     *
     * @param array<string,mixed> $options
     */
    public static function level( array $options ): string {
        return SectionLevel::parse( $options[ self::LEVEL ] ?? null ) ?? self::DEFAULT_LEVEL;
    }
}
