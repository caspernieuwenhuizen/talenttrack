# Recycle-bin foundation: schema, capability, retention config (#2020)

Bump: minor

Lays the groundwork for the recycle bin (archive → trash → purge). Every
archivable record type now carries `trashed_at` / `trashed_by` columns, so a
later release can stage records for permanent deletion with a recovery window.

A new academy-admin-only capability, `tt_manage_recycle_bin`, owns permanent
deletion — it is never granted to coaches, Heads of Development, or anyone
holding only settings rights. A per-club retention window
(`tt_recycle_bin_retention_days`, default 30) is seeded for the future purge
process, and the bin gets its own `recycle_bin` authorization-matrix entity.

No user-visible behaviour changes yet — this is the substrate the bin's UI and
purge logic build on. See the new Recycle bin help page for the retention and
GDPR right-to-erasure basis.
