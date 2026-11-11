<?php
namespace TT\Modules\Analytics\Reports;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * DescribesOptions (#4961, epic #4564) — a block's options as a list of
 * fields, so a second surface can offer them without declaring them again.
 *
 * The Report Studio builds its block options from this list
 * (`ReportStudio\Domain\SectionOptions`). The class's own `unknownKeys()`
 * reads the same list, so an option the class accepts is always one the
 * Studio offers.
 *
 * A field is one of three kinds:
 *
 * - `level` — the Summary / Details choice (`SectionLevel`);
 * - `switch` — on or off;
 * - `ids` — a list of record ids, offered from `fieldChoices()`.
 */
interface DescribesOptions extends BlockOptionsInterface {

    public const KIND_LEVEL  = 'level';
    public const KIND_SWITCH = 'switch';
    public const KIND_IDS    = 'ids';

    /**
     * The fields, in the order a panel offers them, each with what it means
     * when nothing is recorded.
     *
     * @return list<array{key:string, kind:string, default:mixed}>
     */
    public static function fields(): array;

    /** A field's translated label. */
    public static function fieldLabel( string $key ): string;

    /**
     * The values an `ids` field offers: id => label. Empty for any other kind.
     *
     * @return array<int,string>
     */
    public static function fieldChoices( string $key ): array;
}
