# Ratings grid: main and sub categories are now visibly separate columns (#2432)

The grid's column headers were a single flat row, so there was no way to see
which columns were main categories and which were sub-categories underneath
them — the structure every other evaluation screen makes visible was lost
here. Worse, the columns were sorted on display order alone, which did not
keep a sub-category next to its own parent, so related columns could end up
scattered across the grid.

The header is now two rows. A main category spans its own block, its
sub-categories sit underneath it, and a main you rate directly keeps its own
column labelled *Main score* alongside them — so you can score at main level,
sub level, or both. Sub-categories are always adjacent to their parent, and a
separator marks where each main's block begins so the eye can track it while
scrolling sideways.

Sub-categories start collapsed and each main expands on its own, which keeps a
detailed methodology from spreading a squad across an unusably wide grid. A
main whose sub-categories already hold scores for that activity opens expanded,
so reopening a detailed rating shows what was entered rather than hiding it.
Collapsing never hides pending work: the header counts the unsaved scores
folded away, those scores still save, and a score outside the scale forces its
main back open because it blocks saving until corrected.

Keyboard navigation now walks the visible cells rather than counting header
cells, so the arrow keys stay correct across two header rows and after any
expand or collapse.
