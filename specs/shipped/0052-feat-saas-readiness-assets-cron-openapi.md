<!-- type: feat -->

# #0052 — SaaS-readiness baseline (PR-C) — assets, cron, OpenAPI

This is the third of three independently-shippable PRs that together fulfil the #0052 epic. PR-C may not start until PR-A is merged and `bin/audit-tenancy.php` returns success; it does not depend on PR-B.

- PR-A — `specs/0052-feat-saas-readiness-tenancy-and-repos.md` — tenancy scaffold + repository enforcement (must merge first).
- PR-B — `specs/0052-feat-saas-readiness-rest-and-auth.md` — REST gap closure + auth portability.
- PR-C — *this spec* — asset/cron audit + hand-written OpenAPI contract.

PR-C may run in parallel with PR-B and with unrelated feature work — it touches no schema and no controller signatures.

## Problem

Three small but distinct surfaces that the medium-term SaaS migration would want already squared away:

1. **Asset references.** The plugin uses `wp-content/uploads/` paths in three places. Two are URL-based (good — portable to S3/R2 object storage). One needs a closer look: any file-system read (`file_get_contents`, `realpath`, `is_readable`) against an `uploads/`-prefixed path will break the moment the storage backend is no longer the local FS. SaaS migration would need to convert these to URL-based fetches; PR-C does it now while it's three sites instead of fifty.
2. **`wp_cron` vs the workflow engine.** 13 `wp_cron` / `wp_schedule_event` references exist across the codebase. The workflow engine (`src/Modules/Workflow/`) is the right home for *domain* scheduled work (post-match evals, self-evals, goal-setting cadence, cert-expiry warnings). Some of the 13 calls are infrastructure (translation cache invalidation, internal housekeeping) — those legitimately stay on `wp_cron`. Some are domain logic predating the workflow engine — those move. SaaS migration will replace the scheduler underneath; one chokepoint (workflow engine) is replaceable, fifty `wp_cron` registrations are not.
3. **OpenAPI is not published.** `docs/rest-api.md` is hand-maintained. For SaaS, an OpenAPI spec is the contract a non-WordPress client codegens against. Generating it from controller annotations would be ideal but requires tooling we don't have; hand-writing it (and CI-validating it against actual responses) is the pragmatic middle ground.

PR-C cleans all three up.

## Background — audit numbers (frozen as of v3.39.0)

- 3 `uploads/` path references in `src/`.
- `wp_enqueue_media()` in 2 places (`PlayersPage.php` for player photo, `ConfigurationPage.php` for club logo) — both URL-based, fine.
- `photo_url` on `tt_players` is `VARCHAR(500)` — already portable.
- 13 `wp_cron` / `wp_schedule_event` references. Categorisation done during this PR.
- No `talenttrack/v1` OpenAPI spec exists.
- `docs/rest-api.md` is the current hand-maintained reference.

## Decisions locked

Inherited from the 2026-04-28 inline-Q shaping pass for #0052:

1. **Workflow vs `wp_cron` line: would a coach / HoD / admin recognise the task?** If yes, it's domain — moves to the workflow engine. If no (translation cache, plugin housekeeping), it stays on `wp_cron`.
2. **OpenAPI: in scope, hand-written.** Not auto-generated. `docs/openapi.yaml` is the artefact. A contract test suite verifies real responses match the spec for at least the read endpoints.
3. **Sequencing: PR-C may run parallel to PR-B.** No conflict surface.

## Proposal

Three workstreams, one PR.

### Workstream 1 — asset portability

**Inspect the 3 `uploads/` references.** Done at build time; expected outcome is "0 of 3 are FS-reads, 3 of 3 are URL-based." If any are FS-reads, convert to URL-based via `wp_get_attachment_url()` or equivalent.

**Documentation.** `docs/architecture.md` § Storage gets a new paragraph:

> Asset URLs are the contract. The plugin must never assume `wp-content/uploads/` is the storage backend; SaaS deployments will use object storage (S3, R2). Helpers that read or transform images take a URL, not a server path. New code that needs to compose an asset URL goes through `wp_get_attachment_url()` or the dedicated REST endpoint, never through `WP_CONTENT_DIR . '/uploads/...'`.

**`docs/architecture.md` § Storage** is added if it doesn't exist; otherwise updated.

### Workstream 2 — `wp_cron` audit + selective workflow-engine migration

Each of the 13 references is categorised:

- **Stays on `wp_cron`** — infrastructure-level (translation cache, plugin update checker, internal housekeeping).
- **Moves to the workflow engine** — domain-level (cert-expiry warnings, eval reminders, scheduled report renders, anything a coach/HoD would recognise as a "task").

The exact split is decided during build (the audit numbers identify the call sites; the categorisation needs to read each one). Expected rough split: ~4-6 stay infrastructure, ~7-9 move to workflow templates.

For each migrated entry:
1. Define a workflow template under the appropriate module's `Workflow/` directory.
2. Register the template in `WorkflowTemplateRegistry`.
3. Remove the `wp_schedule_event()` call.
4. Migration step in this PR's deactivation hook (or a one-shot `wp_unschedule_event()`) to clean up the existing schedule on already-installed plugins.

`docs/workflow-engine-cron-setup.md` is updated with the line between domain and infrastructure scheduling.

### Workstream 3 — hand-written OpenAPI spec + contract test

**`docs/openapi.yaml`** ships as a hand-written OpenAPI 3.1 document covering every endpoint in `talenttrack/v1`. Each endpoint declares:

- HTTP verb + path
- Request body schema (where applicable)
- Response body schema (success + error shapes)
- Capability required (in `description`)
- Pagination headers (`X-WP-Total`, `X-WP-TotalPages`)

The spec sits alongside `docs/rest-api.md` — not replacing it. `docs/rest-api.md` remains the human-readable narrative; `openapi.yaml` is the machine-readable contract.

**Contract test — `bin/contract-test.php`.** Ships in this PR. A self-contained PHP script (no PHPUnit) that:

1. Boots WordPress.
2. For each `GET` endpoint with no required params (or with synthetic test data created in setup), calls the endpoint via `rest_do_request()` against a fresh test fixture.
3. Validates the response shape against the OpenAPI schema (using a lightweight JSON schema validator pulled in via composer if needed; otherwise a hand-rolled subset).
4. Reports per-endpoint pass/fail.

The script is meant to be run manually or via a future CI hook — not blocking on every PR. Documented in `docs/contributing.md`.

**`docs/rest-api.md`** is updated with a pointer to `openapi.yaml`:

> The canonical machine-readable contract lives in `docs/openapi.yaml`. This document is the human-readable narrative; if the two disagree, treat the OpenAPI spec as authoritative and open an issue.

**v1 → v2 migration policy** is documented as a closing section of `docs/rest-api.md`:

> Breaking changes to a `talenttrack/v1` endpoint shape bump the namespace to `talenttrack/v2`. The v1 namespace is supported for at least one release after v2 ships, with `Deprecation: true` headers on the v1 responses. This policy is **codified but not yet exercised** — every change to `v1` so far has been backwards-compatible.

## Acceptance criteria

- [ ] All 3 `uploads/` references inspected. Any FS-reads converted to URL-based fetches.
- [ ] `docs/architecture.md` § Storage updated with the asset-URL contract.
- [ ] All 13 `wp_cron` / `wp_schedule_event` references categorised. Domain ones migrated to workflow templates; infrastructure ones documented as staying.
- [ ] `docs/workflow-engine-cron-setup.md` updated with the domain-vs-infrastructure line.
- [ ] `docs/openapi.yaml` written, covering every `talenttrack/v1` endpoint.
- [ ] `bin/contract-test.php` ships and passes against a fresh fixture install.
- [ ] `docs/rest-api.md` updated with the OpenAPI pointer + v1/v2 migration policy section.
- [ ] `docs/contributing.md` updated with how to run the contract test.
- [ ] `languages/talenttrack-nl_NL.po` — most of this PR is invisible to end users; expect zero or one new strings.
- [ ] PHPStan level 8 + PHP lint + msgfmt + docs-audience CI pass.
- [ ] Manual smoke: existing UI flows unchanged. Migrated workflow templates fire on schedule (verifiable via `tt_workflow_event_log`).
- [ ] `SEQUENCE.md` updated: PR-C row moves from "Ready" to "Done"; if PR-B has also landed, the #0052 epic row collapses to "Done" with all three sub-rows.

## Verification

- **PHPStan level 8 + PHP lint.** Catches dead `wp_schedule_event` callbacks once the registration is removed.
- **OpenAPI YAML validation.** Run `npx @apidevtools/swagger-cli validate docs/openapi.yaml` (manual; not in CI yet).
- **Contract test.** `bin/contract-test.php` returns exit 0 on a fresh fixture.
- **Cron migration smoke.** On a test install, verify the migrated workflow templates appear in the workflow admin UI and produce tasks on schedule. Verify the unschedule logic actually removed the old `wp_cron` entry (`wp_get_scheduled_event()` returns false for the old hook).

## Sequencing

- PR-C blocks on PR-A merging successfully and `bin/audit-tenancy.php` returning success.
- May run in parallel with PR-B — no conflict surface.
- Recommended release: bundle PR-B + PR-C into one release tag if both land within the same week.

## Out of scope (explicitly)

- Auto-generated OpenAPI from controller annotations. (Tooling we don't have.)
- A new `talenttrack/v2` namespace. (Documented policy only — same as PR-B.)
- Replacing `wp_cron` entirely with the workflow engine. Infrastructure tasks legitimately stay on `wp_cron`.
- S3 / R2 object storage integration. PR-C only ensures we *could* migrate; it does not migrate.
- Anything in PR-A's schema scope or PR-B's REST/auth scope.

## Estimated effort

~8-12h actual based on recent compression patterns. The OpenAPI hand-write is the longest single workstream (~5-7h for ~16 controllers). Cron migration is ~2-3h depending on how many of the 13 calls move. Asset audit is ~1h.
