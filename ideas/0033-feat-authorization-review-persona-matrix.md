<!-- type: epic -->

# Authorization review — persona-driven (entity, activity, scope) matrix + tile/menu re-organisation

Origin: 26 April 2026 conversation. Today's authorization is a hand-rolled mix of WP roles (`tt_player`, `tt_coach`, `tt_head_dev`, `tt_readonly_observer`, `tt_staff`, `tt_scout`, `tt_parent` (planned in #0032), `tt_team_manager` (??)), capabilities split into view/edit pairs (v3.0.0), Functional Roles (a separate per-team-per-person assignment model), and per-scope rows in `tt_user_role_scopes`. Tiles and menu items are gated by individual cap checks scattered across the dispatchers. The result works but isn't legible — there's no single place that answers "what can a Head of Development actually do, on what, where?", and re-labelling tiles per persona today requires touching multiple dispatchers.

This idea proposes a single coherent persona-matrix model: every persona maps to a profile of (entity, activity, scope) triples, the dashboard tile grid + menu render only what the active persona allows, multi-persona users see a consolidated view, and the matrix is editable from a wp-admin page so tweaks don't need a release.

## Why this is meaningful now

- **Multi-persona users are the norm.** A parent-of-player is also a coach; a coach is also the academy admin; an HoD is also a staff member of one specific team. Today the user has one WP role at a time and the right capability set is approximated. A persona matrix lets the user be many things and the UI consolidates them sensibly.
- **Tile/menu naming should follow the active persona.** A coach sees "Players" and means "the players I coach"; a parent sees "Players" and means "the player I'm a parent of". Today both see the same tile with the same label; navigating into the tile then filters to the right scope. The label itself should change.
- **#0032 invitations** introduces a dedicated `tt_parent` role + per-team Functional-Role-driven WP-role assignments. The matrix needs to know what `tt_parent` can do — and that decision shouldn't be made by ad-hoc cap calls scattered across modules.
- **#0017 trial player module** adds a "trialist" persona. Currently it has no formal authorization shape.
- **#0011 monetization caps** live partly inside `LicenseGate`, partly in module-specific checks. Persona matrix gives `LicenseGate` a single point to query.

## Personas (in scope for v1)

The eight personas Casper listed plus three observations:

| Persona | Today's WP role | Notes |
| --- | --- | --- |
| Parent | `tt_parent` (planned in #0032) | Read access scoped to their child(ren). |
| Player | `tt_player` | Read access scoped to themselves. |
| Assistant coach | `tt_coach` (no distinction from head coach) | Per-team, edit on sessions/evaluations/attendance. |
| Head coach | `tt_coach` | Per-team, full coaching surface incl. delete. |
| Head of Development | `tt_head_dev` | Cross-team, includes club-wide reports + roster admin. |
| Scout | `tt_readonly_observer` (loosely) or new `tt_scout` | Read across teams + targeted edit on trial cases (#0017). |
| Team manager | (no role today) | Per-team, non-coaching admin work — schedule, attendance, parent comms. |
| Academy admin | `administrator` (WP) | Full configuration + module toggles + license. |

**Three flags during shaping:**

- The current `tt_coach` doesn't distinguish head from assistant. The matrix can either (a) introduce two distinct WP roles, or (b) use a single `tt_coach` role plus a Functional Role flag (`is_assistant`). **Recommendation: (b) — Functional Roles already exist as the per-team assignment layer; head/assistant is a property of the assignment, not a distinct WP role.**
- "Team manager" doesn't exist as a WP role yet. v1 needs to add it.
- "Scout" is currently approximated by `tt_readonly_observer`. With #0017 ramping up, scouts need targeted edit rights on trial cases — a real `tt_scout` role with a non-trivial profile.

## Activities

Casper's three (display/read, change, create/delete-as-one) are the right v1 set. Flag for v2:

- **Approve / sign-off** (e.g., HoD approves a coach's evaluation, parent acknowledges a goal). Doesn't exist today.
- **Comment / discuss** (when #0028 conversational goals ships).
- **Print / export to PDF** (rate-card + evaluation prints — currently authority-checked the same as "view", but in some clubs printing is treated more carefully).
- **Bulk verbs** (bulk archive / bulk delete) — high-blast-radius, may want a dedicated "bulk" activity.

For v1: stick with **read / change / create-or-delete**. Note v2 candidates in the idea so the matrix has obvious extension points.

## Entities

Casper's list, plus what's missing or worth grouping differently:

### Casper's list
team, player, sessions, evaluations, goals, people, player compare, rate cards, bulk import players, custom fields, evaluation categories, usage statistics, configuration (eval types / positions / preferred foot / age categories / goal statuses / goal priorities / attendance statuses / rating scales / module toggles / branding), reports (rename "analytics"), migrations, roles, help and documentation, audit log, authorization check, category weight for evaluation categories.

### Additions to consider

- **Functional Roles** (the *types* — Head Coach, Assistant Coach, Physio, etc.) — separate from WP role; needs its own gate.
- **Functional Role assignments** — the per-team-per-person rows that drive the WP-role grants. Editing these needs auth.
- **Methodology** (#0027 — formations, principles, positions, set pieces, learning goals, methodology assets). Already shipped; needs a row in the matrix.
- **Backup snapshots + restore** (#0013) — admin-only verb, distinct from "configuration".
- **Demo data + demo mode toggle** (#0020) — admin-only, has its own scope.
- **Trial cases** (#0017, planned) — per-player + per-trial, scout has targeted access.
- **Custom field VALUES** (per-player) — distinct from custom field DEFINITIONS. Different verbs apply.
- **License / account / billing** (#0011) — admin-only.
- **Setup wizard state** (#0024) — admin-only, runs once per install.
- **Spond integration config** (#0031, planned) — per-team, admin-or-HoD.
- **Invitations** (#0032, planned) — per-target-entity, who-can-invite-whom.
- **My-* surfaces** (my profile, my card, my evaluations, my goals, my sessions, my team) — implicitly scoped to "self", but listing them as entities makes the matrix complete.
- **Dashboard tile grid + landing** — what tiles even appear is a function of the matrix.
- **Notifications** — doesn't exist yet; flag for future.
- **Comments / threads** (when #0028 ships).

### Cross-cutting — scope

The current system already has scope rows (`tt_user_role_scopes`: global / team / player). The matrix is really (persona × activity × entity × **scope**), not just (persona × activity × entity). Scope determines:

- A coach has `change` on `evaluations` **scoped to teams they're assigned to**, not globally.
- A parent has `read` on `players` **scoped to their children**, not the whole roster.
- A scout has `read` on `players` **globally** but `change` on `trial_cases` **scoped to cases they're assigned to**.

Scope must be a first-class dimension in the matrix.

## Proposed grouping (extending Casper's seed)

Casper's groupings make sense; here's the full proposed map:

| Group | Entities | Personas with primary edit access |
| --- | --- | --- |
| **Roster** | teams, players, people, custom field values, bulk import, player compare | Academy admin, HoD, Team manager (per-team) |
| **Performance** | evaluations, sessions, goals, attendance | Head coach, Assistant coach, HoD |
| **Methodology** | principles, formations, positions, set pieces, learning goals | HoD, Academy admin |
| **Reference** | custom field definitions, evaluation categories, category weights, all configuration lookups (positions / age groups / foot options / goal statuses / goal priorities / attendance statuses / rating scales / eval types) | Academy admin |
| **Authorization** | roles, role-permission matrix, functional role types, functional role assignments, invitations, permission debug (rename to authorization check) | Academy admin |
| **Analytics** | reports (renamed from "reports"), rate cards, usage statistics | HoD, Scout (read), Academy admin |
| **System** | branding, module toggles, license/account, demo data + mode, backup, migrations, setup wizard, Spond config | Academy admin |
| **Player-self** | my profile, my card, my evals, my goals, my sessions, my team | Player, Parent (scoped to child) |
| **Documentation** | help & docs viewer (and edit when #0029 ships its admin-side doc-source workflow) | Everyone reads; HoD + Academy admin edit |
| **Audit** | audit log viewer (#0021 when it ships), authorization check | Academy admin |

This grouping is also the proposed dashboard tile-group order for personas with broad access (e.g., Academy admin sees Roster, Performance, Methodology, Analytics, Reference, System).

## Tile/menu naming per persona

The same tile may need different labels per persona:

| Tile slug | Player label | Parent label | Coach label | HoD label | Academy admin label |
| --- | --- | --- | --- | --- | --- |
| `players` | (hidden) | "My child(ren)" | "My team's players" | "Players" | "Players" |
| `evaluations` | "My evaluations" (read-only) | "{Child}'s evaluations" | "Evaluations" | "Evaluations" | "Evaluations" |
| `goals` | "My goals" | "{Child}'s goals" | "Goals" | "Goals" | "Goals" |
| `sessions` | "My sessions" | "{Child}'s sessions" | "Sessions" | "Sessions" | "Sessions" |
| `teams` | (hidden) | "{Child}'s team" | "My teams" | "Teams" | "Teams" |
| `methodology` | (hidden or read-only) | (hidden) | "Methodology" (read) | "Methodology" | "Methodology" |
| `reports` | (hidden) | (hidden) | "Reports" (scoped) | "Analytics" | "Analytics" |

The current model has `Me` / `Coaching` / `Analytics` / `Administration` tile groups with hardcoded labels. The persona matrix should drive both **which tiles appear** and **what label they carry**.

## Architectural decisions

### Locked (2026-04-26)

1. **Matrix storage** — new `tt_authorization_matrix` table (one row per persona × activity × entity × scope), seeded from a shipped PHP config, with a "reset to defaults" button in the admin UI. WP capabilities become a thin compatibility layer that reads from the table.

2. **Scope model** — `tt_user_role_scopes` stays as-is for "where does this persona apply at runtime" (global / team / player). The matrix row carries the scope *capability* ("can act at team level"). They answer different questions; both stay.

3. **Multi-persona resolution** — union (most permissive wins per entity/activity) with an explicit "active persona" toggle in the UI ("you are viewing as Parent"). No intersection logic.

4. **Tile/menu rendering** — centralised into a new `TileRegistry` keyed by entity, asking the matrix once per request whether the current persona can read each entity, rendering only those tiles. Replaces the per-dispatcher cap checks scattered through views.

### Still open (defer to spec-time shaping)

5. **Backwards compat with `tt_coach` / `tt_head_dev` / etc.**: existing WP roles continue to exist; the matrix layer sits on top, computing capabilities at runtime via the `user_has_cap` filter. Preserves any third-party code that checks `current_user_can('tt_edit_evaluations')` directly.

6. **Functional Role + persona interaction**: a person assigned as Head Coach via Functional Role gets the Head Coach persona's profile, scoped to that team — assignment auto-elevates within scope. Removing the assignment removes the persona within that scope.

7. **The matrix admin UI**: under Configuration → Authorization (new tab), persona-by-entity grid + scope picker, editable only by `administrator` (WP) — `tt_edit_settings` isn't strict enough for "redefine what every role can do".

8. **Rollout / migration**: walk every user's WP role + scope rows, derive their persona set, leave the rest untouched. Migration preview ("what changes for each existing user") ships alongside the migration so admins can verify nothing they expected to keep gets revoked.

## Open questions still to resolve

- **Does a user pick an "active persona"** (like switching identities), or does the UI always show the union? Switcher is more honest about which lens you're using; union is one less click.
- **Multi-club / multi-tenant**: when #0011 monetization brings multi-site licensing, does the matrix become per-site or stay per-install? (Probably per-install for v1.)
- **Per-team customization of personas**: can a team's HoD restrict what Head Coach means *for that team*? (Probably no — the matrix is club-wide; per-team-flavour belongs in v2.)
- **Default profile content**: the seed matrix needs careful authoring — what *exactly* does each persona see and do? This is non-trivial; ~half a day of design conversation.
- **Print / PDF authority**: does Player can-print own profile? Parent can-print child? HoD can-print any? Currently identical to "view".
- **Scout role specifics**: when #0017 ships, scouts need scoped edit on trial cases. What does "scout" mean *outside* trial-case context? (Probably read-only across all teams + write on assigned trial cases.)
- **Team manager role**: doesn't exist today. What's the canonical capability set? Per-team admin (schedule, attendance, parent comms) but not coaching (no eval/goal edits)?
- **Migration preview**: before applying the new matrix, run a "what changes for each existing user" report so admins can verify nothing they expected to keep gets revoked.

## Out of scope (for v1 of this epic)

- **Per-team customization of personas** (each team can override the club-wide matrix).
- **Time-bounded permissions** (this user has Head Coach access until end of season).
- **Approval workflows** (HoD must approve coach's evaluation before it's published).
- **The `cohort` / `season` / `age-group` scope kinds**.
- **Guardian-of-multiple-children roll-up views** (one parent → many children; needs UX work).
- **External-auditor temporary access** (a board reviewer gets read-only for 7 days). Could ride on the time-bounded extension once it ships.
- **Federated identity** (Google / Apple SSO).

## Sizing

A real epic. Decomposition guess:

| Sprint | Focus | Hours |
| --- | --- | --- |
| 1 | Schema for `tt_authorization_matrix` + matrix repository + read path (`MatrixGate::can(persona, entity, activity, scope)`) | 8-12h |
| 2 | Migration: walk existing users → infer personas + scopes; legacy `user_has_cap` filter wired against the matrix | 6-10h |
| 3 | Admin matrix UI: persona × entity grid editor with scope picker, default-seed reset button | 10-14h |
| 4 | Tile/menu rendering refactor: `TileRegistry::tilesForUser()` + persona-aware labels | 8-12h |
| 5 | New roles: `tt_team_manager`, refined `tt_scout`, head/assistant Functional-Role flag | 4-6h |
| 6 | Audit + migration-preview report ("what changes for each existing user") | 4-6h |
| 7 | Testing across all 8 personas with multi-persona users; docs (`docs/authorization.md` rewrite) + nl_NL.po | 6-10h |
| **Total** | | **~46-70 hours** |

Each sprint is an independently mergeable PR. Ship-along discipline (.po + docs) per project rules. SEQUENCE.md updated per sprint.

## Touches

Almost every module — this is the kind of epic that ripples broadly because every existing cap check eventually moves to the new gate. Critical files:

New:
- `database/migrations/<NN>-add-authorization-matrix.sql` + seed
- `src/Modules/Authorization/MatrixGate.php` (replaces ad-hoc `current_user_can` calls in dispatchers)
- `src/Modules/Authorization/PersonaResolver.php` (user → persona set)
- `src/Modules/Authorization/Admin/MatrixEditorPage.php` (Configuration → Authorization tab)
- `src/Modules/Authorization/MigrationPreview.php`
- `src/Shared/Frontend/TileRegistry.php` (replaces hardcoded dispatchCoachingView etc.)

Existing — every dispatcher in `DashboardShortcode`, every admin page's cap check, the menu builder in `Shared\Admin\Menu.php`, every module's REST controller's permission_callback.

Documentation: full rewrite of `docs/access-control.md` + nl_NL counterpart; new `docs/authorization-matrix.md` + nl_NL.

## Sequence position

After #0032 (invitations — introduces `tt_parent` role into the existing matrix). Probably before #0017 trial player module since #0017 needs the new scout role definition. Could ship in parallel with #0011 monetization since `LicenseGate` becomes a clean caller of `MatrixGate`.

## Notes / questions to think about before shaping

- Worth doing a half-day persona-mapping workshop with a real club (one HoD + one parent + one team manager) before hardening the default profiles. The risk of guessing wrong is high.
- The matrix editor UI is the part most likely to grow. Keep v1 simple: a grid of checkboxes; advanced scope rules deferred.
- The migration preview is the safety net. Without it, a shipped matrix that revokes something users expected silently will be noticed only when they hit an unexpected 403.
