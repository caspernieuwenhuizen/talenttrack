Bump: patch

**A head coach no longer sees the Scouting visits tile.** Planning and logging
outbound scouting visits is the scout's work; a head coach was being offered it
because the tile authorised on the same `prospects` record type as their own
onboarding funnel, and the two had been inseparable since an unrelated fix in
v4.20.2.

The tile now has a visibility setting of its own, granted to scouts, the head of
development and the academy admin. The head coach **keeps the onboarding
pipeline for their own age group** — that was deliberate, and removing the
prospects grant to hide the visits tile would have taken the funnel with it.
Direct navigation to the visit planner and to a single visit is refused for the
same personas the tile is hidden from.

Nothing changes for a scout, a head of development or an academy admin. An
upgrade backfills the new setting, so the tile does not disappear while the
matrix catches up.
