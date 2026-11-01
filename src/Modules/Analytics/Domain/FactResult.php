<?php
namespace TT\Modules\Analytics\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * FactResult — the rows of one fact query and whether the row cap cut them
 * off (#4568). A consumer that shows the rows says so when `truncated` is
 * true, so a capped breakdown is never read as the whole picture.
 */
final class FactResult {

    /**
     * @param array<int,\stdClass> $rows
     */
    public function __construct(
        public readonly array $rows,
        public readonly bool $truncated
    ) {}
}
