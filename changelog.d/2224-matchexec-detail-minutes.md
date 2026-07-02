# Match execution detail: linked activity + correctable recorded minutes (#2224)

Bump: patch

The match-execution screen now links its parent activity through the
breadcrumb chain (Dashboard / Activities / {activity} / Match execution),
so the activity is both visible and one tap away — no hand-rolled back
button. On a **finalized** execution it also adds a **Correct recorded
minutes** action: a coach with `tt_edit_activities` can edit each player's
recorded minutes with numeric inputs and Save (or Cancel back to the
read-only detail). Minutes are only correctable post-finalize, where no
auto-recompute can clobber the manual value; the correction writes through
the existing row-scoped `PATCH /attendance/{id}` path (its minutes column
now accepts a clamped 0–200 value), so the figure flows straight into the
minutes reports without reopening the locked match. No new endpoint,
capability, or schema change.
