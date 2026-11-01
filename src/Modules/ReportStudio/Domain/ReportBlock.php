<?php
namespace TT\Modules\ReportStudio\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * ReportBlock — one Studio block (#4568, epic #4564).
 *
 * A block reads its figure through a shared read (a report query or
 * `FactQuery`), never SQL of its own, and returns JSON data, never HTML.
 * `available()` keeps a block that cannot return data off the list, so the
 * Studio never offers a stub.
 */
interface ReportBlock {

    /** Stable key, used in URLs and stored reports. */
    public function key(): string;

    /** Translated label. */
    public function label(): string;

    /**
     * Subject scopes the block supports.
     *
     * @return list<string> values of ReportContext::SCOPES
     */
    public function scopes(): array;

    /**
     * Audiences the block may appear for.
     *
     * @return list<string> PlayerReportAudience::INTERNAL | SCOUT | FAMILY
     */
    public function audiences(): array;

    /** The capability the block reads with. */
    public function capability(): string;

    /**
     * Typed options the block accepts.
     *
     * @return list<BlockOption>
     */
    public function options( ReportContext $context ): array;

    /** Can the block return data in this context at all? */
    public function available( ReportContext $context ): bool;

    /**
     * Payload keys an external audience may receive. Everything else is
     * removed from the payload before it leaves the engine.
     *
     * @return list<string>
     */
    public function externalKeys(): array;

    /**
     * The block's data for this context and these validated options.
     *
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function render( ReportContext $context, array $options ): array;
}
