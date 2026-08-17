<?php
namespace TT\Modules\DemoData\Generators;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Every demo generator declares the coverage category it fills, so the
 * orchestrator, the generate form and the wipe cascade all agree on one key.
 */
interface GeneratorInterface {

    /** The `DemoCoverage::CATEGORIES` key this generator writes. */
    public static function category(): string;
}
