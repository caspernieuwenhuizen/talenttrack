Bump: minor

**An academy admin can now edit the authorization matrix without a WordPress
account.** The persona × entity grid has a frontend surface at
**Configuration → Authorization matrix**, gated on a new
`tt_manage_authorization` capability granted to administrator and Club Admin.
Until now the only editor was in wp-admin behind an administrator account, so
an academy without one on hand could not correct an over-broad or too-narrow
grant at all — and those grants decide who can open a player's evaluations,
notes and medical fields.

**What a Club Admin cannot do is the reason this could ship.** Their own
persona row is locked, and so are the entities that govern the permission
model, the schema and the backups. The lock is enforced when the save is
applied, not merely in the markup: a hand-crafted form post or a direct REST
call against a protected cell is rejected and writes neither a matrix row nor a
changelog entry. Reset-to-defaults, the seed export/import round-trip and the
matrix on/off switch were not delegated and stay administrator-only in
wp-admin — which also keeps that page as the recovery path, since a bad matrix
edit can hide the frontend surfaces that lead back to the matrix.

The save-and-audit logic moved out of the wp-admin controller into a shared
`MatrixEditService`, so the two screens and the new REST routes
(`GET`/`PUT /authorization/matrix`, `POST /authorization/matrix/reset`) write
identically. Behaviour for a WordPress administrator is unchanged on both
surfaces.
