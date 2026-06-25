<!-- audience: admin -->

# Modules (admin guide)

**TalentTrack → Access Control → Modules**

Each TalentTrack module can be turned off here. Disabled modules don't `register()` or `boot()` — their tiles, REST routes, admin pages, and capabilities all silently disappear until re-enabled. The toggle is per-install, so a multi-tenant deployment would need a separate per-tenant flag (deferred to v2 of #0011).

## Frontend access (v4.21.15+)

The same toggle is reachable from the frontend admin surface at **`?tt_view=modules`** (and via a **Modules** tile under Configuration), gated by the `tt_manage_modules` capability (administrator + academy admin by default) instead of a raw admin-only check. It's also exposed over REST for non-WordPress front ends: `GET /wp-json/talenttrack/v1/modules` lists modules; `POST` with `{ "class": "...", "enabled": true|false }` toggles one. The wp-admin page stays as the power-user fallback.

## Card layout (v4.29.0+)

The frontend Modules page presents modules as **cards grouped by category** rather than a flat list. Each card shows an icon, a human label and a one-line description, plus a status pill — **Core** (grey, cannot be switched off), **On** (green) or **Off** (muted) — and a **Module** type tag. The switch on the right enables or disables the module; core modules render with the switch locked. The confirm dialog ("reload open tabs after saving") and the underlying REST contracts are unchanged.

Categories, in order: **Player data**, **Coaching & development**, **Planning & match day**, **Communication**, **Analytics & reporting**, **Integrations**, **Administration** (which holds the three always-on core modules) and **Advanced / developer**. The label, description, icon and category for every module live in one place — `TT\Shared\Modules\ModuleMetadata` — so no raw class name is ever shown to a user.

Where a module owns sub-features, the card carries a feature count (e.g. "2 features") and an expandable panel. Each feature sits inside its parent card with its own **Feature** pill (visually distinct from the Module tag), its description and its own switch. Features only appear while their parent module is on. The page is mobile-first: cards stack to one column on a phone and the switches meet the 48px touch target.

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

On the frontend Modules page each feature appears as an indented row (↳) directly beneath its parent module, with its own On/Off switch. A feature only shows while its parent module is on. The features that ship **off by default**:

- **Cohort transitions** (Journey module, default **off**) — the academy-wide "find players by journey event + date range" query (`?tt_view=cohort-transitions`). Turning it off hides its tile, its page, and its REST route (`/journey/cohort-transitions`). The rest of Journey — player timeline, injuries, safeguarding notes — stays fully available.
- **Team chemistry** (Team Development module, default **off**) — the formation board with suggested XI and chemistry scoring (`?tt_view=team-chemistry`). Turning it off hides its tile, its page, and the chemistry/pairings/team-fit REST routes. The **Team blueprint** editor — which lives in the same module and shares the same capability — stays available.
- **Analytics explorer** (Analytics module, default **off**) — the ad-hoc explorer for KPI and dimension queries (`?tt_view=analytics`, `explore`, `scheduled-reports`). See the section below for what stays running when it's off. (v4.30.0+ this is a `FeatureRegistry` feature, managed on the same frontend Modules page alongside the others, not only on the wp-admin page.)
- **Custom widgets** (Custom widgets module, default **off**) — the beta builder for bespoke dashboard widgets. Turning it off skips the whole module boot — no admin page, no REST routes, no editor palette tile — exactly as the old `tt_custom_widgets_enabled` option did. (v4.30.0+ this is a `FeatureRegistry` feature; the prior option value is carried forward on upgrade so nothing changes.)

The features that ship **on by default** (they run today; turning them off is an opt-out, so academies that want them keep them with no action):

- **Photo exercise extraction** (Exercises module, default **on**) — the photo→exercise AI extraction (`POST /vision/extract`) and its capture UI. Turning it off makes the extraction REST route return 403; the exercise library CRUD is unaffected.
- **Blueprint share links** (Team Development module, default **on**) — public read-only share links for team blueprints (`?tt_view=team-blueprint-share`) and the share-URL generate/rotate controls. Turning it off hides the share actions in the blueprint editor, makes the public share URL show the "not valid" notice, and refuses the rotate action; blueprint editing is unaffected.
- **Onboarding pipeline workflow** (Workflow module, default **on**) — the automatic tasks that move prospects through the recruitment funnel (log prospect → invite → test training → trial review → team offer). Turning it off stops those six templates from dispatching new tasks; the onboarding pipeline view and any existing tasks stay visible, and every other workflow template keeps running. This is the switch that lets an academy run "workflow only for onboarding" — leave this on and disable the other templates in the workflow template config.
- **SMS channel** (Comms module, default **on**) — offers SMS as a messaging channel (it still needs a provider plugin to actually deliver). Turning it off removes the SMS channel adapter so messages can't be sent over SMS; email, push, WhatsApp-link and in-app channels keep working.
- **Scheduled messaging** (Comms module, default **on**) — the daily cron that fires goal nudges, attendance flags, onboarding nudges and staff-development reminders. Turning it off stops the scheduled cron from registering; event-driven messages (the other templates) keep firing from their owning modules.
- **Team planner calendar** (Planning module, default **on**) — the week-by-week team planner board (`?tt_view=team-planner`). Turning it off hides the planner tile and shows the unavailable notice on its route; creating and editing activities is unaffected.
- **Medical events on timeline** (Journey module, default **on**) — shows injury and medical events on the player timeline to staff who already hold the medical-view permission. Turning it off hides medical events from the timeline even for authorised staff (an academy-wide privacy brake); the permission itself is unchanged.
- **PDP calendar integration** (PDP module, default **on**) — writes scheduled PDP conversations to the calendar feed when a development plan is created or carried over. Turning it off skips the calendar write; PDP plans, conversations and verdicts are unaffected.
- **Dashboard layout editor** (Persona Dashboard module, default **on**) — the drag-and-drop builder for persona dashboard layouts. Turning it off hides the editor menu entry, its Configuration tile and the editor page itself; the rendered dashboards keep working from their saved layouts.
- **Match prep PDF export** (Match Prep module, default **on**) — the A4 match-preparation sheet's print / export-to-PDF actions. Turning it off hides the Print / export buttons and refuses both the client print route and the server-side DomPDF export; the on-screen match-prep editor is unaffected.

What an off feature does, on the next page load:

- Its **tile** disappears from the dashboard (sibling tiles in the same module stay).
- A user who lands on the feature's `?tt_view=<slug>` (bookmark, stale tab) sees the same friendly "this section is currently unavailable" notice as a disabled module.
- `MatrixGate` denies the feature's own matrix entity at every scope — the cap is unreachable even for a persona that holds it — without touching entities shared with sibling surfaces.
- The feature's **REST routes** return 401/403; routes that back sibling surfaces keep serving.
- Existing data rows are **untouched** — re-enabling restores access to all history.

State lives in `tt_feature_state` (carrying the `club_id` tenancy scaffold), with `updated_by` + timestamp for audit. It's exposed over REST for non-WordPress front ends: `GET /wp-json/talenttrack/v1/features` lists features; `POST` with `{ "key": "...", "enabled": true|false }` toggles one (both gated by `tt_manage_modules`).

### Analytics explorer

- **Analytics explorer** (default **off**) — the ad-hoc Analytics dashboard tile and dimension/KPI explorer (`?tt_view=analytics`, `explore`, `scheduled-reports`). As of v4.30.0 this is a `FeatureRegistry` feature, managed on the frontend Modules page next to the others (the wp-admin Modules page still works too; both write the same `tt_feature_state` row). Turning it off hides the tile and those pages, but the **analytics engine keeps running** — the attendance, minutes and standard reports plus dashboard KPIs all still work, because they consume the engine directly, not the explorer UI. As of v4.26.9 the toggle also hides every inline **Explore →** affordance (player detail, team detail, standard reports, the reports launcher's prospects-per-scout tile), so switching Explorer off leaves no dangling links into a disabled feature. The activity detail page no longer carries an Explorer preset row at all.

## Read-only status for everyone (`?tt_view=features`, v4.23.1+)

The Modules page is admin-only (it's a write surface). For transparency, every user — coach, player, parent — gets a read-only **Features** view at **`?tt_view=features`**, reachable from a **Features** tile under the **About** group on the dashboard. It needs no special capability.

It lists each user-facing module with an **On / Off / Always on** badge, a one-line "Provides:" summary (built from the surfaces the module owns), and any sub-feature toggles nested beneath it with their own badge + description. There are no controls — it's a snapshot of what's live. Users who *can* manage modules see a **Manage modules & features** link that jumps to the editable page.

The same data is available over REST at `GET /wp-json/talenttrack/v1/feature-status` (any logged-in user). All the shaping lives in `FeatureStatusService`, so the view and the API return the same answer. Only modules that actually present something to a user (own a tile or a feature) appear — pure-infrastructure modules are omitted.

## See also

- [Authorization matrix](authorization-matrix.md) — module disable feeds into the matrix gate.
- [Access control](access-control.md) — the broader role + capability model.
