# Recycle bin: archive → trash → purge lifecycle core (#2021)

Bump: minor

Adds the recycle-bin domain core to `ArchiveRepository`: a third soft-delete
tier (active → archived → trashed → purged) layered on the existing archive.
Entities can now be moved to a recycle bin, restored back to archived, or
permanently purged through the existing fail-closed cascade. Trashed records
of minors are hidden behind the `tt_manage_recycle_bin` capability and scoped
to the club on every query, and each transition is recorded in the audit log.
Domain layer only — the bin's list view and REST endpoints land in follow-up
work; no user-visible screens change yet.
