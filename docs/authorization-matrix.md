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

Editing cells is **shadow-mode** until you click **Apply** in the Migration Preview page (TalentTrack → Access Control → Migration preview).

While in shadow mode:

- The `tt_authorization_matrix` table reflects your edits.
- The legacy `current_user_can( 'tt_view_evaluations' )` calls still decide who can do what.
- Nothing breaks for real users; you can edit without fear.

When you click **Apply**:

- A flag (`tt_authorization_active`) flips to `1`.
- The `user_has_cap` filter routes every legacy `tt_*` capability check through the matrix.
- Real users see the new permissions on their next request.

Click **Rollback** to flip the flag back to `0` — matrix data is preserved; only the routing changes. Rollback is one-click; matrix-driven authorization is a deliberately reversible decision.

## The migration preview

Before clicking Apply, the Migration Preview page shows:

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
- `scout` — `tt_scout` WP role; reads players + evaluations cross-team.
- `team_manager` — new in #0033 Sprint 7; `tt_team_manager` WP role. Logistics for a team (sessions, attendance, invitations) without coaching authority.
- `academy_admin` — `administrator` or `tt_club_admin` WP role.

A user can hold multiple personas simultaneously (a parent who's also a head coach). The matrix uses the **union** by default — any persona that grants permission wins. The persona switcher in the user menu lets multi-persona users temporarily lens the dashboard to one persona's view; that's a UI lens, not an authorization restriction.

## See also

- [Access control](access-control.md) — the broader role + capability model.
- [Modules](modules.md) — disabling a module short-circuits its matrix rows.
