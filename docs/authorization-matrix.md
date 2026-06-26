<!-- audience: admin -->

# Authorization matrix (admin guide)

**TalentTrack → Access Control → Authorization Matrix**

The authorization matrix is the single source of truth for "what can each persona do, on what?". Eight personas × ~30 entities × three activities (read / change / create_delete) = a few hundred cells. The shipped defaults match what each role does today; admins can edit per-cell to redefine the rules without writing code.

## When to edit it

Three real reasons to touch the matrix:

1. **A new persona joins the club.** You introduced a "Director of Football" alongside the Head of Development; the shipped matrix doesn't know about them. Add their persona to the seed file (or wait for the persona-management UI in v2 of the matrix epic).
2. **Default scope is wrong for your club.** Maybe Head Coaches should not be able to delete sessions in your setup. Toggle the `D` pill off for `head_coach × activities`.
3. **Compliance.** A board policy requires that scouts cannot read evaluations of players outside their assigned scouting region. Switch the scope from `global` to `team` for `scout × evaluations × read`.

Anything else — leave it alone. Editing the matrix is sharp; an admin who tightens a scope on the wrong cell can lock real users out of real surfaces.

## What the cells mean

Each cell on the grid is `(persona, entity, activity, scope)`:

- **R** — read. View / list / single-record display.
- **C** — change. Edit existing rows.
- **D** — create / delete. Add new rows + delete existing rows. One verb because the blast radius is similar.
- **Scope** — `global` (everywhere), `team` (only teams the user is assigned to), `player` (only the user's own player record / their child / their assigned trial player), `self` (only the user's own user record).

## Default vs admin-edited

- Cells filled from the shipped seed render **dimmed**.
- Cells you've changed render **bold**.
- The "Reset to defaults" button truncates `tt_authorization_matrix` and reseeds from `config/authorization_seed.php`. Every admin-edit you've made is lost. Logged in the changelog.

## The changelog

Every edit (grant, revoke, scope change, reset) writes a row to `tt_authorization_changelog`:

| Field | Meaning |
| - | - |
| `persona, entity, activity, scope_kind` | The cell that changed |
| `change_type` | `grant` / `revoke` / `scope_change` / `reset` |
| `before_value` / `after_value` | Boolean before/after |
| `actor_user_id` | Who clicked save |
| `note` | "scope: team → global" for scope_change rows |

Until #0021 (the audit log viewer epic) ships, the changelog only renders inside the Matrix admin page. After #0021 lands, these rows fold into the unified audit log.

## How to apply changes

New installs start with the matrix **already active** — a brand-new academy boots with matrix-driven authorization on, because the seeded matrix already covers every persona. (The activation runs once on a fresh install only; upgrading an existing site never flips it.) On an existing site the matrix stays dormant until an admin activates it deliberately, as below. To turn it off on a new install, open **TalentTrack → Access Control → Activate access control** and click **Rollback**, or set `tt_authorization_active` to `0` in `tt_config`.

Editing cells is **shadow-mode** until you click **Apply** in the Activate access control page (TalentTrack → Access Control → Activate access control).

While in shadow mode:

- The `tt_authorization_matrix` table reflects your edits.
- The legacy `current_user_can( 'tt_view_evaluations' )` calls still decide who can do what.
- Nothing breaks for real users; you can edit without fear.

When you click **Apply**:

- A flag (`tt_authorization_active`) flips to `1`.
- The `user_has_cap` filter routes every legacy `tt_*` capability check through the matrix.
- Real users see the new permissions on their next request.

Click **Rollback** to flip the flag back to `0` — matrix data is preserved; only the routing changes. Rollback is one-click; matrix-driven authorization is a deliberately reversible decision.

## The access-control preview

Before clicking Apply, the Activate access control page shows:

- Per-user **Gained** caps (matrix grants something the old caps didn't).
- Per-user **Revoked** caps (matrix denies something the old caps granted) — the dangerous column.
- A CSV download for offline review.

Empty Gained + empty Revoked = the matrix matches the legacy caps for that user. Most users in a fresh install start that way; the matrix exists primarily as a substrate for change, not as a behavior shift.

## Personas in v1

Eight personas ship in the seed:

- `player` — a player viewing their own data (self-scope on most reads).
- `parent` — a parent of a player (scoped to their child via `tt_player_parents`).
- `assistant_coach` — a `tt_coach` WP user with `tt_team_people.is_head_coach = 0` for at least one team.
- `head_coach` — a `tt_coach` WP user with `tt_team_people.is_head_coach = 1` for at least one team. A coach can hold both personas if they head-coach one team and assist another.
- `head_of_development` — `tt_head_dev` WP role; oversees the whole academy.
- `scout` — `tt_scout` WP role; reads players cross-team. Since v4.20.103 (#1378) evaluation reads are scoped to assigned players, and PDP files/verdicts are not granted at all — release deliberations are not scouting inputs.
- `team_manager` — new in #0033 Sprint 7; `tt_team_manager` WP role. Logistics for a team (sessions, attendance, invitations) without coaching authority.
- `academy_admin` — `administrator` or `tt_club_admin` WP role.

A user can hold multiple personas simultaneously (a parent who's also a head coach). The matrix uses the **union** by default — any persona that grants permission wins. The persona switcher in the user menu lets multi-persona users temporarily lens the dashboard to one persona's view; that's a UI lens, not an authorization restriction.

## Tournaments — admin-only in v1 (#0093)

The Tournament planner ships with two new capabilities — `tt_view_tournaments` and `tt_edit_tournaments`. v1 maps both to `administrator` + `tt_club_admin` only. No other persona (Coach, HoD, Scout, Player, Parent) holds either cap until the persona-expansion follow-up.

The caps are intentionally **not** in `RolesService::VIEW_CAPS` / `EDIT_CAPS` so they don't auto-propagate to HoD via `allViewCapsTrue()`. They live in their own `TOURNAMENTS_CAPS` constant; `ensureCapabilities()` grants them to WP `administrator` and the role definition for `tt_club_admin` lists them explicitly.

When the persona-expansion ship lands:

1. Map `tt_view_tournaments` → Coach + HoD + Scout, `tt_edit_tournaments` → Coach (own tournaments) + HoD.
2. Build `AuthorizationService::canViewTournament` / `canEditTournament` with creator / team-coach / global-staff logic (currently they defer to the cap check).
3. Swap REST `permission_callback`s from cap-only to per-entity checks.

## PDP visibility — one shared decision, frontend and REST (#1923)

PDP-file visibility is decided in a single place: `TT\Modules\Pdp\PdpAccess`. Both the rendered files tab (`FrontendPdpManageView`) and every REST surface (`PdpFilesRestController`, `PdpVerdictsRestController`) call `PdpAccess::canSeeFile( $user_id, $player_id )`, so the two sides can no longer answer differently — the cause of the head-coach-vs-HoD divergence in #1758.

The read ladder (matrix-aware, in order):

1. **Global PDP read** — a matrix `pdp_file/read/global` grant (Head of Development, Academy Admin), the WordPress site admin, the legacy `tt_edit_settings` umbrella, or the HoD / academy-admin persona fallback for installs whose matrix is still dormant.
2. **PDP editor of the player's team** — holds `tt_edit_pdp` and coaches the player's team (`coach_owns_player`).
3. **PDP viewer of the player's team** — holds `tt_view_pdp` and coaches the player's team.

`PdpAccess::canEditFile()` mirrors the ladder with the edit cap, and `PdpAccess::isGlobalVerdictAuthority()` answers "is this signer the head of academy?" via the matrix (`pdp_verdict/change/global`) instead of the old `tt_head_dev` role-name string compare (#0052 PR-B debt).

The previously login-only PDP REST callbacks were tightened to capability checks (`#0052`: capabilities are the contract, never `is_user_logged_in()` as authorization):

- `GET /pdp-blocks` and `GET /seasons` — admin-config reads, now gated on `tt_access_frontend_admin` via the matrix bridge (`AuthorizationService::userCanOrMatrix`). The write paths are unchanged (`tt_edit_settings`).
- `PATCH /pdp-conversations/{id}` — gated on `tt_view_pdp` presence; the authoritative per-row gate (coach-owns / linked player / linked parent) still lives in `allowedFieldsFor()`.

Effective access is unchanged — every actor who could read or edit a PDP before lands on the same answer; the work removed the frontend/REST drift and the role-name compare, it did not widen or narrow any persona.

## Team chemistry — one shared decision, frontend and REST (#1922)

Team-chemistry and Team-blueprint authorization is decided in a single place: `TT\Modules\TeamDevelopment\TeamChemistryAccess`. The rendered blueprint view (`FrontendTeamBlueprintsView`), the dashboard dispatcher gate for the `team-chemistry` / `team-blueprints` views, the share-link rotation handler, and every REST `permission_callback` on `TeamDevelopmentRestController` all call into it, so the frontend and the REST API can no longer answer differently.

The decision resolves through the `team_chemistry` matrix entity (`MatrixGate`), not the raw `tt_view_team_chemistry` / `tt_manage_team_chemistry` capabilities:

- `TeamChemistryAccess::canRead()` / `canManage()` — matrix `read` / `change` authority on `team_chemistry`, **ignoring** the `team_chemistry` sub-feature toggle (the Team blueprint editor deliberately stays available when the chemistry board feature is off).
- `TeamChemistryAccess::canReadChemistry()` / `canManageChemistry()` — the same authority **plus** the `team_chemistry` sub-feature being on (the chemistry-board surfaces, which honour the feature switch — #1485).

Because the matrix is now the single source of truth, two personas that previously held the raw read capability are no longer granted `team_chemistry` access:

- **Assistant coaches lose `team_chemistry` read.** The matrix omits `team_chemistry` from `assistant_coach` (removed by the #1060 "AC is operational, HC is development" editorial decision). Assistant coaches share the `tt_coach` WP role with head coaches, so the role still carries the cap, but the persona-aware matrix gate denies them. Head coaches (also `tt_coach`) keep access via their `team_chemistry [rc, team]` row.
- **Readonly observers lose `team_chemistry` read.** The all-areas observer (`tt_readonly_observer`) has no `team_chemistry` matrix row, so the gate denies it. The stale `tt_view_team_chemistry` role grant is revoked on upgrade so WP caps converge on the matrix authority.

Personas that keep access: `head_coach` (read + manage, team scope), `team_manager` (read, team scope), `scout` (read, global), `head_of_development` (read, global), `academy_admin` (read + manage, global). WP administrators and other holders of `tt_edit_settings` continue to bypass the per-team read gate as before.

## See also

- [Access control](access-control.md) — the broader role + capability model.
- [Modules](modules.md) — disabling a module short-circuits its matrix rows.
- [Tournaments](tournaments.md) — user-facing guide for the planner.
