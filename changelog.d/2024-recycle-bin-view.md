# Recycle bin: centralized view + REST + settings entry point (#2024)

Bump: minor

Adds the centralized recycle bin — a single admin-only screen
(`?tt_view=recycle-bin`, reachable from Configuration → System) that lists
every trashed record across all 20 archivable entity types, grouped by type
with counts. Each row shows its identity, who and when it was binned, and a
days-until-purge badge that turns red in the final week. Two inline actions:
**Restore** returns the record to the archive, and **Delete now** permanently
purges it after a cascade-preview confirm. A blocked purge surfaces the
dependency report and leaves the record in place.

The bin is academy-admin only (`tt_manage_recycle_bin`). Three new REST
routes back it: `GET /recycle-bin` (cross-entity list), `POST
/recycle-bin/{entity}/{id}/restore`, and `DELETE /recycle-bin/{entity}/{id}`.
Every mutating route verifies both the capability and that the target belongs
to the current academy before it runs, so a forged or foreign-tenant id is a
not-found, never a silent success. The `{entity}` segment is validated against
the archive's entity allowlist.

Closes the "no purge path weaker than the bin" gap: every legacy per-entity
permanent-delete endpoint (`DELETE …/permanent`) is re-gated onto
`tt_manage_recycle_bin`, so all permanent-deletion paths now require the same
capability as the bin's own purge.
