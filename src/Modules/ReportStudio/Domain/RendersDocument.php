<?php
namespace TT\Modules\ReportStudio\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * RendersDocument (#5021, epic #4564) — a block that prints itself.
 *
 * Optional next to `ReportBlock`. `StudioDocument` prints every block from its
 * payload; a block that is a section of another report implements this so it
 * prints with that report's renderer and is measured with that report's
 * heights, and the two documents cannot show the same figures two ways. The
 * markup must be DomPDF-safe: tables, no flex or grid.
 */
interface RendersDocument {

    /**
     * The block's printed markup, from its payload alone, its own heading
     * included. Escaped; the document places it as it is.
     *
     * @param array<string,mixed> $data the block's payload, after the audience cut
     */
    public function document( array $data ): string;

    /**
     * The block's printed height in millimetres, for the page estimate.
     *
     * @param array<string,mixed> $data
     */
    public function documentHeight( array $data ): float;
}
