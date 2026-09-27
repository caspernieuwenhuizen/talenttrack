# A player's tournament minutes are the figures the coach confirmed (#4053)

The completion step asks a coach to confirm what each child actually played on
a tournament fixture, because the auto-planned numbers were wrong — two keepers
on ten minutes and thirteen children on nil, when they had all played about
twelve. The minutes overview and the minutes reports read those confirmed
figures; the player's own **Tournaments** tab read the rotation plan instead, so
a correction never reached the record it was made for.

The register is now a player's tournament minutes everywhere, and the rotation
plan is the fallback where nothing was confirmed. One resolver answers for the
Tournaments tab, the coach's minutes ticker, the minutes grid and the minutes
reports, so two screens can no longer disagree about one child's afternoon. A
row that is not a confirmed figure is marked **Planned** — a fixture still to
come, or one completed before the confirm step shipped.

A confirmed nil stays nil: if a coach recorded that a child did not get on, the
tab shows 0 minutes and reads *Bench* rather than falling back to the plan's
number. Nothing was migrated or backfilled — a fixture from last season falls
back to its plan, and the minutes reports now count it instead of reading the
whole squad as nil.
