# Recycle bin: read-only archived/trashed detail (#2022)

Bump: minor

Fixes Bug 1: opening an archived or trashed record showed "does not exist",
because every detail view's lookup ends in `WHERE archived_at IS NULL` and so
never received a non-active row. Detail views (players, teams, evaluations,
goals) now retry through the archive-aware visibility gate and render a
**compact read-only summary card** for archived and trashed records instead —
the record's identity plus a few key fields and a status banner, with no Edit
affordance (restore first, then edit).

An **archived** record shows an amber banner with who archived it and when,
plus **Restore** and **Move to recycle bin**. A **trashed** record shows a red
banner counting down to the purge, plus **Restore to archive** and **Delete
permanently now**, wired to two new `tt_manage_recycle_bin`-gated REST routes
(`POST recycle-bin/{entity}/{id}/restore`, `DELETE recycle-bin/{entity}/{id}`).

Privacy-critical: a trashed record is a soft-deleted minor's record. A
non-admin who opens a trashed record's link gets a clean "not found" — never a
permission-denied page that would confirm the record exists. The card lives in
a single shared `ArchivedDetailCard` renderer so the banners and actions can't
drift per entity.
