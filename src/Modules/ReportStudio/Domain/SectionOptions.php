<?php
namespace TT\Modules\ReportStudio\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Analytics\Reports\BlockOptionsInterface;
use TT\Modules\Analytics\Reports\DescribesOptions;
use TT\Modules\Analytics\Reports\SectionLevel;

/**
 * SectionOptions (#4961, epic #4564) — a report section's options as Studio
 * block options, generated from the class that owns them.
 *
 * The section's option class lists its fields (`DescribesOptions`), and its
 * `unknownKeys()` accepts exactly those, so the Studio offers every option the
 * report has and none it does not. The validated values are passed to the
 * section's composer as they are; the class's `normalise()` reads them there,
 * so a default reads as the report's default.
 */
final class SectionOptions {

    /** Past this many ids a list is a hand-edited request, not a choice. */
    public const MAX_IDS = 30;

    /**
     * @param class-string<BlockOptionsInterface>|null $owner
     * @return list<BlockOption>
     */
    public static function of( ?string $owner ): array {
        if ( $owner === null || ! is_subclass_of( $owner, DescribesOptions::class ) ) return [];

        $out = [];
        foreach ( $owner::fields() as $field ) {
            $key   = (string) $field['key'];
            $label = $owner::fieldLabel( $key );
            switch ( $field['kind'] ) {
                case DescribesOptions::KIND_LEVEL:
                    $out[] = new BlockOption( $key, BlockOption::TYPE_ENUM, $label, SectionLevel::labels(), false, $field['default'] );
                    break;
                case DescribesOptions::KIND_SWITCH:
                    $out[] = new BlockOption( $key, BlockOption::TYPE_BOOL, $label, [], false, (bool) $field['default'] );
                    break;
                case DescribesOptions::KIND_IDS:
                    $choices = [];
                    foreach ( $owner::fieldChoices( $key ) as $id => $name ) $choices[ (string) $id ] = $name;
                    $out[] = new BlockOption( $key, BlockOption::TYPE_IDS, $label, $choices, false, [], null, null, self::MAX_IDS );
                    break;
            }
        }
        return $out;
    }
}
