<?php
namespace TT\Modules\ReportStudio\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * LayoutBound (#4572) — a block that only makes sense in some report
 * layouts. A page break means something in rows, which print, and nothing in
 * a grid, which is for screens.
 *
 * The library lists such a block switched off, with the reason, for a report
 * in another layout, and a definition holding it is refused on save.
 */
interface LayoutBound {

    /**
     * The layouts the block may be placed in.
     *
     * @return list<string> values of ReportStartRules::LAYOUTS
     */
    public function layouts(): array;

    /** Why the block cannot be placed in another layout, in the library's words. */
    public function layoutReason(): string;
}
