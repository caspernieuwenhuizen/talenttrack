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

### Gating keys — what this install actually runs

Four optional keys hide a topic when the install cannot use the feature it describes. They resolve through `ContentGate`, shared with the `courses/` corpus, in this order:

| Key | Hidden when | Example |
| --- | --- | --- |
| `module` | the module is switched off | `module: TT\Modules\Methodology\MethodologyModule` |
| `feature` | the `FeatureRegistry` toggle is off | `feature: team_chemistry` |
| `tier` | the licence is below it | `tier: pro` |
| `capability` | the reader lacks it | `capability: tt_view_data_browser` |

Omit a key and it is not a gate — most topics carry none. Hiding is complete: TOC, search, drawer **and** direct URL. Unlike the audience filter, this *is* access control, so the docs never walk a reader into a screen they cannot open.

An unknown `module` / `feature` value leaves the topic visible rather than silently hiding it — a doc that vanishes on someone else's install is the harder bug to find. That is what makes a typo here invisible at runtime, and why the lint checks these values.

### Dev-only docs opt out

A file with no front matter is invisible to the product. That is the intended state for developer documentation, and the set is fixed — every other file must be registered:

```
architecture-mobile-first    frontend-shell
back-navigation              frontend-themes
branded-404                  i18n-architecture
contributing                 i18n-audit-2026-05
dev-tier-rest-port-backlog   index
frontend-2026-patterns       methodology-authoring
mobile-patterns              translator-brief
ui-copy
```

Adding a file to `docs/` means picking one of two states: front matter, or this list. There is no third. The corpus reached 53 unreachable files (#2548) precisely because a third state — "on disk, registered nowhere, listed nowhere" — was allowed to exist.

### The gate

`docs-lint.yml` runs `tools/check-docs.php` on any PR touching `docs/**`, the Documentation module, the dispatcher, or the allowlists. Run it locally before pushing:

```
php tools/check-docs.php                     # structural rules
php tools/check-docs.php --base=origin/main  # adds the diff-only voice rules
```

| # | Rule | Scope |
| --- | --- | --- |
| 1 | Front matter, or the dev-only allowlist above. No third state. | corpus |
| 2 | `title` / `group` / `summary` present; `group` is a key of `HelpTopics::groups()`. | corpus |
| 3 | `audience` present, every value one of the five. | corpus |
| 4 | Every `views:` slug is routable in the dispatcher. | corpus |
| 5 | Every routable slug is claimed by a `views:` entry or listed in `config/no_help_topic.php` with a reason. | corpus |
| 6 | `module` resolves to a class, `feature` to a catalog key, `capability` appears in `src/`, `tier` is free/standard/pro. | corpus |
| 7 | No `](?page=tt-docs&topic=` — use the relative `<slug>.md` form. | corpus |
| 8 | No `](?page=` outside an admin-only topic. | corpus |
| 9 | Every `](<slug>.md)` cross-reference resolves. | corpus |
| 10 | Every `](?tt_view=<slug>)` names a routable slug. | corpus |
| 11 | No version stamp or `#NNNN` **added** to a `user` / `player` / `parent` topic. | diff |
| 12 | No "coming soon" / "planned for" / "in a future release" **added**. | diff |
| 15 | Every file is valid UTF-8. | corpus |

Rules 13-14 (every reader-facing topic has an `nl_NL` twin with translated `title` and `summary`) land with the translation pass in #2550 — enforcing them before the corpus can pass would just mean an exempt label on every PR.

Rule 15 is the one that is not about documentation. A sweep over the corpus in #2546 used `preg_split('/\R/')` without the `u` modifier; outside Unicode mode PCRE treats `\x85` as a line break, and `\x85` is the third byte of `★` (`E2 98 85`). Two files shipped with fifteen lines of replacement characters, through every other gate in the repo. **When scripting an edit across the corpus, split on `"\n"` after normalising CRLF — never `\R` without `/u`.**

**Exemption**: label the PR `docs-lint-exempt`. The gate is skipped entirely, so the exemption is visible in review rather than buried in a file.

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

### The translation catalogue duplicates itself when you merge `main` (#2765)

This one bites without warning, so read it before it does.

`.gitattributes` gives `languages/*.po` the **union** merge driver. That is what stops parallel branches conflicting on the catalogue — and the cost is that a union merge takes **both** sides of every hunk. When `i18n-sync` has relocated and rewrapped your appended entries into their sorted position on `main`, merging `main` back into your branch leaves you holding the appended copy **and** the relocated one. **Git reports no conflict**, because as far as the driver is concerned nothing disagreed.

It happened four times in one day, on four separate branches. The clean case is the tell: the damage depends on whether `main` has relocated an entry your branch also carries — timing, not anything you did.

Why it matters: duplicate `msgid`s are what `msgfmt` refuses, so one reaching `main` can break the `.mo` compile for every locale. The quieter case is worse — when the two copies disagree, one translated and one emptied by a `msgmerge` that lost the string, gettext takes the first, and a Dutch string silently reverts to English with no error anywhere.

**`po-duplicate-lint.yml` fails a PR that duplicates a msgid the base does not**, and names the strings. Run the same check locally:

```
php tools/check-po-duplicates.php
```

Pure PHP — no `msgfmt`, no `jq`, neither of which is installed on the maintainer's machine. It reads `msgctxt`, so a contextual entry sharing a `msgid` with its plain twin is not reported (a naive checker calls 21 of those a duplicate on `main` today), and it ignores obsolete `#~` blocks the way gettext does.

**When it fires, rebuild rather than hand-delete:**

```
git checkout origin/main -- languages/talenttrack-nl_NL.po
# re-add ONLY the strings this branch introduces, with their Dutch msgstr
php tools/check-po-duplicates.php
```

Deleting one of each pair by hand also works, but the two copies can disagree and you cannot tell which one gettext would have taken. Rebuilding removes the coin flip.

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
