<?php
namespace TT\Modules\Import;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * ImportTagSink — where an import records the rows it created.
 *
 * The importer knows it produced a team with id 42; it does not know
 * whether that team is demo data destined for the demo cleaner or a
 * real club's squad. The sink decides.
 *
 * `DemoBatchRegistry` satisfies this for demo imports and writes to
 * `tt_demo_tags`. Real imports get their own implementation writing to
 * their own tables, so the demo cleaner cannot reach a real row.
 */
interface ImportTagSink {

    /** The batch these rows belong to. */
    public function batchId(): string;

    /**
     * Record that the import created `$entity_id` of type `$entity_type`.
     *
     * @param array<string,mixed> $extra
     */
    public function tag( string $entity_type, int $entity_id, array $extra = [] ): void;
}
