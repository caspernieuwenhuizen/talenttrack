# Foundation for parent-account management (#1815)

Groundwork for the upcoming Parent accounts admin surface: a dedicated
`tt_manage_parent_accounts` capability (granted to administrators, Club
Admins and Heads of Development, tunable per-persona via the authorization
matrix), a `ParentAccountService` for listing parents and linking/unlinking
a parent WordPress account on a player, and REST endpoints
(`POST`/`DELETE /players/{id}/parents`). No user-facing screen yet — that
arrives with the Parent accounts view.
