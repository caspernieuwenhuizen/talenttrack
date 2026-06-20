<!-- audience: dev -->

# Architecture

A working sketch of how TalentTrack is put together. For the workflow side (idea → spec → release) see [`DEVOPS.md`](../DEVOPS.md); for how to drive Claude Code on this codebase see [`AGENTS.md`](../AGENTS.md).

## Plugin entry + module pattern

`talenttrack.php` is the WP plugin entry point. It defines the `TT_*` constants, wires the autoloader (`vendor/autoload.php` if Composer ran, otherwise a fallback PSR-4 register), and on `plugins_loaded` boots the kernel.

Code lives under `src/` and uses the namespace `TT\…` matching its directory:

```
src/
├── Core/                    Kernel, Activator, ModuleInterface, Container
├── Infrastructure/          Cross-cutting concerns
│   ├── Database/            Migration runner + base class
│   ├── REST/                Generic REST controllers + RestResponse helper
│   ├── Query/               QueryHelpers (read-side façade), LabelTranslator
│   ├── Security/            AuthorizationService, capability resolution
│   ├── Logging/             Logger
│   └── …
├── Modules/                 One folder per feature area
│   ├── Players/             Admin/, Frontend/, Repositories/
│   ├── Sessions/
│   ├── Evaluations/
│   ├── Goals/
│   ├── Methodology/
│   ├── License/
│   ├── Documentation/
│   └── …
└── Shared/                  Reusable UI components, frontend layout helpers
    ├── Frontend/            DashboardShortcode, FrontendListTable, FrontendBackButton, …
    └── Admin/               Menu, BackButton, BulkActionsHelper, SchemaStatus
```

Every module implements `TT\Core\ModuleInterface` with two methods: `register( Container $c )` to declare bindings (rare; the project mostly uses static facades) and `boot( Container $c )` to attach hooks. Modules are listed in `Kernel::modules()` and booted in registration order.

Boot order:

1. `plugins_loaded@1` — load text domain.
2. `plugins_loaded@5` — `Kernel::instance()->boot()` registers + boots every module.
3. `plugins_loaded@10` — wp-admin menu extensions register.

## Schema + migrations

Schema lives in `database/migrations/NNNN_<name>.php`. Each migration returns an anonymous class extending `TT\Infrastructure\Database\Migration` with a `getName()` and `up()` method. The `MigrationRunner` scans the directory, sorts by filename (zero-padded numeric prefix), and runs anything not in the `tt_migrations` tracking table.

Conventions:

- Idempotent: every `CREATE TABLE` uses `IF NOT EXISTS`; column adds are guarded with `INFORMATION_SCHEMA.COLUMNS` checks; index adds with `INFORMATION_SCHEMA.STATISTICS`.
- Numbering is global across the plugin (not per-module).
- A migration that ships content seed data uses the same numbering and is considered cheap to re-run; idempotency checks are mandatory.
- The `Activator` runs `MigrationRunner::run()` on plugin activation as a safety net; the schema status admin notice nudges admins to run any pending migrations after a plugin update.

Tracking table: `<prefix>tt_migrations` with columns `id`, `migration` (UNIQUE), `applied_at`.

## Capability model (v3.0.0+)

The plugin ships granular caps split into view + edit pairs (`tt_view_<resource>` / `tt_edit_<resource>`) instead of the older single `tt_manage_<resource>` form.

Roles:

- `administrator` — superuser, has every cap.
- `tt_club_admin` — full club-management access; everything except plugin internals.
- `tt_head_dev` — head of development; club-wide view, scoped edit.
- `tt_coach` — coach with team-scoped access.
- `tt_player` — read-only on own profile + own evals/goals.
- `tt_readonly_observer` — read-only across analytics; no writes.
- `tt_staff`, `tt_scout` — peripheral roles with scoped views.

Authorization helpers live in `src/Infrastructure/Security/`:

- `AuthorizationService::canEditPlayer( $user_id, $player_id )` — entity-scoped check (a coach can only edit players on their teams).
- Capability resolution is filterable via `tt_auth_check`, `tt_auth_check_result`, `tt_auth_resolve_permissions`.

Functional roles (`tt_functional_role_*`) are a separate per-team-per-person assignment model layered on top of WP roles; they don't grant caps directly but feed the resolver.

## Frontend (post-#0019)

The plugin ships a fully frontend-first UI on top of the `[talenttrack_dashboard]` shortcode. `DashboardShortcode` renders the tile grid landing and dispatches into one of three view groups based on `?tt_view=`:

- **Me-group** — `my-evaluations`, `my-sessions`, `my-goals`, `my-card`, `my-profile`.
- **Coaching group** — `players`, `teams`, `evaluations`, `sessions`, `goals`, `methodology`.
- **Analytics group** — `compare`, `reports` (the Reports launcher, which now also lists `rate-cards` as an entry rather than a standalone tile).

Each view extends `FrontendViewBase` and delegates form save to a REST endpoint. List tables are rendered by `FrontendListTable`, which speaks the same orderby/filter/search contract as the REST controllers (see [REST API reference](rest-api.md)).

### Sectioned tile grids (#1543)

The Configuration-family views (Configuration, Lookups index, Modules, Export, Reports launcher) render a *curated, static* tile list grouped under headed sections. `\TT\Shared\Frontend\Components\FrontendSectionedTileGrid` is the shared presenter for that pattern:

- `render( array $sections, array $args = [] )` — `$sections` is an ordered list of `[ 'label', 'tiles' => [ ['label','desc','url','icon'?,'cap'?], … ], 'render'? => callable ]`. Each section renders a small-caps heading + the `.tt-cfg-tile-grid`; a tile carrying a `cap` is dropped when the user lacks it (matrix-aware), and a section with no surviving tiles renders nothing. Pass a section `render` callable (or a global `$args['tile_renderer']`) to emit a custom per-tile shape — e.g. the Export view's `<details>` accordions.
- `fromGroups( array $tiles, array $groups, string $leftover_label = '' )` — turns a flat curated list (each tile carrying a `slug`) into ordered sections by matching `$groups[]['slugs']`, with a trailing leftover bucket so a new tile is never silently dropped.

This is for the static curated tile family only. The persona-dashboard tile system (`FrontendTileGrid` / `TileRegistry`) is dynamic/persona/entity-driven and stays separate, as does the wp-admin `add_submenu_page` separator logic in `AdminMenuRegistry`.

### Tile icons — Phosphor duotone in accent chips (#1553)

Tile surfaces render their icon as a [Phosphor](https://phosphoricons.com/) **duotone** glyph (MIT-licensed) inside an accent-tinted rounded "chip": a ~3.25rem rounded square with a `color-mix(in srgb, <accent> 14%, #fff)` tint, a 2rem (32px) glyph, and the glyph colour set to the per-tile accent (the duotone inherits it via `currentColor`). The shared `\TT\Shared\Frontend\Components\TileIconChip::render( $icon_key, $accent )` helper emits the chip markup; `TileIconChip::styles()` emits its (idempotent) CSS once per request. It is wired into the persona-dashboard tiles (`FrontendTileGrid`) and the Configuration tiles (`.tt-cfg-tile-icon` in `FrontendConfigurationView`).

The duotone SVGs live in `assets/icons/duotone/<key>.svg`, keyed identically to the line-icon set. `IconRenderer::renderDuotone( $key )` loads from that directory and **falls back to the line icon** (`render()`) when no duotone variant is bundled for a key, so a tile is never blank. The bundled set + its MIT licence are under `assets/icons/duotone/` (`LICENSE`).

Scope is **tile surfaces only.** Inline icons (buttons, wp-admin menu, 18–20px usages) keep the original line set via `IconRenderer::render()` from `assets/icons/` — tiny duotone reads muddy. The inline render path is unchanged.

The sticky `body.tt-theme-inherit` toggle (#0023) lets a host theme override the design tokens TalentTrack defines. Token contract is documented in [theme integration](theme-integration.md).

## Settings + lookups

Two storage layers for configurable reference data:

- `tt_lookups` — open enums (positions, foot options, attendance statuses, evaluation types, goal status / priority). Translatable, drag-reorderable, club-extensible.
- `tt_config` — singletons (academy name, logo URL, rating max, color palette, feature toggles like `tt_show_legacy_menus`).

Code that needs a translatable user-facing value pulls it via `tt_lookups`; never hard-coded PHP arrays of UI strings (per the Ship-along rule in DEVOPS.md).

## SaaS-readiness scaffold (#0052 PR-A)

Per `CLAUDE.md` § 3, every new tenant-scoped table carries a `club_id INT UNSIGNED NOT NULL DEFAULT 1` and root entities carry a `uuid CHAR(36) UNIQUE`. PR-A backfills the same scaffold across the existing schema:

- **Tenancy column.** Migration `0038_tenancy_scaffold.php` adds `club_id` to ~50 tenant-scoped `tt_*` tables. Idempotent — already-shipped tables (trial cases, journey events) are skipped via `SHOW COLUMNS`.
- **UUID column.** Same migration adds `uuid VARCHAR(36) UNIQUE` to the five root entities (`tt_players`, `tt_teams`, `tt_evaluations`, `tt_activities`, `tt_goals`) and backfills existing rows in 500-row batches with `wp_generate_uuid4()`.
- **`tt_config` reshape.** Migration `0039_tt_config_tenancy.php` adds `club_id` to `tt_config` and replaces `PRIMARY KEY (config_key)` with `PRIMARY KEY (club_id, config_key)`. Tenant-scoped `wp_options` (the three trial-letter settings) get copied into `tt_config` keyed by `club_id=1`.
- **Resolver.** `Infrastructure\Tenancy\CurrentClub::id()` returns `1` today and is filterable via `tt_current_club_id`. A future SaaS auth backend (session / JWT / subdomain) hooks the filter; this class stays as the single chokepoint.
- **Helpers.** `QueryHelpers::clubScopeWhere()` returns the `club_id = N` SQL fragment; `QueryHelpers::clubScopeInsertColumn()` returns the insert-payload fragment. New repositories use these from day one; legacy repository sweep is deferred (see § Known SaaS-readiness gaps below).
- **`ConfigService`.** Already updated to filter by `CurrentClub::id()` on every read + write. Per-club cache namespace prevents stale cross-club reads.

The scaffold is invisible at runtime today (every row carries `club_id = 1`, every read implicitly returns the same single tenant). Verification via `bin/audit-tenancy.php` (run via `wp eval-file`).

### Known SaaS-readiness gaps (deferred to PR-B / PR-C / SaaS migration)

- **Repository read-side filter sweep.** Most repositories (`src/Modules/*/Repositories/`) still execute SQL like `SELECT ... FROM tt_xxx WHERE id = %d` without a `club_id` filter. Today this is correct (one tenant, all rows have `club_id=1`); a future second tenant would leak across boundaries. The mechanical sweep happens in PR-B + module-by-module follow-ups before any SaaS go-live. The audit script flags data-integrity violations; the read-side gap is documented here, not detected automatically.
- **Wizard analytics counters.** `tt_wizard_started_*` / `_completed_*` / `_skipped_*` rows in `wp_options` use dynamic keys and remain install-global. Per-club analytics is a separate refactor (small surface; not blocking).
- **`tt_user_id` resolver.** Player records still reference `wp_user_id` directly. The future SaaS auth model substitutes a portable identity; documented as intent in `docs/access-control.md`.

## Journey events (#0053)

The journey is a read-side aggregate, not a fifth modeling pillar. Per `CLAUDE.md` § 1, every player has a chronological story — the journey makes that story queryable without rewriting the modules that own the underlying records.

- **Schema:** `tt_player_events` is the spine. `tt_player_injuries` is a leaf table (the one major data source the codebase didn't already have).
- **Emission:** existing module hooks (`tt_evaluation_saved`, `tt_goal_saved`, `tt_pdp_verdict_signed_off`, `tt_player_save_diff`, `tt_trial_started`, `tt_trial_decision_recorded`) fire on lifecycle changes. `JourneyEventSubscriber` listens and projects each fire into a `tt_player_events` row via `EventEmitter::emit()`.
- **Idempotency:** `uk_natural (source_module, source_entity_type, source_entity_id, event_type)` makes re-emission a no-op. Repository hooks can fire on every save.
- **Visibility:** each row carries `visibility ∈ {public, coaching_staff, medical, safeguarding}`. Server-side filtering in `PlayerEventsRepository::timelineForPlayer` scopes the result to the viewer's caps and returns a `hidden_count` so the UI can render placeholders honestly.
- **Soft-correct:** `superseded_by_event_id` + `superseded_at` columns. Default reads filter superseded rows; `?include_superseded=1` reveals them.
- **Boundary with workflow tasks:** workflow tasks are **reminders** (someone needs to do something); journey events are **records** (something happened). Some triggers fan out both — an injury insert spawns a recovery-due workflow task *and* a journey event; that's coincidence, not coupling. Don't reuse one for the other.
- **Boundary with audit log:** `tt_audit_log` is operational telemetry (who changed what column when, for security investigation). The journey is the player-development story (what's happened to this player, in player-friendly language). Both can record an evaluation save; their consumers are different.

## Personal-data registry (#0081 child 1)

GDPR Articles 15 (subject access) and 17 (erasure) require an authoritative answer to *which tables hold this person's data*. Hard-coding that list inside an exporter or eraser invites drift.

`src/Infrastructure/Privacy/PlayerDataMap.php` is a static-only registry. Modules call `PlayerDataMap::register( $table, $player_id_column, $purpose, $owner_module )` from their boot path. Two query methods:
- `PlayerDataMap::all()` — full registration list.
- `PlayerDataMap::rowCountsForPlayer( int $player_id )` — runs a row-count per registered table; skips silently when a registered table doesn't exist (modules can be disabled).

`CorePiiRegistrations::register()` is the v1 backfill — registers 13 known core PII tables on every boot, called from `Kernel::boot()` after `bootAll()` so individual modules can register their own first. Coverage policy: only direct, indexed player-id FKs land in the central bootstrap. Junction-style tables that reach a player via two hops (e.g. `tt_pdp_conversations` → `tt_pdp_files.player_id`) are not registered — the erasure code walks them via the parent-table registration. Same logic for `tt_test_trainings`: session metadata, link to a person runs through `tt_workflow_tasks`.

Erasure execution is not in this registry — that lives in #0073. The registry is the contract; the eraser walks it.

## Storage (#0052 PR-C)

Asset URLs are the contract. The plugin must never assume `wp-content/uploads/` is the storage backend; SaaS deployments will use object storage (S3, R2). Helpers that read or transform images take a URL, not a server path; new code that needs to compose an asset URL goes through `wp_get_attachment_url()` or the dedicated REST endpoint, never through `WP_CONTENT_DIR . '/uploads/...'`.

The Backup module is the one place that legitimately writes to the local filesystem. `BackupDestinationInterface` abstracts the destination — `LocalDestination` writes to `wp-content/uploads/talenttrack-backups/`; SaaS deployments register an `S3Destination` (or similar) that implements the same interface. Audit pass for #0052 PR-C confirmed `BackupSettingsPage`'s `wp-content/uploads/...` reference is a UI label, the interface docblock is documentation, and `LocalDestination` is by design the local-FS backend — no migration is needed for `LocalDestination` itself.

Player photos (`tt_players.photo_url`) and club logo (`tt_config.logo_url`) are URL-only.

## Background work (#0052 PR-C)

Two scheduling layers coexist:

- **`wp_cron`** — infrastructure: usage telemetry rollups, backup cron, external-integration polling (Spond), the workflow engine's own cron tick. The user wouldn't recognise these as "tasks".
- **Workflow engine** (`src/Modules/Workflow/`) — domain tasks a coach / HoD / admin would recognise: post-match evaluation reminders, quarterly goal-setting cadence, PDP-verdict deadlines, trial-decision reminders, certification-expiry warnings.

The line is "would a coach / HoD / admin recognise this task?" — if yes, it's domain and lands in the workflow engine. SaaS migration replaces the scheduler underneath the workflow engine; one chokepoint is replaceable, fifty `wp_cron` registrations are not.

Five `wp_schedule_event()` callsites today (audit frozen as of v3.52.x):

| File | Category | Notes |
| - | - | - |
| `Infrastructure/Usage/UsageTracker.php` | Infrastructure | Stays. Rollup of plugin-internal counters. |
| `Modules/Backup/Scheduler.php` | Infrastructure | Stays. Backup cron is plumbing. |
| `Modules/Spond/SpondModule.php` | Infrastructure | Stays. External-integration polling, hourly. |
| `Modules/Workflow/Dispatchers/CronDispatcher.php` | Infrastructure | Stays. This *is* the workflow engine's own tick. |
| `Modules/Trials/Reminders/TrialReminderScheduler.php` | Domain (port-on-touch) | Coaches recognise "remind me about trial decisions". The `t-7 / t-3 / t-0` bucket logic + per-(case, user, bucket) state should migrate to a workflow template when Trials is next non-trivially edited. |

## Testing surface

There's no PHPUnit harness checked in (yet). The CI workflow runs:

- `php -l` syntax lint over every PHP file.
- `phpstan analyse` at level 8.
- `msgfmt --check` over every `.po`.

Manual test plans live in each PR's body. As the codebase grows, an integration harness for the REST controllers is the most likely next addition.

## Where to look when…

- "How do I add a new resource end-to-end?" — clone a small existing module (Goals is the cleanest); follow Module + REST + Repository + Frontend view + Admin page conventions.
- "How are migrations triggered?" — `Activator::activate()` for fresh installs; `MigrationRunner::run()` invoked by the schema status banner on update.
- "What's the source of truth for capabilities?" — `Kernel::ensureCapabilities()` calls each module's `ensureCapabilities()`. Roles are mutated idempotently on every activation.
- "How does the frontend find an endpoint?" — the view passes `data-rest-path` + `data-rest-method` on the `<form>`; `tt-ajax-form.js` reads those and posts.
