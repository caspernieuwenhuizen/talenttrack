# Injuries: one derived status, answered in one place (#4281)

Whether an injury is open, overdue, incomplete or closed is now decided by
one class, `InjuryStatus`, derived on read and never stored. Planning
(`PlayerAvailability`), the squad overview's list, the player's Injuries tab
and the overview table all read it, and the planning rule's SQL now lives
beside its PHP twin, with a test that the two agree on every case. The
injury list in `GET /players/{id}/injuries` gains a `status` field. Nothing
changes on screen or in planning yet: what planning does with an overdue or
incomplete record is decided separately. An open record without an expected
return counts as incomplete after `injury_incomplete_after_days` days
(`tt_config`, default 14).
