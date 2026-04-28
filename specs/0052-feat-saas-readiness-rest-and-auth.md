<!-- type: feat -->

# #0052 — SaaS-readiness baseline (PR-B) — REST gap closure + auth portability

This is the second of three independently-shippable PRs that together fulfil the #0052 epic. PR-B may not start until PR-A is merged and `bin/audit-tenancy.php` returns success.

- PR-A — `specs/0052-feat-saas-readiness-tenancy-and-repos.md` — tenancy scaffold + repository enforcement (must merge first).
- PR-B — *this spec* — REST gap closure + auth portability cleanup.
- PR-C — `specs/0052-feat-saas-readiness-assets-cron-openapi.md` — asset/cron audit + OpenAPI contract.

PR-B does **not** touch schema, so it can run in parallel with PR-C and with unrelated feature work. The decisions locked in PR-A's spec (`club_id`, `CHAR(36)` UUIDs, `CurrentClub::id()`) are inherited here.

## Problem

The plugin's REST surface is already solid — 16 controllers across 5 namespaces, 283 `current_user_can()` calls, capability-driven `permission_callback` everywhere, and a `BaseController` + `RestResponse` shared infrastructure. But two patches of legacy material make the SaaS-migration story harder than it needs to be:

1. **REST is not the only contract.** 41 PHP files still use `admin_post_*` / `wp_ajax_*` action hooks (225 hook registrations total). These work — they're how WordPress historically did form submission — but they bypass the capability-driven REST layer entirely. A future non-WordPress front end (the medium-term SaaS plan) cannot consume them. Some surfaces have *no* REST endpoint at all: lookups, audit log read, invitations dispatch, and a few admin-internal panes. Per `CLAUDE.md` § 3, "every feature must be reachable through the REST API."
2. **Auth bleeds role-string compares in five places.** `current_user_can()` is the contract; role-string `in_array( 'role_name', $user->roles, true )` checks are an implementation-detail leak that breaks the moment SaaS swaps the auth backend. Five files do role-string compares: `DemoDataCleaner`, `AudienceResolver`, `OnboardingHandlers`, `PdpVerdictsRestController`, and one more identified during this PR's audit. `PdpVerdictsRestController` is the worst — it does *both* a cap check and a role-string check, which means the role-string check is dead code that still has to be maintained.

PR-B is the cleanup pass for both. It does not aim to port every `admin_post_*` to REST today (that would be a full sprint of churn for negative short-term value) — it instead establishes a port-on-touch policy, ports the highest-value surfaces (lookups + audit log read + invitations dispatch), and scrubs the role-string compares.

## Background — audit numbers (frozen as of v3.39.0)

- 16 REST controllers in v1 namespace `talenttrack/v1`.
  - 12 in `src/Infrastructure/REST/`
  - 4 in `src/Modules/Pdp/Rest/`
  - 1 in `src/Modules/TeamDevelopment/Rest/`
- Coverage spans: players, teams, sessions/activities, evaluations, eval categories, goals, people, custom fields, functional roles, config, PDP (conversations, files, verdicts, seasons), team development.
- 41 PHP files registering `admin_post_*` / `wp_ajax_*` hooks (225 registrations total).
- 22 `is_user_logged_in()` calls. Most gate frontend pages and are reasonable; some are over-broad and could be capability-specific.
- 5 files with `in_array( 'role_x', $user->roles, true )` role-string compare:
  - `DemoDataCleaner`
  - `AudienceResolver`
  - `OnboardingHandlers`
  - `PdpVerdictsRestController` (also has the cap check; redundant)
  - One additional file identified during the audit pass (logged in PR commit body)
- No `talenttrack/v2` namespace exists. PR-B does **not** introduce one — the migration discipline is documented but not exercised here.

## Decisions locked

Inherited from the 2026-04-28 inline-Q shaping pass for #0052:

1. **`admin_post_*` migration: port-on-touch by default + opportunistic ports for high-value surfaces.** Not a sweeping migration. Ports the surfaces with the strongest case (lookups, audit log read, invitations dispatch). The remaining 38-ish files become a *documented backlog* of "still on admin-post; port when next touched."
2. **Auth contract: capability-only.** Role-string compares are eliminated. Where a role implies a unique capability and that capability doesn't exist yet, define one.
3. **`is_user_logged_in()` audit: tighten where the gate is more specific than 'logged in'; leave alone where bare login *is* the gate.** Most frontend page guards are fine. ~5-8 of the 22 calls are over-broad — those convert to capability checks.
4. **`tt_user_id` resolver deferred.** PR-A documented this; PR-B inherits the decision. SaaS-migration epic owns it.
5. **No v2 namespace exercise.** Defer until the first real breaking change earns it.

## Proposal

Three coordinated workstreams in one PR.

### Workstream 1 — REST gap closure

Three new REST controllers register under `talenttrack/v1`:

**`/wp-json/talenttrack/v1/lookups`**
- `GET /lookups` — list lookup types (paginated).
- `GET /lookups/{type}` — list values for a type (e.g. `/lookups/cert_type`).
- `POST /lookups/{type}` — create a value (cap: `tt_manage_lookups`).
- `PUT /lookups/{type}/{id}` — update.
- `DELETE /lookups/{type}/{id}` — delete (or archive — match existing repo behaviour).

Replaces the current admin-only direct queries against `tt_lookups`. Frontend already reads lookups via PHP-side `LookupRepository`; REST endpoint is for the future SaaS front end.

**`/wp-json/talenttrack/v1/audit-log`**
- `GET /audit-log` — paginated read with filters (entity_type, entity_id, user_id, date range). Cap: `tt_view_audit_log`.

The audit log is currently rendered server-side via `FrontendAuditLogView`. The view stays; the REST endpoint is added as the SaaS-front-end contract. The view could later be migrated to call the REST endpoint, but that's a follow-up.

**`/wp-json/talenttrack/v1/invitations`**
- `POST /invitations` — create invitation (cap: per-entity, depends on what's being invited).
- `GET /invitations/{token}` — read invitation by token (public-readable, used by the accept page).
- `POST /invitations/{token}/accept` — accept and create the user.
- `DELETE /invitations/{id}` — revoke (cap: invitation creator or `tt_manage_invitations`).

Invitations currently use a custom dispatch flow under `src/Modules/Invitations/`. The REST controller wraps the existing dispatcher rather than reimplementing it.

All three controllers follow the existing pattern: extend `BaseController`, return `RestResponse`, declare `permission_callback` against capabilities. No `permission_callback` returns `__return_true` except `GET /invitations/{token}` (which is intentionally public — the token *is* the auth).

### Workstream 2 — port-on-touch policy + high-value `admin_post_*` ports

The default policy is documented in `docs/architecture.md` and `docs/contributing.md`:

> When you touch a file that registers `admin_post_*` or `wp_ajax_*` handlers, port the handler to a REST endpoint in the same PR if the change is non-trivial. Trivial changes (typo fix, copy edit) don't trigger the port.

To kick the policy off, this PR ports three concrete admin-post handlers identified as highest-value during shaping. The exact list is decided during build (not pre-declared here) but candidates include:

- The bulk-archive / bulk-delete handlers (touched frequently, highest-leverage surface).
- The eval-save handler if it's still admin-post (much of #0014 work moved this to REST already; verify).
- The invitations send/resend dispatch (overlaps with workstream 1).

The remaining ~38 files are listed in a new `docs/dev-tier-rest-port-backlog.md` — one row per file with current handler name, suggested REST verb+path, and "port when next touched" instruction.

### Workstream 3 — auth portability cleanup

**Role-string compare elimination.** The five files identified in the audit are touched:

- `DemoDataCleaner` — switch to a `tt_manage_demo_data` cap (define if it doesn't exist).
- `AudienceResolver` — this one *does* legitimately need to know the role (audience routing is role-based by design); keep the role check but isolate behind a `RoleResolver::primaryRoleFor( $user_id )` helper that future SaaS auth can re-implement. Documented as the *one* allowed role-aware surface.
- `OnboardingHandlers` — switch to a `tt_run_onboarding` cap or similar.
- `PdpVerdictsRestController` — drop the redundant role-string check; keep only the cap check.
- The fifth file (identified at build time) — same treatment.

**`is_user_logged_in()` audit.** All 22 calls reviewed. For each:

- If the gate is "logged in *and nothing else*", leave alone.
- If the gate implies a more specific capability ("logged in *and can see PDP files*"), replace with `current_user_can()`.

The expected outcome: ~5-8 calls convert to capability checks; ~14-17 stay. Each conversion is documented in the PR commit body.

**Documentation update.** `docs/access-control.md` gets a new top-section paragraph:

> Capabilities are the auth contract. Role names are an implementation detail that maps a default cap bundle to a user; do not check role names directly except via `RoleResolver::primaryRoleFor()` for audience routing. A future SaaS auth backend may not preserve role names at all.

## Acceptance criteria

- [ ] Three new REST controllers (`lookups`, `audit-log`, `invitations`) registered under `talenttrack/v1` with capability-based `permission_callback`.
- [ ] `docs/rest-api.md` updated with all new endpoints, payload shapes, and capability requirements.
- [ ] At least three high-value `admin_post_*` handlers ported to REST.
- [ ] `docs/dev-tier-rest-port-backlog.md` created with the remaining `admin_post_*` files listed.
- [ ] Port-on-touch policy documented in `docs/architecture.md` and `docs/contributing.md`.
- [ ] All five role-string compare sites updated. The one legitimate role-aware site (`AudienceResolver`) refactored behind `RoleResolver::primaryRoleFor()`.
- [ ] `is_user_logged_in()` audit complete; over-broad gates converted to capability checks (per-conversion logged in commit body).
- [ ] `docs/access-control.md` updated with the capability-as-contract paragraph.
- [ ] `languages/talenttrack-nl_NL.po` updated for any user-facing error strings on new endpoints (e.g. permission denied messages).
- [ ] `docs/<slug>.md` + `docs/nl_NL/<slug>.md` updated for any user-visible behaviour change (most of this PR is invisible to end users; expect light or no NL doc churn).
- [ ] PHPStan level 8 + PHP lint pass.
- [ ] Manual smoke: every existing UI flow still works (admin & frontend). New REST endpoints return correct shapes via curl/Postman.
- [ ] `SEQUENCE.md` updated: PR-B row moves from "Ready" to "Done".

## Verification

- **PHPStan level 8 + PHP lint.** Catches dead role-string compares (where `$user->roles` is no longer accessed) and any controller signature drift.
- **Manual smoke checklist.** Run the existing happy-path smoke (admin pages + frontend dashboards). For each ported `admin_post_*` handler, verify the form still submits and produces the same end state. For each new REST endpoint, exercise it via the existing REST tooling (browser dev tools or curl with `X-WP-Nonce`).
- **`is_user_logged_in()` regression check.** Walk the audit list during code review; for each conversion, confirm the original gate's *intent* is preserved (not narrower, not broader).

## Sequencing

- PR-B blocks on PR-A merging successfully and `bin/audit-tenancy.php` returning success.
- Once PR-A is in, PR-B can run in parallel with PR-C and with unrelated feature work — it touches no schema and no shared infrastructure beyond `BaseController` (which is stable).
- Recommended release: bundle PR-B + PR-C into a single release tag if both land within the same week, or release PR-B independently if PR-C runs longer.

## Out of scope (explicitly)

- Porting every `admin_post_*` handler. Port-on-touch policy + three high-value ports + a documented backlog is the deal.
- Introducing the `talenttrack/v2` namespace. Defer until the first real breaking change.
- The `tt_user_id` resolver. Still deferred to the SaaS-migration epic.
- Anything in PR-A's schema scope (already merged at this point).
- Anything in PR-C's asset/cron/OpenAPI scope.

## Estimated effort

~10-14h actual based on recent compression patterns. Three small REST controllers + three handler ports + an audit pass on five role-string sites + 22 `is_user_logged_in()` reviews. Allows parallelisation with PR-C.
