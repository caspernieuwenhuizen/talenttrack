<?php
namespace TT\Modules\ReportStudio\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Analytics\Domain\UnknownFactKeyException;

/**
 * ChecksFactKeys (#4860) — a block whose stored options name analytics
 * fields (a fact, a measure, a dimension) says whether they still exist.
 *
 * A saved report keeps those names for months. When a field is renamed the
 * fact declares an alias and the block reads it as the new name; when it is
 * gone the block is refused on save and shown as "refers to a field that no
 * longer exists" when the report is read, instead of being read for more rows
 * than it asked about.
 */
interface ChecksFactKeys {

    /**
     * The options with every alias resolved to the current key.
     *
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     * @throws UnknownFactKeyException for a field that is neither declared nor aliased.
     */
    public function resolveStoredKeys( array $options ): array;
}
