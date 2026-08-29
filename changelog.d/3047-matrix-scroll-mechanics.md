# The access-control matrix is one scroll, not two (#3047)

Bump: patch

The permissions grid used to sit in a box with its own scrollbars inside a page that also scrolled: reading one persona's grants meant dragging two of them, and losing the row and column headings on the way. The grid now scrolls sideways only, and the page owns the vertical axis, so reading an entity list top to bottom is one continuous scroll.

The entity column still stays put while the persona columns move under it, and every category band now repeats the persona names, so the column you are looking at is identified within a band rather than only at the very top of the table.

Scope has moved out of the cells onto its own line. Each entity row carries a small **Scope** button that opens a row of scope dropdowns underneath — still one per persona, so nothing about who can see what has changed; it simply no longer makes every entity two rows tall whether you are looking at it or not.
