# Weekly planner PDF no longer carries the browser's URL footer (#2791)

Bump: patch

Saving the weekly planner sheet as a PDF put the browser's own header and
footer on the paper — the page URL and page number along the bottom, the
document title and date across the top. The sheet handed its whole paper
margin to the page box, and that margin is exactly the band a browser prints
those into.

The sheet now carries the 14mm margin as its own padding and leaves the page
box at zero, so there is nowhere for the band to print. Nothing on the sheet
moves: the printed geometry is identical to before, minus the browser's
additions. Same approach the goal-intake and methodology-reference print
sheets already use.
