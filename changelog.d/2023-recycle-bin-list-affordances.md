# Recycle bin: archived-list affordances + payload audit (#2023)

Bump: minor

Fixes a bug where archived holiday rows showed only Restore and no
destructive action: the holiday REST payload omitted `archived_at`, so the
list-table visibility check hid both archived-row actions. A new shared
`LifecycleFields` helper now emits `archived_at` plus the new `trashed_at`
on every list/detail payload that surfaces lifecycle state, so the field
can't drift per entity.

The archived-tier destructive action is relabelled from "Delete permanently"
to **"Move to recycle bin"** and re-pointed at a new reversible
`POST {entity}/{id}/trash` route (the irreversible purge stays inside the
recycle bin). Moving a record now shows a full itemized cascade preview in
the confirm dialog, and the success banner offers one-click **Undo**. The
per-entity "All" status tab is dropped — trashed records never appear in
ordinary lists, leaving Active and Archived as the only views.
