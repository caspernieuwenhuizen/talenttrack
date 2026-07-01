# Player comparison: filters moved to the shared FilterBar (#2176)

Bump: patch

The player-comparison filter block (Date from/to and Evaluation Type) now
uses the shared FilterBar component: an inline single-line row on desktop
and a "Filters" button + bottom sheet on phones and tablets. The date range
and evaluation-type filter drive the comparison identically — same
parameters, same results — and the Compare action still submits the player
picks together with the filters. The bespoke filter styling was removed.
