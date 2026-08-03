# Archived-record actions hidden without permission + correct confirm titles (#2330)

The archived/trashed record card now hides lifecycle buttons whose REST route the
current user can't reach: "Move to recycle bin" only shows for users who can manage
settings, and "Restore to archive" / "Delete permanently now" only for recycle-bin
managers. Head coaches no longer hit a dead-end "Action failed." on an archived
record. The confirm-modal title now matches the action ("Move to recycle bin",
"Restore record", "Delete permanently") instead of always reading "Archive record".
