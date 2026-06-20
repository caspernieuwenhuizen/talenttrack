<!-- audience: admin -->

# Modules (admin guide)

**TalentTrack → Access Control → Modules**

Each TalentTrack module can be turned off here. Disabled modules don't `register()` or `boot()` — their tiles, REST routes, admin pages, and capabilities all silently disappear until re-enabled. The toggle is per-install, so a multi-tenant deployment would need a separate per-tenant flag (deferred to v2 of #0011).

## Frontend access (v4.21.15+)

The same toggle is reachable from the frontend admin surface at **`?tt_view=modules`** (and via a **Modules** tile under Configuration), gated by the `tt_manage_modules` capability (administrator + academy admin by default) instead of a raw admin-only check. It's also exposed over REST for non-WordPress front ends: `GET /wp-json/talenttrack/v1/modules` lists modules; `POST` with `{ "class": "...", "enabled": true|false }` toggles one. The wp-admin page stays as the power-user fallback.

## Why turn a module off?

- **Demo to a non-paying prospect.** Disable License so the upgrade banner stays out of the way.
- **Pre-launch dev.** Disable Backup until the cron job is configured on the host.
- **Per-club product surface.** A youth club doesn't run a Methodology, so the Methodology tab clutters their setup.
- **Feature debug.** A new admin needs the Workflow tab disabled while they figure out the rest of the product.

## What the toggle actually does

When a module is disabled, **on the next page load**:

- `Kernel::loadModules()` skips the class entirely — `register()` + `boot()` never run.
- Hooks, REST routes, capability declarations, scheduled events the module owns — all silently absent.
- **Frontend dashboard tiles** the module owns disappear from the user's tile grid.
- **wp-admin sidebar entries** the module owns disappear from the menu, and their direct URLs stop resolving.
- **wp-admin dashboard tiles + stat cards** for the module's entity hide.
- A user who lands on `?tt_view=<slug>` for a disabled module's surface (bookmarked link, stale tab) sees a friendly "this section is currently unavailable" notice with a back button — not a 404 or fatal.
- `MatrixGate::can()` short-circuits any matrix row whose `module_class` is the disabled module — even if a persona has the permission, the entity is unreachable. One auth check, no parallel "is this on?" branch.
- Existing data rows in the module's tables are **untouched** — turning the module back on later restores access to all historical data.

## Always-on modules

Three modules cannot be disabled. Their toggle renders inert with a tooltip:

| Module | Why |
| - | - |
| `Auth` | Login + logout. The product is unreachable without it. |
| `Configuration` | The settings table + lookups. Most other modules read from `tt_config`. |
| `Authorization` | The matrix itself. Disabling it would lock everyone out of the toggle. |

## License module — special case

The License module's toggle ships **enabled by default** + with an inline warning when disabled:

> ⚠️ **Don't forget to implement the gate before going live.**
> Disabling License removes all monetization checks (`LicenseGate::*`).
> Pre-launch this is fine for demos and dev. Before public launch,
> either hardcode `LicenseModule` enabled or implement a `TT_DEV_MODE`
> constant that disables this toggle in production.

The warning is intentional. Right now (pre-monetization-launch) the runtime toggle is the easy path; once the product is live, the toggle becomes a hard gate that needs constant-driven enforcement so a malicious admin can't switch it off to escape billing.

## Dependencies between modules

**Not yet enforced.** Disabling a module that another module depends on may break the dependent silently. Examples:

- `WorkflowModule` builds task templates that reference `EvaluationsModule` entities. Disabling Evaluations leaves Workflow templates pointing at nothing — they no-op gracefully but render confusingly.
- `InvitationsModule` writes to `tt_player_parents` (introduced by #0032). Disabling Players leaves the pivot referencing dead foreign keys.

A dependency graph + warning UI is on the v2 roadmap for the Modules surface.

## Audit

Every module-state change writes a row to `tt_module_state` with the `updated_by` user id and timestamp. Until #0021 ships and the audit log viewer surfaces this, the row is the only trail.

## Features (toggles within a module)

Some modules own several distinct surfaces. A **feature flag** switches one of them off while the rest of the module — and its sibling surfaces — keep running. This is finer-grained than the module toggle: disabling the whole module would take down surfaces you want to keep.

### Per-module feature toggles (`?tt_view=modules`, v4.23.0+)

On the frontend Modules page each feature appears as an indented row (↳) directly beneath its parent module, with its own On/Off switch. A feature only shows while its parent module is on. Two features ship **off by default**:

- **Cohort transitions** (Journey module, default **off**) — the academy-wide "find players by journey event + date range" query (`?tt_view=cohort-transitions`). Turning it off hides its tile, its page, and its REST route (`/journey/cohort-transitions`). The rest of Journey — player timeline, injuries, safeguarding notes — stays fully available.
- **Team chemistry** (Team Development module, default **off**) — the formation board with suggested XI and chemistry scoring (`?tt_view=team-chemistry`). Turning it off hides its tile, its page, and the chemistry/pairings/team-fit REST routes. The **Team blueprint** editor — which lives in the same module and shares the same capability — stays available.

What an off feature does, on the next page load:

- Its **tile** disappears from the dashboard (sibling tiles in the same module stay).
- A user who lands on the feature's `?tt_view=<slug>` (bookmark, stale tab) sees the same friendly "this section is currently unavailable" notice as a disabled module.
- `MatrixGate` denies the feature's own matrix entity at every scope — the cap is unreachable even for a persona that holds it — without touching entities shared with sibling surfaces.
- The feature's **REST routes** return 401/403; routes that back sibling surfaces keep serving.
- Existing data rows are **untouched** — re-enabling restores access to all history.

State lives in `tt_feature_state` (carrying the `club_id` tenancy scaffold), with `updated_by` + timestamp for audit. It's exposed over REST for non-WordPress front ends: `GET /wp-json/talenttrack/v1/features` lists features; `POST` with `{ "key": "...", "enabled": true|false }` toggles one (both gated by `tt_manage_modules`).

### Analytics explorer

- **Analytics explorer** (default **off**, managed on the **wp-admin** Modules page) — the ad-hoc Analytics dashboard tile and dimension/KPI explorer (`?tt_view=analytics`, `explore`, `scheduled-reports`). Turning it off hides the tile and those pages, but the **analytics engine keeps running** — the attendance, minutes and standard reports plus dashboard KPIs all still work, because they consume the engine directly, not the explorer UI. As of v4.26.9 the toggle also hides every inline **Explore →** affordance (player detail, team detail, standard reports, the reports launcher's prospects-per-scout tile), so switching Explorer off leaves no dangling links into a disabled feature. The activity detail page no longer carries an Explorer preset row at all.

## Read-only status for everyone (`?tt_view=features`, v4.23.1+)

The Modules page is admin-only (it's a write surface). For transparency, every user — coach, player, parent — gets a read-only **Features** view at **`?tt_view=features`**, reachable from a **Features** tile under the **About** group on the dashboard. It needs no special capability.

It lists each user-facing module with an **On / Off / Always on** badge, a one-line "Provides:" summary (built from the surfaces the module owns), and any sub-feature toggles nested beneath it with their own badge + description. There are no controls — it's a snapshot of what's live. Users who *can* manage modules see a **Manage modules & features** link that jumps to the editable page.

The same data is available over REST at `GET /wp-json/talenttrack/v1/feature-status` (any logged-in user). All the shaping lives in `FeatureStatusService`, so the view and the API return the same answer. Only modules that actually present something to a user (own a tile or a feature) appear — pure-infrastructure modules are omitted.

## See also

- [Authorization matrix](authorization-matrix.md) — module disable feeds into the matrix gate.
- [Access control](access-control.md) — the broader role + capability model.
