---
title: Modules
group: frontend
summary: Per-install module toggles — disable Methodology, Workflow, License, etc. without touching code.
audience: [admin]
views: [modules, features, install-profile]
order: 80
---

# Modules (admin guide)

**TalentTrack → Access Control → Modules**

Each TalentTrack module can be turned off here. Disabled modules don't `register()` or `boot()` — their tiles, REST routes, admin pages, and capabilities all silently disappear until re-enabled. The toggle is per-install, so a multi-tenant deployment would need a separate per-tenant flag (deferred to v2 of #0011).

## Frontend access

The same toggle is reachable from the frontend admin surface at **`?tt_view=modules`** (and via a **Modules** tile under Configuration), gated by the `tt_manage_modules` capability (administrator + academy admin by default) instead of a raw admin-only check. It's also exposed over REST for non-WordPress front ends: `GET /wp-json/talenttrack/v1/modules` lists modules; `POST` with `{ "class": "...", "enabled": true|false }` toggles one. The wp-admin page stays as the power-user fallback.

## Card layout

The frontend Modules page presents modules as **cards grouped by category** rather than a flat list. Each card shows an icon, a human label and a one-line description, plus a status pill — **Core** (grey, cannot be switched off), **On** (green) or **Off** (muted) — and a **Module** type tag. The switch on the right enables or disables the module; core modules render with the switch locked. The confirm dialog ("reload open tabs after saving") and the underlying REST contracts are unchanged.

Categories, in order: **Player data**, **Coaching & development**, **Planning & match day**, **Communication**, **Analytics & reporting**, **Integrations**, **Administration** (which holds the three always-on core modules) and **Advanced / developer**. The label, description, icon and category for every module live in one place — `TT\Shared\Modules\ModuleMetadata` — so no raw class name is ever shown to a user.

Where a module owns more than four sub-features, the card carries a feature count (e.g. "21 features") and an expandable panel; four or fewer are listed directly. Reports owns twenty-one, and stacking those unexpanded buried the modules beside them. Each feature sits inside its parent card with its own **Feature** pill (visually distinct from the Module tag), its description and its own switch. Features only appear while their parent module is on. The page is mobile-first: cards stack to one column on a phone and the switches meet the 48px touch target.

A **search box** at the top of the frontend page (`?tt_view=modules`, v4.x+) filters the list live as you type — matching a module or feature by its name or description. When a match is a nested feature, its module card auto-expands so the row is visible; categories with no remaining matches drop out, and an empty-state line shows when nothing matches. It's a client-side filter (no reload), and with JavaScript off the full list simply renders unfiltered. The wp-admin Modules page has no search — the frontend page is the surface being carried forward.

## Marking a module or feature "under development" (v4.x+)

**A whole module** can be flagged from its card header, using the **Under development** checkbox under the module's on/off switch. Flagging a module marks everything it owns: every view it owns shows the pill, and every dashboard tile leading into it shows a small **Under development** badge — so the marker is visible *before* someone clicks in, not only once they're inside. A core (always-on) module can be flagged too: the flag gates nothing, so there's no reason to exempt it.

**A single feature** can be flagged the same way from its row inside the module card.

The badge appears on a dashboard tile whenever the tile's own feature is flagged **or** its module is, so both levels behave identically from the user's side. Tiles on the persona dashboard, the classic tile grid, the "My work" rail and a parent's child tiles all carry it.

Each feature row carries a second control next to its on/off switch: an **Under development** checkbox. Tick it and every view that feature owns shows a small amber **Under development** pill at the top, so anyone using the surface — coaches, players and parents alike — knows it's still being built and may change. The pill is purely informational: it never disables or hides anything, and the feature keeps working exactly as before. It's independent of the on/off switch, so a feature can be live *and* flagged, or you can turn the flag off again without touching whether the feature is enabled. Only admins who can manage modules (`tt_manage_modules`) see or change the flag; the pill itself is visible to every user of the flagged surface. The flag is also readable and settable through the `/talenttrack/v1/features` REST endpoint.

## Install profiles

Deciding module by module is the fine-grained control. An **install profile** is the coarse one: a named shape for the whole install, so a club can say "we run the development loop" once instead of making fifty separate decisions on their first afternoon.

Two profiles ship:

| Profile | What it is |
| - | - |
| **Basics** | The development loop and the surfaces that feed it — players, teams, people, evaluations, goals, activities, measurements, the journey, and the reports and exports that read them back. Match day, training plans, the knowledge library, the integrations and the developer surfaces stay off. |
| **Full academy** | Everything the plugin ships, at its default settings. This is what an install gets when no profile is chosen, so it is also the way back. |

Two things about Basics are worth knowing because they look like mistakes and are not. **Analytics stays on** — the reports and the dashboard figures read the analytics engine directly, and only the separate Analytics explorer surface is switched off. **Communication stays on** — it is what invitations and account mail travel over; only its two cost-bearing extras (scheduled sends and the SMS channel) are switched off.

A profile is an association, not a copy. The install remembers which profile it is on, and how far it has drifted from it — a count of the modules and features that no longer match. Drift is worked out fresh every time it is shown, so switching something back into line clears it immediately, with nothing to reset.

Choosing a profile never overrules your plan. A module or feature that is not part of what this install is entitled to is reported as skipped, with the reason, rather than being switched on and failing later.

Applying a profile changes only which surfaces are switched on. **No data is deleted, ever.** A module switched off by a profile keeps every row it owns, and switching it back on restores access to all of it.

### Where you see it, and how you change it

At the top of the Modules page there is a strip reading **Install profile** and, under it, either the profile you are on with the number of changes since — *Basics · 3 changes since* — or **Not on a profile** for an install that predates them. Beside it, a chooser and a **Review changes** button.

Review changes opens the preview, which is the **only** screen in the product that applies a profile. It lists what would happen in three groups:

1. **Will be switched on**
2. **Will be switched off**
3. **Cannot be applied** — anything above what your plan includes, with the reason. These are read-only text rather than an unticked box, because they are not a choice you have.

Everything in the first two groups is ticked. Untick anything you would rather keep as it is and it is left alone; the confirmation afterwards says how many changes were made. Nothing is written until you press **Apply** — opening the preview and navigating away changes nothing at all. **Cancel** returns you to the Modules page, and if you arrived from somewhere else it returns you there instead.

A profile that would change nothing shows a short "already matches" line and no Apply button.

### When a release changes what your profile includes

A profile is a living association, not a copy taken once. Every new module ships switched on, so without this an academy deliberately put on Basics would quietly re-accumulate surfaces it never asked for, one release at a time.

So when a release changes what your profile covers, the strip on the Modules page says so — *"Basics now covers Training plans. Nothing has changed yet."* — with two buttons:

- **Review** opens the preview showing **only** those changes, where you apply them the same way as any others.
- **Dismiss** records that you have seen them and decided against, and the notice stops. It does not come back on the next unrelated release. If a later release changes its mind about the same thing again, it is raised again — that is a new decision, not the same one repeated.

Nothing is ever applied automatically. A release happens with nobody watching, so a release is exactly the wrong moment for something to be switched on unasked.

The notice can tell the two kinds of difference apart: a module *you* switched off is your decision and is never reported as a profile change, and a module the *profile* newly includes is never reported as something you did. An install on Full academy, or on no profile, sees no notice at all.

*Audience: developers.* The profiles themselves live in `config/profiles.php` and are not editable at runtime — changing what Basics means is a release, for the same reason the plan map is. A profile states its modules in full (every class in `config/modules.php`, so a module added in a release has to be placed rather than arriving on by omission) and its features as overrides only (the catalog builds the export and report keys from loops, so enumerating them would go stale the day a report is added). `TT\Shared\Modules\ProfileRegistry` reads the file; `TT\Shared\Modules\ProfileService` compares it against live state (`diff()`, `divergence()`) and applies it (`apply()`) through `ModuleRegistry` and `FeatureRegistry`, so every write carries the same audit trail a hand-thrown switch does. `tools/check-module-toggles.php` fails the build when a profile names something that does not resolve, misses a switchable module, or tries to disable an always-on one.

Drift detection hangs off a **confirmation watermark** stored beside the profile slug in `tt_config`: for every row the operator was last shown, the intent the profile had for it at that moment. Divergence alone cannot separate "the operator turned this off" from "the profile turned this on" — both read as "the install does not match" — and the watermark is the only thing that can. A diff row absent from the watermark, or present with a different intent, is a profile change; one present with the same intent is the operator's own divergence. `apply()` writes the watermark from the diff it showed (applied and excluded rows alike — the recorded fact is "seen and decided", not "agreed"); `dismiss()` adds rows to it carrying the profile's current intent. A watermark written for a different profile than the install is on is not interpreted at all, and raises nothing. Detection is a comparison on page load — **no cron, no scheduled event**, and a test asserts the profile surfaces register none.

## Why turn a module off?

- **Demo to a non-paying prospect.** Disable License so the upgrade banner stays out of the way.
- **Pre-launch dev.** Disable Backup until the cron job is configured on the host.
- **Per-club product surface.** A youth club doesn't run a Methodology, so the Methodology tab clutters their setup.
- **Feature debug.** A new admin needs the Workflow tab disabled while they figure out the rest of the product.
- **Trim the player dashboard.** The Players module owns a feature per player tile — My journey, My team, My evaluations, My activities, My goals, My PDP. Switch any of them off (they ship on) to hide that tile *and* block its `?tt_view` URL for players in this academy. The player profile is the always-on anchor and has no toggle.
- **Curate the reports.** The Reports module owns a feature per report (15 in all — the eight standard reports, the two wp-admin reports, the three attendance reports, minutes-played-per-team and rate cards). Switch any off (they ship on) to hide that report's launcher tile *and* reject a direct link to it, exactly like the Export module's per-tile toggles.

## What the toggle actually does

When a module is disabled, **on the next page load**:

- `Kernel::loadModules()` skips the class entirely — `register()` + `boot()` never run.
- Hooks, REST routes, capability declarations, scheduled events the module owns — all silently absent.
- **Frontend dashboard tiles** the module owns disappear from the user's tile grid.
- **wp-admin sidebar entries** the module owns disappear from the menu, and their direct URLs stop resolving.
- **wp-admin dashboard tiles + stat cards** for the module's entity hide.
- A user who lands on `?tt_view=<slug>` for a disabled module's surface (bookmarked link, stale tab) sees a friendly "this section is currently unavailable" notice with a back button — not a 404 or fatal.
- `MatrixGate::can()` short-circuits any matrix row whose `module_class` is the disabled module — even if a persona has the permission, the entity is unreachable. One auth check, no parallel "is this on?" branch.
- **Help topics** for the module disappear from Help & Docs — the sidebar, the search box, the help drawer, and direct topic URLs alike. See below.
- Existing data rows in the module's tables are **untouched** — turning the module back on later restores access to all historical data.

## Help topics follow the switches

A help topic describes a feature. When the install cannot run that feature,
the topic is not a preview of what you are missing — it is a set of
instructions for a screen that is not there. So the documentation reads the
same four switches everything else does:

| Front-matter key | Hidden when |
| --- | --- |
| `module:` | the module is off |
| `feature:` | the feature toggle is off |
| `tier:` | your licence is below the tier the topic names |
| `capability:` | you personally lack that capability |

A topic that names none of these is never hidden by this — most are, and
they behave exactly as they always did.

**Hiding is complete, not cosmetic.** A hidden topic is absent from the
table of contents, from the search box, from the help drawer, and from its
own URL. There is no "upgrade to read this" teaser: an academy on Free does
not see Pro documentation at all. If you want to know what a higher tier
would give you, that belongs on the licence page, not in the help index.

**It is reversible and immediate.** Turning a module back on restores its
topics on the next page load. Nothing is cached across the toggle and
nothing is deleted.

**A typo fails open.** A topic naming a module or feature that does not
exist stays visible rather than vanishing — a doc that silently disappears
on someone else's install is the harder bug to find. The docs lint is what
catches the typo before it ships.

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

### Switchability and plan are two different axes

There are two lists in the codebase that both look like "the features", and confusing them is how each of them goes stale.

| | `Core\FeatureRegistry` | `Modules\License\FeatureMap` |
| - | - | - |
| Answers | *What has this club switched on?* | *What is this club entitled to?* |
| Decided by | the club's own operator, at runtime | the plan the install was provisioned with |
| Changed by | a toggle on the Modules page | a release, or a plan change |
| Lives in | `src/Core/FeatureRegistry.php` | `src/Modules/License/FeatureMap.php` |

A club can switch off something it pays for. A club cannot switch on something it does not have. So the two are checked independently, and **neither derives from the other** — a surface asks `FeatureRegistry::isEnabled()` for "is this club using it" and `LicenseGate::allows()` for "is this club allowed it".

They share some key names where they describe the same surface, which makes them readable side by side. That is a convenience, not a link. Before #2922 they shared exactly one name (`team_chemistry`) and it was coincidence — which is the state to avoid, in both directions: a shared name that means two things, and two names that mean one thing.

Pro features that do not yet have a `LicenseGate` call site are listed explicitly in `config/license_gate_pending.php`, and `FeatureMapGateCoverageTest` fails if a Pro feature is neither gated nor listed. A tier map without gates is a table nobody enforces, and that is precisely how the 2025 map survived twenty modules without anybody noticing.

### Per-module feature toggles (`?tt_view=modules`,)

On the frontend Modules page each feature appears as an indented row (↳) directly beneath its parent module, with its own On/Off switch. A feature only shows while its parent module is on. The features that ship **off by default**:

- **Cohort transitions** (Journey module, default **off**) — the academy-wide "find players by journey event + date range" query (`?tt_view=cohort-transitions`). Turning it off hides its tile, its page, and its REST route (`/journey/cohort-transitions`). The rest of Journey — player timeline, injuries, safeguarding notes — stays fully available.
- **Team chemistry** (Team Development module, default **off**) — the formation board with suggested XI and chemistry scoring (`?tt_view=team-chemistry`). Turning it off hides its tile, its page, and the chemistry/pairings/team-fit REST routes. The **Team blueprint** editor — which lives in the same module and shares the same capability — stays available.
- **Analytics explorer** (Analytics module, default **off**) — the ad-hoc explorer for KPI and dimension queries (`?tt_view=analytics`, `explore`, `scheduled-reports`). See the section below for what stays running when it's off. (v4.30.0+ this is a `FeatureRegistry` feature, managed on the same frontend Modules page alongside the others, not only on the wp-admin page.)
- **Custom widgets** (Custom widgets module, default **off**) — the beta builder for bespoke dashboard widgets. Turning it off skips the whole module boot: no admin page, no REST routes, no editor palette tile. Saved widgets are kept and reappear if you switch it back on.

The features that ship **on by default** (they run today; turning them off is an opt-out, so academies that want them keep them with no action):

- **Photo exercise extraction** (Exercises module, default **on**) — the photo→exercise AI extraction (`POST /vision/extract`) and its capture UI. Turning it off makes the extraction REST route return 403; the exercise library CRUD is unaffected.
- **Blueprint share links** (Team Development module, default **on**) — public read-only share links for team blueprints (`?tt_view=team-blueprint-share`) and the share-URL generate/rotate controls. Turning it off hides the share actions in the blueprint editor, makes the public share URL show the "not valid" notice, and refuses the rotate action; blueprint editing is unaffected.
- **Onboarding pipeline workflow** (Workflow module, default **on**) — the automatic tasks that move prospects through the recruitment funnel (log prospect → invite → test training → trial review → team offer). Turning it off stops those six templates from dispatching new tasks; the onboarding pipeline view and any existing tasks stay visible, and every other workflow template keeps running. This is the switch that lets an academy run "workflow only for onboarding" — leave this on and disable the other templates in the workflow template config.
- **Team planner** (Planning module, default **on**) — the week-by-week team-planning calendar (`?tt_view=team-planner`). Turning it off hides the Team planner tile and its page; the **Activities** log — the backward-looking surface — stays available, so an academy that works activity-by-activity can switch the forward planner off.
- **SMS channel** (Comms module, default **on**) — offers SMS as a messaging channel (it still needs a provider plugin to actually deliver). Turning it off removes the SMS channel adapter so messages can't be sent over SMS; email, push, WhatsApp-link and in-app channels keep working.
- **Scheduled messaging** (Comms module, default **on**) — the daily cron that fires goal nudges, attendance flags, onboarding nudges and staff-development reminders. Turning it off stops the scheduled cron from registering. Event-driven messages are unaffected and keep firing from their owning modules — a cancelled training, an invitation email, a direct message, a scout-report delivery, a trial-input reminder, a scheduled-report delivery. Some of the remaining templates ship registered but not yet connected to a trigger, so they do not send at all; that is independent of this switch, and turning it back on does not start them.
- **Medical events on timeline** (Journey module, default **on**) — shows injury and medical events on the player timeline to staff who already hold the medical-view permission. Turning it off hides medical events from the timeline even for authorised staff (an academy-wide privacy brake); the permission itself is unchanged.
- **PDP calendar integration** (PDP module, default **on**) — writes scheduled PDP conversations to the calendar feed when a development plan is created or carried over. Turning it off skips the calendar write; PDP plans, conversations and verdicts are unaffected.
- **Dashboard layout editor** (Persona Dashboard module, default **on**) — the drag-and-drop builder for persona dashboard layouts. Turning it off hides the editor menu entry, its Configuration tile and the editor page itself; the rendered dashboards keep working from their saved layouts.
- **Match prep PDF export** (Match Prep module, default **on**) — the A4 match-preparation sheet's print / export-to-PDF actions. Turning it off hides the Print / export buttons and refuses both the client print route and the server-side DomPDF export; the on-screen match-prep editor is unaffected.
- **Tournament auto-balance** (Tournaments module, default **on**) — the greedy fair-share auto-planner that fills a match grid by eligibility, equal-share minutes and starts distribution. Turning it off hides the Auto-balance button on every match card and makes the `auto-plan` REST route return 403 so it can't be triggered directly; the per-match planner grid and manual click-to-swap planning are unaffected, so a Head of Development who plans minutes by hand can remove the shortcut without losing the planner.
- **Player comparison** (Stats module, default **on**) — the Player comparison tile and view (`?tt_view=compare`) for comparing up to four players side-by-side. Turning it off hides the tile and blocks a direct link to it; the rest of the Stats module (Podium, Application KPIs) is unaffected.
- **Podium** (Stats module, default **on**) — the Podium tile and view (`?tt_view=podium`) of team rankings and top performers. Turning it off hides the tile and blocks a direct link to it; the rest of the Stats module (Player comparison, Application KPIs) is unaffected.

What an off feature does, on the next page load:

- Its **tile** disappears from the dashboard (sibling tiles in the same module stay).
- A user who lands on the feature's `?tt_view=<slug>` (bookmark, stale tab) sees the same friendly "this section is currently unavailable" notice as a disabled module.
- `MatrixGate` denies the feature's own matrix entity at every scope — the cap is unreachable even for a persona that holds it — without touching entities shared with sibling surfaces.
- The feature's **REST routes** return 401/403; routes that back sibling surfaces keep serving.
- Existing data rows are **untouched** — re-enabling restores access to all history.

State lives in `tt_feature_state` (carrying the `club_id` tenancy scaffold), with `updated_by` + timestamp for audit. It's exposed over REST for non-WordPress front ends: `GET /wp-json/talenttrack/v1/features` lists features; `POST` with `{ "key": "...", "enabled": true|false }` toggles one (both gated by `tt_manage_modules`).

### Analytics explorer

- **Analytics explorer** (default **off**) — the ad-hoc Analytics dashboard tile and dimension/KPI explorer (`?tt_view=analytics`, `explore`, `scheduled-reports`). This is a `FeatureRegistry` feature, managed on the frontend Modules page next to the others (the wp-admin Modules page still works too; both write the same `tt_feature_state` row). Turning it off hides the tile and those pages, but the **analytics engine keeps running** — the attendance, minutes and standard reports plus dashboard KPIs all still work, because they consume the engine directly, not the explorer UI. The toggle also hides every inline **Explore →** affordance (player detail, team detail, standard reports, the reports launcher's prospects-per-scout tile), so switching Explorer off leaves no dangling links into a disabled feature. The activity detail page no longer carries an Explorer preset row at all.

  Switching the explorer **on** does not open it to everyone: the explorer,
  like the Analytics page beside it, needs permission to view central
  analytics — held by Head of Development and Academy Admin. A coach who
  opens its URL is refused.

## The capability catalog for everyone (`?tt_view=features`)

The Modules page is admin-only (it's a write surface). Every user — coach, player, parent — gets a read-only **Features** view at **`?tt_view=features`**, reachable from a **Features** tile under the **About** group on the dashboard. It needs no special capability.

The page opens with a summary of how much of TalentTrack the academy is running ("Your academy is running 14 of 19 TalentTrack capabilities"), then splits into two sections:

- **In use** — what's switched on today.
- **Available to switch on** — part of TalentTrack, not enabled here yet.

Both sections group their cards by category (Player data, Coaching & development, Planning & match day, and so on), and each card carries the module's icon, its written name and a one-line description of what it's for, an **Includes** line naming the screens it adds, and any sub-features nested beneath it with their own On/Off badge.

There are no controls anywhere on the page and no card links into the management page — it's a catalog, not a second write surface. Users who *can* manage modules see a **Manage modules & features** link in the page header that jumps to the editable page.

Three things are deliberately left out:

- **Always-on core** — authentication, configuration and authorization. They can't be switched off, so listing them as capabilities is noise.
- **Advanced / developer modules** — the seed-review and custom-widget tooling. Not academy-facing.
- **Anything under development that isn't switched on.** A module or feature flagged "under development" (see above) while still off is not advertised. One that's flagged *and* already on stays listed under **In use**, carrying its amber pill — its screens are live on the dashboard, so hiding it here would only confuse.

Modules that present nothing to a user (no tile, no feature) never appear, on this page or in the API.

The same catalog is available over REST at `GET /wp-json/talenttrack/v1/feature-catalog` (any logged-in user). The older `GET /wp-json/talenttrack/v1/feature-status` is unchanged and still returns the complete, unfiltered list of modules and features — including always-on and under-development ones — for callers auditing state rather than reading a catalog. All the shaping for both lives in `FeatureStatusService`, so the view and the API return the same answer.

## Switchability — the contract a new module must satisfy

*Audience: developers.* Everything above describes using the toggles. This describes keeping them honest.

The switching mechanism has always worked. What was missing was anything that **fails** when a new module or a new routable surface ships without a toggle — so every part of it was convention, and a convention gets discovered by an academy asking "why can't I turn this off?".

`tools/check-module-toggles.php` runs on every PR that touches the files deciding switchability. Seven assertions:

1. **Every module class on disk is declared in `config/modules.php`.** A module that exists but is not declared never boots, and no operator can switch it on.
2. **Every declared module has a `ModuleMetadata` entry.** Without one the modules page shows a slugified class name where a label belongs. This assertion found five modules missing metadata the day it was written.
3. **Every tile's `?tt_view=` slug has an off-switch.** Three ways to qualify, and only the third needs the manifest:
 1. a `FeatureRegistry` entry claims the slug in its `view_slugs`;
 2. the tile names a `module_class` an academy can switch off — the module toggle already hides it;
 3. it is listed in `config/always_on_surfaces.php` with a reason.
4. **No matrix entity is claimed by two features.** The catalog docblock has always said this MUST hold; nothing checked it, and a duplicate silently gates a sibling surface too.
5. **Every feature's `module_class` resolves to a declared module.** A feature naming a class that is not registered gates nothing, silently.
6. **Every install profile names only modules and features that exist**, names every switchable module, and never tries to disable an always-on one. A typo in a profile is otherwise a row that does nothing at apply time.
7. **Every module-owned `?tt_view=` slug the dashboard dispatcher routes is owned on the unconditional path.** See below — this is the assertion that makes "the module is off" answerable at all.

### Ownership has to survive the module being off

The gate whose entire job is to catch "this module is switched off" spent a long time being a no-op in exactly that state.

`TileRegistry::isViewSlugDisabled()` resolves a slug's owning module from the registered tiles. Tiles are registered inside a module's `register()` / `boot()` — and `ModuleRegistry` skips both for a **disabled** module. So the tile that would prove ownership does not exist precisely when it is needed: ownership resolves to nothing, the gate returns false, and the route dispatches as though the module were on. With Training plans switched off, `?tt_view=training-run` still rendered the sideline view, and the activity page still offered **Execute training**.

The fix is that ownership is declared where it cannot disappear: `CoreSurfaceRegistration`, which `Kernel::register()` calls whether or not any given module boots. Two forms count, both in that file:

- `TileRegistry::registerSlugOwnership( '<slug>', <module> )`, or
- a tile registered there carrying both `view_slug` and `module_class`.

A tile registered from inside `src/Modules/**` does **not** count, for the reason above. Assertion 7 walks the dispatcher's `case '<slug>':` arms, keeps the ones that render a `TT\Modules\…\Frontend\…` view, and requires each to be owned that way — or listed in `config/always_on_surfaces.php` with a reason. Arms that reach a `src/Shared/Frontend` view are skipped: no single module owns those, so nothing should gate them.

The affordance layer asks the same question, in `CrossViewLink::allows()`, before any capability gate and before a caller's own `gate` override. That ordering matters more than it looks: `LegacyCapMapper` lets a WP `administrator` pass every `tt_*` cap unconditionally — the deliberate emergency override for the person running the install — so a capability-shaped check hid the button from a coach and left it in place for the operator who had just switched the module off. "May this user do it?" and "does this surface exist here?" are different questions, and only the second one has anything to say about a switched-off module.

### What this means when you add a module

- Add the class to `config/modules.php`, and to `ModuleRegistry::ALWAYS_ON_MODULES` **only** if the product is genuinely unusable without it — three modules qualify today.
- Add a `ModuleMetadata` entry: a label, a one-line description in the academy's language rather than the codebase's, an icon, a category.
- Add a `FeatureRegistry` entry if the module owns surfaces an academy might not want, and **list each new view slug in the same PR that adds the surface**. That habit is the whole point; the gate is what stops it depending on anyone remembering.
- Declare each of the module's `?tt_view=` slugs in `CoreSurfaceRegistration::registerSlugOwnerships()`. Registering the tile in the module's own `boot()` is not enough — see above.

### When a surface must always be on

First check it is actually un-switchable: **a tile that names its owning module is already covered**, because the module toggle hides it. That is the common case, and naming the module is nearly always the right fix rather than adding anything.

If it genuinely has no off-switch, list it in `config/always_on_surfaces.php` with a sentence saying what breaks if it can be turned off. There are six entries, all real decisions: the settings page, the feature-toggle page itself, migrations and the audit log would each remove the means of recovering; functional roles is how anyone gets a permission at all; and `open-wp-admin` is a link out of the product, so it must not depend on the product working.

The manifest briefly held 54 more, marked `grandfathered`. Almost all were an artefact of the gate's first version, which only knew about route (1) and so demanded a feature toggle for surfaces whose module was switchable all along. Teaching it route (2) removed 47 at a stroke — and turned up one real bug, a Data browser tile that named no module and therefore survived its own module being switched off.

### The gate is itself tested

`bin/module-toggle-selfcheck.php` runs the gate against deliberately broken copies of the tree and asserts it fails on each, for the right reason. A gate that passes is not evidence that it works.

One thing it deliberately cannot check, and says so rather than guessing: a tile slug built from a variable at runtime. Surfaces that are not registered as tiles at all used to be the second blind spot; assertion 7 reads them off the dispatcher instead, so a tile-less module surface is now covered.

## See also

- [Authorization matrix](authorization-matrix.md) — module disable feeds into the matrix gate.
- [Access control](access-control.md) — the broader role + capability model.
