<!-- type: epic -->

# SaaS-readiness baseline — make the existing codebase ready for the future migration

Raw idea:

The medium-term plan is to migrate to a full SaaS front end (separate web app, possibly mobile native, possibly multi-tenant). `CLAUDE.md` now codifies forward-looking SaaS-readiness rules for new code. This epic is the *one-time backfill*: bring the existing codebase up to the same standard so the migration sprint, when it comes, isn't gated on cleaning up technical debt across 38 migrations and 20+ modules.

This is preparation, not migration. We are not building a SaaS front end here — we are removing the things that would make a SaaS front end painful.

## Why this is an epic

It's cross-cutting (touches schema, REST, auth, modules), it's not urgent, but every PR that ships without thinking about it makes the cleanup bigger. Better to do it once, deliberately, than to discover during a SaaS sprint that 41 files still post to `admin-post.php` and there's no tenancy column anywhere.

Estimated 4-6 sprints if done thoroughly. Some sprints can be parallelized with unrelated feature work because the changes are isolated to specific layers.

## What "SaaS-ready" actually means here

Four properties the codebase should have, in priority order:

1. **Tenancy-scaffolded.** A future multi-tenant version can scope every record to an account/club without a schema rebuild.
2. **API-complete.** Every feature is reachable through REST. The PHP-rendered front end is *one* consumer of the API, not the canonical interface.
3. **Auth-portable.** Capability checks are abstracted from cookie state. A future JWT/OAuth backend swap doesn't require touching every controller.
4. **Asset-portable.** File/media references are URLs, not server-relative paths. Object storage migration is one config change away.

Mobile and player-centric concerns are handled separately (in `CLAUDE.md` and the per-feature work). This epic is just the SaaS plumbing.

## Audit — where the codebase stands today

Concrete numbers from the v3.39.0 source.

### Tenancy

**Status: not started.**

- Zero tables have a `club_id` / `tenant_id` / `account_id` column. Searched both `src/` and `database/migrations/`.
- `wp_generate_uuid4()` is used in three places, all for transient DOM IDs (PSP component, ratecard payload, invite popover). No persistent UUIDs on any root entity.
- 42 `tt_*` tables exist across 38 migrations. Of those, ~15 hold tenant-scoped data (players, teams, evaluations, goals, sessions, attendance, eval_categories, custom fields, custom_values, audit_log, invitations, workflow tasks/triggers, methodology assets, set_pieces, formations).
- `tt_config` is keyed only by `key` — no tenancy dimension. A second tenant would overwrite the first's config.
- `wp_options` and `update_option()` / `get_option()` are used in ~20 places across modules. Anything stored there is global to the WP install, which is fine for a single-tenant plugin and a problem for SaaS.

**What backfill looks like:**
- Add `club_id INT UNSIGNED NOT NULL DEFAULT 1` to every tenant-scoped table. Index it.
- Add `uuid CHAR(36) UNIQUE` to root entities: `tt_players`, `tt_teams`, `tt_evaluations`, `tt_sessions`, `tt_goals`. Backfill with `wp_generate_uuid4()` in the migration.
- Audit `tt_config` and any `wp_options` reads. Anything tenant-scoped moves to `tt_config` keyed by `(club_id, key)`. Anything WP-install-global (cache flags, etc.) can stay in `wp_options`.
- Wrap reads in `Infrastructure/Query/` helpers that automatically include `club_id = current_club_id()` in the `where` clause. Today `current_club_id()` returns `1`. Tomorrow it reads from session/JWT.

### REST coverage

**Status: solid foundation, gaps exist.**

- 16 REST controllers exist (12 in `Infrastructure/REST/`, 4 in `Modules/Pdp/Rest/`, 1 in `Modules/TeamDevelopment/Rest/`).
- Coverage spans: players, teams, sessions/activities, evaluations, eval categories, goals, people, custom fields, functional roles, config, PDP (conversations, files, verdicts, seasons), team development.
- Auth is via `X-WP-Nonce` for browsers, application passwords for integrations. `permission_callback` uses capability checks (`current_user_can`), which is portable.
- `RestResponse` and `BaseController` exist as shared helpers — good.

**Gaps:**
- 41 PHP files still use `admin_post_*` / `wp_ajax_*` action hooks (225 hook registrations total). These are the legacy save-and-redirect pattern. They work but they're not the REST contract. Gradual migration: every time a module gets touched, port its `admin_post_*` handlers to REST. Track progress in this epic.
- No REST endpoints found for: methodology, workflow engine, lookups, audit log read API, invitations (uses its own dispatch), backups, migrations, usage stats. Some of these are admin-internal and can stay; some (lookups, audit log, invitations) probably should be REST too.
- No published OpenAPI spec / no schema validation. `docs/rest-api.md` is hand-maintained. For SaaS, an OpenAPI doc generated from the controllers is much better. Optional but nice.
- No `talenttrack/v2` namespace exists. The migration discipline in `CLAUDE.md` says breaking changes bump the namespace; that hasn't been needed yet but the convention should be exercised at least once.

### Auth portability

**Status: mostly good, some role-string bleed.**

- 283 `current_user_can()` calls, 87 nonce checks. The cap layer is real and used.
- `AuthorizationService` exists (`src/Infrastructure/Security/`) with entity-scoped methods (`canEditPlayer( $user_id, $player_id )`). This is exactly the right abstraction.
- Granular caps exist (`tt_view_*` / `tt_edit_*`).
- 22 files still call `is_user_logged_in()`. Most are probably fine (gate-keeping a frontend page), but each one is a place where SaaS auth has to be rewired.
- 5 files do role-string comparison via `in_array( 'role_name', $user->roles, true )`. Hits in `DemoDataCleaner`, `AudienceResolver`, `OnboardingHandlers`, `PdpVerdictsRestController`. One of those (`PdpVerdictsRestController`) checks both `current_user_can('tt_head_dev')` AND `in_array('tt_head_dev', $roles)` — redundant; the cap check is enough. Role-string comparisons in REST controllers are exactly what this epic wants gone.

**What backfill looks like:**
- Replace `in_array( 'role_x', $user->roles )` with `current_user_can( 'cap_x' )` where the role grants a unique capability. Where it doesn't, define one.
- Audit the 22 `is_user_logged_in()` calls — most can become capability checks for the specific thing they gate. Bare login-checks should be the exception.
- Where the user identity matters (not just permissions), prefer `wp_get_current_user()->ID` mapped through a `tt_user_id` resolver, so a future SaaS user model can substitute.

### Asset portability

**Status: low risk, small surface.**

- 3 references to `uploads/` paths in `src/`. Need to inspect each to see if they're URL-based or server-FS-based.
- `wp_enqueue_media()` is used in two places (`PlayersPage.php` for player photo, `ConfigurationPage.php` for club logo). Returns URLs, fine for SaaS.
- `photo_url` on `tt_players` is stored as a URL (`VARCHAR(500)`) — already portable.
- No direct `file_get_contents()` against `wp-content/uploads/` style paths found in a quick grep, but a closer audit is warranted.

**What backfill looks like:**
- Inspect the 3 `uploads/` references, convert any FS reads to URL-based fetches.
- Document in `docs/architecture.md` that asset URLs are the contract; FS paths are private to the storage backend.

### Background work

**Status: mixed.**

- 13 `wp_cron` / `wp_schedule_event` references.
- A workflow engine exists (`src/Modules/Workflow/`, ~243K) — that's the right home for new scheduled work. `docs/workflow-engine-cron-setup.md` covers it.
- The 13 `wp_cron` calls predate or sit alongside the workflow engine. Audit each: keep the ones that are genuinely WP-internal (e.g. translation cache), migrate the ones that are domain logic into the workflow engine.

### Front-end coupling

**Status: already mostly clean (post-#0019 refactor).**

- Vanilla JS + REST + nonce header. No jQuery dependency for new code.
- 7 `wp_localize_script` calls — the `TT.*` global pattern is consistent.
- Front-end JS reads from `window.TT.*`, calls REST. Good.
- Some PHP-rendered HTML still does heavy data composition in `render*()` methods (visible in `FrontendMyProfileView`, `FrontendComparisonView`, etc.). Logic vs. presentation separation needs attention but is not a SaaS-blocker — it's a separate refactor concern that benefits SaaS too.

## Decomposition (rough — for shaping into specs later)

Six sprint-sized chunks. Some can run in parallel, some must be sequential.

### Sprint 1 — Tenancy scaffold (sequential, must come first)

- New migration adds `club_id` to all tenant-scoped tables (~15 tables) with default `1` and an index.
- New migration adds `uuid` to root entities (5 tables) with backfill.
- Introduce `Infrastructure\Tenancy\CurrentClub::id()` helper, returning `1` for now. All future repository reads include `club_id = current_club()` in `where`.
- Move tenant-scoped `wp_options` reads to `tt_config`. Audit and document.

### Sprint 2 — Repository layer enforces tenancy (sequential, depends on Sprint 1)

- Audit every repository under `src/Modules/*/Repositories/`. Add `club_id` filter to read methods.
- Audit every direct `$wpdb` query in `src/`. Same treatment.
- This is mechanical but high-touch — every module is affected. Test coverage should catch regressions; if it doesn't, that's a parallel concern.

### Sprint 3 — REST gap closure (parallelizable)

- Inventory `admin_post_*` and `wp_ajax_*` handlers (41 files, 225 registrations). Categorize: keep / port-to-REST / remove.
- Port the high-value ones to REST. The rest become a documented backlog of "still on admin-post, port when touched."
- Add REST endpoints for surfaces that lack them: lookups, audit log read, invitations dispatch.

### Sprint 4 — Auth portability cleanup (parallelizable, low risk)

- Replace role-string `in_array` checks with capability checks (5 files).
- Audit `is_user_logged_in()` calls (22 instances) — convert to capability checks where the gate is more specific than just "logged in."
- Document the auth contract in `docs/access-control.md`: caps are the contract, role names are an implementation detail.

### Sprint 5 — Asset & cron audit (small, can run alongside any other sprint)

- Inspect the 3 `uploads/` path references, convert FS reads to URL-based.
- Audit the 13 `wp_cron` calls, migrate domain logic into the workflow engine.
- Document the asset contract in `docs/architecture.md`.

### Sprint 6 — API contract hardening (last)

- Generate / hand-write an OpenAPI spec for `talenttrack/v1`. Optionally automate from controller annotations.
- Add a contract test suite that hits every endpoint and validates response shape.
- Document the v1 → v2 migration policy. Bump to v2 only when there's a real reason; this epic doesn't introduce one.

## Open questions

- **Naming.** `club_id`, `tenant_id`, `account_id` — pick one and use it consistently. `club_id` reads most naturally for this domain (a SaaS install hosts many clubs/academies). Confirm before Sprint 1.
- **UUID format.** `CHAR(36)` (string with dashes) or `BINARY(16)` (compact)? String is friendlier for debugging and REST payloads; binary is faster and smaller. Prefer string unless there's a measured perf reason.
- **`tt_config` schema change.** Adding a `club_id` dimension is a breaking schema change for existing installs. Migration must preserve current behavior (existing rows get `club_id = 1`).
- **Workflow engine vs. wp_cron.** Some `wp_cron` calls are genuinely WP-internal (translation cache invalidation, etc.). Where's the line? Document it.
- **Should this block other work?** Sprint 1 is intrusive (touches every tenant-scoped table). Best to schedule it during a quiet feature-development window rather than mid-sprint on something else.
- **Parallel-agent safety.** Per `AGENTS.md`, schema-touching work is solo. Sprint 1 must run alone. Sprints 3, 4, 5 can run alongside unrelated feature work.

## Touches

- New migrations: ~3 (tenancy column, uuid column, tt_config schema change).
- `src/Infrastructure/Tenancy/` — new namespace.
- `src/Infrastructure/Query/QueryHelpers.php` — augment with tenancy helpers.
- Every `src/Modules/*/Repositories/*.php` — add tenancy filter to reads.
- Every `src/Infrastructure/REST/*.php` and `src/Modules/*/Rest/*.php` — verify auth and tenancy.
- 41 files using `admin_post_*` / `wp_ajax_*` — gradual REST port.
- 5 files with role-string compare — capability cleanup.
- `docs/architecture.md`, `docs/access-control.md`, `docs/rest-api.md` — updated.

## What this epic explicitly does NOT do

- Build the SaaS front end. That's a separate, future epic.
- Pick a SaaS framework (React/Vue/etc.). Premature.
- Choose an auth backend (JWT / OAuth / sessions). Premature.
- Multi-tenant the WP plugin. We're scaffolding for tenancy, not enabling it. The plugin remains single-tenant; `current_club()` returns `1`.
- Refactor view files to remove business logic. That's a related but separate concern (covered as a smell test in `CLAUDE.md`'s SaaS principle but not part of this baseline).

## Why now (and why not now)

**Now**: every PR shipped without these scaffolds adds to the cleanup. Sprint 1 alone (the tenancy scaffold) is dramatically cheaper to do today across 15 tables than later across 30+. The upside is asymmetric: small cost today, large saved cost later.

**Not now**: if there's an active feature push or a release in flight, Sprint 1 is too disruptive to interleave. Wait for a quiet week. Sprints 3-5 don't have that constraint and can be picked up opportunistically.
