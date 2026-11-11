<?php
namespace TT\Modules\ReportStudio\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * FamilySection (#5021, epic #4564) — the section of the child's file a block
 * a family may receive is made of.
 *
 * A block whose `audiences()` holds the family names its section here, and a
 * shared copy answers to that section's rule for each family reader: their
 * grant on its records and, for a parent, the child's own choice of what
 * their parents see (`PlayerReportSnapshots::familyMayRead()`). The layout
 * blocks are the document's own words and name `DOCUMENT`. A block that does
 * not implement this is never sent to a family.
 */
interface FamilySection {

    /** The document's own words: a letterhead, a heading, a text, a page break. */
    public const DOCUMENT = 'document';

    /**
     * A `PlayerReportBlock` section key, or `DOCUMENT`.
     */
    public function familySection(): string;
}
