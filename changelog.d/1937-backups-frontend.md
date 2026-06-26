# Backups move to a frontend view, incl. restore + data migration (#1937)

Bump: minor

The Backups surface now lives on the frontend at **Configuration → Backups**
(`?tt_view=backups`) instead of bouncing to wp-admin. The full surface ported
across: schedule / retention / destination settings (with Cancel + Save),
the stored-backups list (download, restore, delete), Run now, the destructive
database **restore** behind a typed-confirm "RESTORE" gate, and the complete
`.ttmig` data-migration flow — export, then upload → preview → dry-run →
typed-confirm "IMPORT" commit.

Every mutating action runs through a capability-gated, nonce-protected REST
endpoint (`tt_manage_backups`) on the new `BackupRestController`; the
serialization, restore engine and migration engine stay in the Backup module
services, so the frontend and the wp-admin page give identical answers. The
two destructive writes (restore + import commit) preserve the typed
confirmation, refuse to run while impersonating another user, and are written
to the audit log (`backup.restored` / `migration.imported`). Backup downloads
are returned as a URL rather than a server-relative path, so the list keeps
working unchanged if storage moves off the local filesystem.

The wp-admin Backups tab stays as the power-user fallback and still owns the
Partial restore scope-picker; the frontend list links to it.
