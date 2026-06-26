# Spond integration moves to a frontend view (#1936)

Bump: minor

The Spond integration now lives on the frontend at **Configuration → Spond
integration** (`?tt_view=spond`) instead of bouncing to wp-admin. The full
surface ported across: per-team sync status with a "Refresh now" button,
the next-automatic-sync time, encrypted account credentials (save / test /
disconnect), and the collapsible API base-URL override. The Spond password
stays encrypted at rest via `CredentialsManager` and is never shown back —
a connected account displays "Connected as <email>" with a blank password
field. New REST endpoints back every action: `POST/DELETE /spond/credentials`,
`POST /spond/test`, `POST /spond/base-url` (gated on `tt_edit_spond_credentials`)
plus the existing `POST /teams/{id}/spond/sync` (gated on `tt_edit_teams`).
The wp-admin page stays as the power-user fallback.
