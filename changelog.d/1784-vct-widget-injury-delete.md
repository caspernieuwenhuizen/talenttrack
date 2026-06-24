# Safe permanent delete for VCT exercises, custom widgets + injuries (#1784)

Bump: patch

Extends the referential-integrity delete framework (#1783) to the last of
the rollout entities, plus a framework enhancement: cascade plans can now
**table-qualify** a reference column, so an ambiguous column name (e.g.
`exercise_id`, which keys both `tt_exercises` and the VCT tables) is scanned
on the right tables only.

- **VCT exercise** — cascades its coaching points; clears the exercise link
  on any session block. New `/vct/exercises/{id}/permanent` route.
- **Custom widget** — standalone; removed directly. New
  `/custom-widgets/{id}/permanent` route (uuid- or id-keyed).
- **Injury** — removes the injury and its journey-timeline events (a minor's
  medical record), so a right-to-erasure delete actually erases. New
  `/player-injuries/{id}/permanent` route.

All fail-closed, gated by `tt_edit_settings` (VCT: `can_admin`). No
migration. The `archived_by`-column migration + list-view delete
affordances for the full archive-lifecycle UI remain on #1784.
