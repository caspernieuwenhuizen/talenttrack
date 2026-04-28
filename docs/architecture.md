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
- **Analytics group** — `rate-cards`, `compare`.

Each view extends `FrontendViewBase` and delegates form save to a REST endpoint. List tables are rendered by `FrontendListTable`, which speaks the same orderby/filter/search contract as the REST controllers (see [REST API reference](rest-api.md)).

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
