<!-- audience: dev -->

# Contributing to TalentTrack docs

The two rules every doc PR has to pass.

## 1. Front matter

Every doc that should be readable inside the product starts with a front-matter block. The block **is** the registry — a file that has one is a help topic, a file that doesn't is invisible to the in-product index. That is how developer-only documentation opts out; there is no separate list to edit.

```markdown
---
title: Match minutes
group: performance
summary: Record minutes played per player per fixture.
audience: [user, admin]
order: 40
---
```

| Key | Required | Meaning |
| --- | --- | --- |
| `title` | yes | Sidebar label. Also the H1 the body should open with. |
| `group` | yes | Which sidebar group. Must be a key of `HelpTopics::groups()`. |
| `summary` | yes | One line, tooltip-length. Also what the sidebar search matches on. |
| `audience` | yes | Who sees it in the TOC. See below. |
| `order` | no | Position within the group; lower sorts first. Topics without one sort after those with one, then alphabetically. |

A value containing `: ` must be quoted, because a bare colon reads as a nested key:

```markdown
summary: 'Run a structured trial: templates, staff input, decision.'
```

Add the same block to the Dutch counterpart with `title` and `summary` translated — the sidebar reads metadata from `docs/nl_NL/<slug>.md` when it exists, so an untranslated block leaves an English label in a Dutch TOC. `group`, `audience` and `order` stay identical in both.

Allowed `audience` values: `user`, `admin`, `dev`, `player`, `parent`. Use the inline-list form for cross-cutting topics (`audience: [user, admin]`).

The older `<!-- audience: … -->` comment is still honoured so an unmigrated file keeps working, but it registers nothing on its own — a file with only the comment and no front matter will not appear in the TOC. Migrate it.

`player` and `parent` (#0042) are persona-specific subsets of `user` — articles tagged with them surface only to the matching role. They're meant for the install-on-iPhone / install-on-Android / notifications-setup / parent-handles-everything KB; default user-facing docs stay on `audience: user`.

The in-product `Help & Docs` page filters its sidebar TOC by the viewer's role:

| Role / capability                                          | Audiences shown |
| ---                                                        | ---             |
| `tt_readonly_observer`, `tt_staff`, `tt_coach`              | `user`          |
| `tt_player`                                                | `user` + `player` |
| `tt_parent`                                                | `user` + `parent` |
| `tt_head_dev` (or `tt_edit_settings`)                      | `user` + `admin` |
| WP `administrator`                                         | all five (`user`, `admin`, `dev`, `player`, `parent`) |

A doc shows up if any of its declared audiences overlap with the viewer's allowed set.

Direct URL access is not gated — anyone with access to the docs page can read any doc by slug. The audience filter is a UX convenience, not access control.

CI rejects PRs that add a new doc without front matter.

## 2. Translations

The translation discipline is per audience:

- `audience` including `user`, `admin`, `player`, or `parent` → translation in `docs/nl_NL/<slug>.md` is **required in the same PR**, front matter included.
- `audience: [dev]` only → no Dutch translation. Dev docs are English-only by design — that's the working language for plugin extenders regardless of locale.

If a doc's audience changes from `dev` to anything else, add the Dutch translation in that PR. If it changes the other way, remove the Dutch counterpart in the same PR.

### i18n CI workflows (PR-time + weekly)

Translation drift is gated and reported automatically by two workflows under `.github/workflows/`:

- **`i18n-pr-check.yml`** runs on every PR that touches `src/**/*.php` or `languages/**`. It refreshes a snapshot `.pot` against the PR branch, `msgmerge`s it into `talenttrack-nl_NL.po`, and fails when the empty-`msgstr` count grew vs. `main`. The failure comment lists the new English msgids. The PR also fails if it adds an obvious hardcoded English leak (`wp_die("Capital…")`, `WP_Error('code', "Capital…")`, `sprintf("Capital…")` — wrap those in `__()` / `_e()` / `esc_html__()`). **Since #1338** the same job also surfaces `msgmerge` fatal-error output and runs `msguniq` over the committed `.po` so duplicate `msgid` definitions (e.g. an active block colliding with an obsolete `#~` block) fail at PR review instead of breaking `i18n-sync.yml` on `main`.
- **`i18n-drift-report.yml`** runs every Monday 06:00 UTC and on manual dispatch. It refreshes the `.pot`, `msgmerge`s every locale `.po`, counts empty + fuzzy entries, and writes the result into an auto-managed tracking issue titled `i18n drift report`. The body lists per-locale counts, the top 10 source files driving the `nl_NL` gap, and PRs merged in the last 7 days that touched PHP under `src/`.

**Override**: when a PR genuinely needs to ship new untranslated msgids (typically because a feature lands faster than the translator), label the PR `i18n-drift-acceptable`. The PR-check passes; the weekly drift report still records the new entries.

Neither workflow commits anything — `i18n-sync.yml` (structural `.pot` regeneration + `msgmerge`) is the only workflow that writes to `languages/`.

Dutch literals (`'Annuleren'`, `'Opslaan'`, `'Doelen…'`) as `msgid`s in PHP source are a bug — they sabotage `msgmerge` when the same literal also appears as an obsolete `#~` block in `nl_NL.po`. Always use English msgid + Dutch msgstr. The landmines on main as of v4.20.78 were cleaned up in #1339 and the PR-time gate added in #1338 prevents future regressions.

## Layout conventions

- One H1 per file, matching the front-matter `title`.
- H2 for major sections, H3 for sub-sections. Avoid going below H3.
- Tables for structured data; bullets for lists; paragraphs for prose. No nested lists deeper than two levels — re-think the structure if you need three.
- Code samples in fenced blocks with a language tag (`php`, `json`, `bash`, …).
- Inline `<code>` for slugs, capability names, table names, function names.

## Links

Four link shapes are recognised. Anything else renders as plain text.

### Another doc — a relative path

```markdown
See [REST API reference](rest-api.md) for the contract.
```

Resolves to whichever docs viewer the reader is already in, and works as a real file link when the doc is read on GitHub. Anchors are fine: `rest-api.md#authentication`.

Never hard-code `?page=tt-docs&topic=…`. It used to be rewritten to wp-admin unconditionally, so following one ejected a coach or a parent out of the app and into a page most of them cannot even load.

### A screen in the app — `?tt_view=`

```markdown
[Open the minutes grid](?tt_view=minutes-grid)
```

Renders as an action chip and carries a `tt_back` hint, so the reader lands on the screen with a back-pill to the topic they were reading. Extra query args are preserved (`?tt_view=players&status=trial`).

The link is **capability-aware**: for a reader whose install has that module or feature switched off, or who lacks the capability, the label renders as plain text instead. A doc can therefore link freely to a screen not everyone can open — nobody is sent to a permission-denied page.

Use these liberally. A support doc that names a screen and then makes the reader go find it is doing half its job.

### wp-admin — `?page=`, `admin.php?…`, `/wp-admin/…`

```markdown
[Error Log](?page=tt-error-log)
```

Renders **only for readers with `tt_edit_settings`**, with a visible marker and an accessible label saying it leaves TalentTrack. For everyone else the label renders as plain text.

So a topic that documents a wp-admin surface belongs on `audience: [admin]` — otherwise a coach reads a page describing something they cannot reach.

### Off-site — `https://`

Rendered as-is.

## Slugs

Slugs are kebab-case and are simply the filename without `.md` — there is no list to add them to. Give the file front matter and it is registered. New slugs still need a row in the layered TOC at [`docs/index.md`](index.md).

## When you add a feature

The release-discipline commitment from v2.22.0+ : every PR that ships user-facing change updates the relevant doc(s) in the same PR. The doc is the *current* state of the feature; `CHANGES.md` is the per-release diff, not a substitute.

## REST port-on-touch policy (#0052 PR-B)

When you touch a file that registers `admin_post_*` or `wp_ajax_*` handlers, port the handler to a REST endpoint in the same PR if the change is non-trivial. Trivial changes (typo fix, copy edit) don't trigger the port.

- The shared base lives at `src/Infrastructure/REST/BaseController.php` + `RestResponse.php` — every new controller extends them.
- The cap goes in `permission_callback` via `BaseController::permCan( 'tt_xyz' )` — never `__return_true` (except for legitimately-public endpoints where the URL token is the auth, like the invitation acceptance read).
- The remaining backlog of admin-post handlers is tracked in [`dev-tier-rest-port-backlog.md`](dev-tier-rest-port-backlog.md).

The REST surface gets stronger with every port; the admin-post surface shrinks.

## Running the REST contract test

`bin/contract-test.php` walks every read endpoint and verifies it returns the standard `RestResponse` envelope shape. Run it before a release or whenever a controller has been touched:

```
wp eval-file bin/contract-test.php
# or, raw php:
WP_LOAD=/path/to/wp-load.php php bin/contract-test.php
```

Auth-required endpoints register as `SKIP` when run unauthenticated; that's expected. The script exits non-zero if any endpoint fails the envelope check or returns ≥ 400 unauthenticated.

## PHP tests (PHPUnit + wp-env) — #1388

The plugin has a PHPUnit test floor that runs against a **real** WordPress + MySQL via wp-env (the same environment the Playwright E2E job uses), so tests exercise actual DB + WP behaviour rather than mocks. The suite lives in `tests/php/`; config in `phpunit.xml.dist`; bootstrap in `tests/php/bootstrap.php`.

**What's covered (Tier 1):**
- `MigrationRunner` — failure surfacing, the failed-migration-re-runs contract, `FAILURES_OPTION`.
- `AuthorizationService` + the authorization-matrix repository — the authz decision contract and grant round-trip (the regression class that bit the board six times: #1143/#1105/#1106/#1147/#1159/#1189).

Tier 2 (a ~20-endpoint REST smoke suite asserting status codes + envelope shape on the historically-buggy denial paths) is added under the same suite.

**Running locally** (requires Docker):

```
npm install                 # first time — installs @wordpress/env
npm run wp-env:start        # boots the WordPress + MySQL test env
npx wp-env run tests-cli --env-cwd=wp-content/plugins/talenttrack vendor/bin/phpunit
```

**CI:** `.github/workflows/php-tests.yml` runs the suite on every code PR and is a **required, blocking gate** — a red suite blocks merge. A deliberately broken authz grant or a migration that stops surfacing failures fails the build.

### Mandatory: a smoke test for every new REST endpoint

When you add a `register_rest_route(...)`, add a smoke test for it in `tests/php/` in the same PR. The bar is low — assert the **status code** and the **envelope shape** for at least the **denial path** (an unauthorised caller gets the expected 401/403) and the happy path. This is the cheapest insurance against the authorization-coverage bug class; full-content assertions are not required. (Trivial copy-only changes to an existing endpoint don't.)

**Enforced in CI (#1388).** `.github/workflows/rest-test-coverage.yml` (script: `scripts/rest-test-coverage.php`) is a diff-based, forward-only gate: a PR whose diff ADDS a `register_rest_route(` line under `src/` must, in the same diff, add or modify a file under `tests/php/` — otherwise the gate fails and names the offending controller(s). It grandfathers the existing routes (only diff-added registrations are in scope) and is coarse by design: it checks that the PR *touches* the PHP test dir, not that a specific test name matches the route — the reviewer confirms the test actually covers the new route. For the rare PR that genuinely needs no new test (a route moved verbatim between files, a trivial copy-only change), apply the `rest-test-exempt` label and the gate skips. The Tier 2 smoke pattern to copy lives in `tests/php/RestSmokeTest.php`.
