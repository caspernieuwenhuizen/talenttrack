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

A second, much smaller gate runs alongside it. `conflict-marker-lint.yml` runs
`tools/check-conflict-markers.php` on **every** PR — no path filter, because a
marker committed in `src/` on a PR that touches no documentation would otherwise
never be scanned:

```
php tools/check-conflict-markers.php
```

It fails when a git conflict marker has been committed as literal file content.
That is not a cosmetic problem: git cannot three-way-merge a file that already
contains a marker, so every later branch touching that file fails with
`could not parse conflict hunks` until somebody removes it — which is exactly
what `docs/rest-api.md` did for three days (#2889).

It looks for `<<<<<<<` and `>>>>>>>` at the start of a line and never for a bare
`=======` on its own. A run of equals signs under a line of text is a Markdown
setext H1 underline, and a seven-character heading gets a seven-character
underline, so no length test can tell the two apart. Since git always writes all
three markers together, finding either angle marker is enough — `=======` is
reported only as context inside a file that already tripped one.

A third small gate sits beside those two. `changelog-snippet-check.yml` runs
`tools/check-changelog-snippets.php` over `changelog.d/`:

```
php tools/check-changelog-snippets.php
```

It reads each release-note snippet rather than only counting it: the first
non-empty line must be an `# ` heading, `Bump:` must come after it and appear
at most once, and there must be a body. Without those, `tools/release.ps1`
takes the first line as the entry title — so a snippet opening with
`Bump: minor` produced a changelog entry titled "Bump: minor" that then bumped
the patch version, which is how seven malformed snippets reached the v4.108.0
release diff (#3043). A title with no `(#123)` is a warning, not a failure.
The full shape is in `changelog.d/README.md`.

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
| 13 | Every `user` / `player` / `parent` / `admin` topic has an `nl_NL` twin. | corpus |
| 14 | The twin's `title` and `summary` are translated; its `group`, `audience` and `order` match the English. | corpus |
| 15 | Every file is valid UTF-8. | corpus |

Rules 4, 5 and 10 all turn on "routable", and that set is derived from `DashboardShortcode` by `tools/lib/routable-slugs.php` — one deriver, shared with `check-mobile-classes.php` and `check-tile-routes.php`. It resolves three shapes: a literal `case '<slug>':`, a constant arm such as `case SomeView::SLUG:` (by reading the constant out of the class the arm names), and the `$tt_view_param === …` comparisons that route pre-auth screens above the dispatch chain. Anything it cannot follow statically is reported as a note rather than dropped.

Write a dispatcher arm whichever way reads better; both are visible to every gate. Before this was shared, each gate derived the set for itself and they disagreed by eight live routes, so a constant arm was invisible to the help-topic rule — an arm written the better way exempted itself from the requirement.

Rules 13-14 were held back until the translation pass brought the corpus to parity — enforcing them earlier would have meant an exempt label on every PR, which is the same as no rule.

`group`, `audience` and `order` must be *identical* in both files, because they key off one registry entry; only `title` and `summary` are translated. A Dutch `title` identical to the English one is treated as untranslated, since that is nearly always what it means. For the handful of words that genuinely do not change — *Modules* — add them to `$identicalByDesign` in `tools/check-docs.php`, so each one is a decision somebody made rather than a hole in the rule.

`audience: [dev]` topics need no twin: dev docs are English-only by design, and the rule skips them.

Rule 15 is the one that is not about documentation. A sweep over the corpus in #2546 used `preg_split('/\R/')` without the `u` modifier; outside Unicode mode PCRE treats `\x85` as a line break, and `\x85` is the third byte of `★` (`E2 98 85`). Two files shipped with fifteen lines of replacement characters, through every other gate in the repo. **When scripting an edit across the corpus, split on `"\n"` after normalising CRLF — never `\R` without `/u`.**

**Exemption**: label the PR `docs-lint-exempt`. The gate is skipped entirely, so the exemption is visible in review rather than buried in a file.

CI rejects PRs that add a new doc without front matter.

## 2. Translations

The translation discipline is per audience:

- `audience` including `user`, `admin`, `player`, or `parent` → translation in `docs/nl_NL/<slug>.md` is **required in the same PR**, front matter included.
- `audience: [dev]` only → no Dutch translation. Dev docs are English-only by design — that's the working language for plugin extenders regardless of locale.

If a doc's audience changes from `dev` to anything else, add the Dutch translation in that PR. If it changes the other way, remove the Dutch counterpart in the same PR.

### Translations go in a fragment, not in the catalogue (#3863)

**A PR that adds a translatable string does not edit `languages/talenttrack-*.po`.** It drops one brand-new file:

```
languages/pending/<issue>-<short-slug>.po
```

carrying only the entries that PR introduces, each with its Dutch `msgstr`. A file that exists on exactly one branch cannot conflict with another branch's, which is the whole point — the catalogue was the only file parallel branches ever collided on, and not because the work overlapped. It never did.

```po
#: src/Modules/Teams/Frontend/FrontendTeamDetailView.php:77
msgid "Back to squad"
msgstr "Terug naar de selectie"
```

Rules, all enforced by `php tools/consolidate-translations.php --check`:

- One file per issue, named `<issue>-<slug>.po`. For a locale other than Dutch, `<issue>-<slug>.<locale>.po`, or a `"Language: de_DE\n"` header inside the fragment.
- Every entry carries a non-empty `msgstr`. A fragment exists to hold the translation.
- No obsolete `#~` entries — retiring a string is the catalogue regeneration's job.
- No duplicate `(msgctxt, msgid)` pair, in the fragment or against the catalogue.

A one-word label still needs `_x()` with a context, carried through into the fragment as a `msgctxt`: a short `msgid` inherits the wrong sense from whatever the translator saw first, which is how `"Pass"` became `"Geslaagd"` on a football screen.

**Editing an existing translation** is a catalogue edit — make it in `languages/talenttrack-nl_NL.po` directly. Fragments are for strings the PR introduces.

**The release folds them in**, once, for the whole batch: `tools/release.ps1` calls `tools/consolidate-translations.php` next to the `changelog.d` step, and `languages/pending/` is empty again afterwards. Run it by hand to see what it would do:

```
php tools/consolidate-translations.php --dry-run
```

It keys entries the way gettext does — on the `(msgctxt, msgid)` pair, with wrapped literals **joined by content first**, so `"ab" "cd"` and `"abcd"` are one entry. That detail looks trivial and is not: a repair script that concatenated the quoted literals verbatim reported four entries as missing from `main` that were already there, and appending them produced three duplicate msgids. The consolidator fills in the `msgstr` of a msgid the catalogue already carries untranslated, appends the rest above the obsolete `#~` block, and **refuses to write anything at all** when the result would carry a duplicate msgid, when a fragment collides with an obsolete `#~` entry, or when two fragments translate the same string differently.

Full rules in `languages/pending/README.md`.

### i18n CI workflows (PR-time + weekly)

Translation drift is gated and reported automatically by two workflows under `.github/workflows/`:

- **`i18n-pr-check.yml`** runs on every PR that touches `src/**/*.php` or `languages/**`. It asks one question: **does every new translatable string on this PR's added lines have an entry, with a non-empty `msgstr`, in the PR's fragment?** The failure names the msgids and the `file:line` that introduced them. It also validates the fragments themselves (`consolidate-translations.php --check`, `check-po-duplicates.php`), and fails on an obvious hardcoded English leak (`wp_die("Capital…")`, `WP_Error('code', "Capital…")`, `sprintf("Capital…")` — wrap those in `__()` / `_e()` / `esc_html__()`). Run the same check before pushing, with nothing installed but PHP:

  ```
  php tools/check-i18n-fragment.php
  ```

  **Since #3864** this replaced a gate that regenerated the `.pot` on both sides and diffed untranslated counts. It was accurate and hard to act on: `Empty-msgstr delta: 3 (baseline=9, head=12)` alongside `New untranslated msgids: 0` are two true statements whose combination takes real effort to read as "you added three strings and translated none of them". It also needed `wp-cli`, `gettext` and a second worktree to produce a number nobody could reproduce locally.

  A string that is already untranslated on `main` is reported as a **note** and does not fail the PR — that drift predates you, and failing on it only teaches people to reach for the override label.
- **`i18n-drift-report.yml`** runs every Monday 06:00 UTC and on manual dispatch. It refreshes the `.pot`, `msgmerge`s every locale `.po`, counts empty + fuzzy entries, and writes the result into an auto-managed tracking issue titled `i18n drift report`. The body lists per-locale counts, the top 10 source files driving the `nl_NL` gap, and PRs merged in the last 7 days that touched PHP under `src/`.

**Override**: when a PR genuinely needs to ship new untranslated msgids (typically because a feature lands faster than the translator), label the PR `i18n-drift-acceptable`. The PR-check passes; the weekly drift report still records the new entries.

Neither workflow commits anything — `i18n-sync.yml` (structural `.pot` regeneration + `msgmerge`) is the only workflow that writes to `languages/`.

### Duplicate msgids, and why the catalogue is no longer union-merged (#2765, #3864)

`languages/*.po` used to carry the **union** merge driver, so parallel branches would not conflict on the catalogue. It is gone, and it is worth knowing why before anyone adds it back.

A union merge takes **both** sides of every hunk, which is safe only for append-only text. A `.po` is not append-only: the catalogue regeneration **relocates** and re-wraps entries, so merging `main` into a branch that had appended an entry left you holding the appended copy **and** the relocated one. **Git reported no conflict**, because as far as the driver was concerned nothing disagreed. It happened four times in one day, on four separate branches, and the clean case is the tell — the damage depended on whether `main` had relocated an entry your branch also carried, which is timing, not anything you did.

It also did not buy the conflict reduction it was added for: branches that `git merge-tree` resolved cleanly locally were still reported `CONFLICTING` by GitHub on the `.po`, because the driver runs on a developer's machine and not on the server-side merge that actually gates a PR.

So the collision surface was removed instead: a PR drops a fragment under `languages/pending/` and never touches the catalogue, and the release folds the fragments in with nothing else in flight.

Duplicates still matter, and the check stays. Duplicate `msgid`s are what `msgfmt` refuses, so one reaching `main` can break the `.mo` compile for every locale. The quieter case is worse — when the two copies disagree, one translated and one emptied by a merge that lost the string, gettext takes the first, and a Dutch string silently reverts to English with no error anywhere.

**`po-duplicate-lint.yml` fails a PR that duplicates a msgid the base does not**, and names the strings. Run the same check locally:

```
php tools/check-po-duplicates.php
```

Pure PHP — no `msgfmt`, no `jq`, neither of which is installed on the maintainer's machine.

#### What it catches, and what it does not (#3205)

It reported OK on four branches in one drain that then failed `i18n-pr-check` on a duplicate `msgmerge` caught. Two people hit it independently, which is what makes it worth writing down: a local check that clears a file CI then rejects teaches everyone that CI is flaky.

**Three things it looks for.**

| It catches | Because |
| --- | --- |
| The same `(msgctxt, msgid)` pair twice in the live catalogue | The original job. A `msgid` shared between a plain entry and a `msgctxt` one is **not** a duplicate — that is what contexts are for, and ignoring `msgctxt` calls ~27 legitimate pairs on `main` a duplicate. |
| The same `msgid` once wrapped and once not | `msgmerge` on `main` re-wraps a long `msgid` across quoted fragments while your branch still carries it on one line. Identical to gettext, different raw text, so comparing lines rather than values misses it. Fragments are concatenated before the key is built. |
| An entry glued onto the tail of the one before it | A union merge can append an entry with no blank line between. Anything reading the catalogue in blocks sees one entry where there are two, so a re-apply that looks correct silently carries both across. Entries are closed by the next `msgid`, never by a blank line — and the glue itself is reported with its line number, because it is the fingerprint of the merge even before it duplicates anything. |
| The same `msgid` twice among the obsolete `#~` entries | Obsolete entries look inert and are not: `msgmerge` promotes one back to live the moment its string reappears in the `.pot`, so two obsolete copies come back as two live copies in a commit nobody wrote by hand. |

**What it deliberately does not report:**

- A **live entry with an obsolete twin.** That is the normal state of a catalogue whose string came back. The two namespaces are counted separately for exactly this reason.
- **`msgid_plural` and `msgstr[n]`.** They continue their own entry, so they are not glue; treating them as such reports every plural in the file.
- **Anything the base branch already had.** The check is a delta against `origin/main` (override with `--base=`), so a pre-existing duplicate is somebody else's PR to fix, not yours.

Each of those cases has a fixture under `tests/fixtures/po/` and an assertion in `tests/php/PoDuplicateCheckTest.php`, so the blind spots cannot quietly come back. `clean.po` is the important one: it holds every shape a stricter check would wrongly flag.

**The verdict is absolute; only the explanation is comparative.** A duplicated `(msgctxt, msgid)` pair fails the check wherever it came from, because `msgfmt` refuses the file either way. The output still says how many you introduced and how many were already on `main`, which is useful for knowing whose fix it is — but it is not what decides the exit code, and a passing run never reports a duplicate count.

That distinction is the point: the check used to fail only on duplicates `main` did not already have, so when `main` itself went red every branch inherited the pair, found nothing "introduced", and reported OK. On a machine without `msgfmt` — the normal case here — that OK was the only signal available. If this check passes, the catalogue compiles; you do not need `msgfmt` locally to trust it.

**It covers the fragments too**, so a fragment that carries the same `(msgctxt, msgid)` twice fails in the PR that wrote it rather than blocking a whole batch at release time.

**When it fires on a fragment**, delete the second copy — a fragment states the strings one PR introduces, so the pair is always a mistake.

**When it fires on a catalogue**, your branch is carrying a hand-merged copy. Rebuild rather than hand-deleting lines:

```
git checkout origin/main -- languages/talenttrack-nl_NL.po
# put ONLY the strings this branch introduces in
# languages/pending/<issue>-<slug>.po, with their Dutch msgstr
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

Topics render through the plugin's one markdown renderer (`Shared\Content\MarkdownRenderer`), which the course reader shares. Pipe tables and fence language tags both render in-product; a wide table scrolls inside its own box rather than pushing the page sideways. A list item may wrap across lines — an indented continuation belongs to the item above it, so emphasis can span a line break.

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

### What the two suites run on (#3090)

The two heavy gates — `php-tests.yml` (PHPUnit) and `e2e.yml` (Playwright) — run on **every pull request, whatever its base branch**, and on every push to `main`.

That distinction used to matter, and getting it wrong was expensive. Both workflows carried `branches: [main]` under `pull_request`, so a PR opened against a feature branch ran **neither suite** — and its checks column still went green, because the other dozen lint gates have no such filter. A stacked PR could be reviewed, ticked and merged without either suite ever having executed; the failure only surfaced later, on the push to `main`, attributed to whoever merged last.

`pull_request` only fires for pull requests in the first place, so that filter's entire effect was to skip the case that most needed covering. It is gone. A list of feature-branch glob patterns was considered and rejected for the same reason: it would keep a filter whose only remaining job is to be incomplete, and it would fail silently the first time somebody stacked onto a base the pattern missed — the same bug, one branch name later.

The `push: branches: [main]` triggers are unchanged. They are the post-merge net, and there is no reason to run them on every branch push.

**So: a green column on a PR now means the suites actually ran.** Before this change it did not, and the difference was invisible from the PR page. If you are reading a PR opened before it landed, check the checks list for "PHPUnit (wp-env)" and "Playwright (Chromium)" by name rather than trusting the overall tick.

The cost is CI minutes on stacked PRs, and it is dominated by wp-env startup rather than by testing — which the next section is about.

### Why wp-env startup is ~90s, and what has already been ruled out (#2417)

`Start wp-env` is the single largest cost in CI — around 90s of a ~150s PHPUnit job, so roughly 60% setup against 15% testing — and both gating workflows pay it.

**Two fixes were tried and both measured worse than doing nothing.** They are recorded here because they are the two anybody would reach for first, and each cost a CI cycle to disprove.

**1. Pre-pulling the container images: +25s net.** A step pulling `wordpress`, `wordpress:cli` and `mariadb:lts` ahead of wp-env cost 28s and took nothing at all off wp-env's own 92s, because wp-env resolves and pulls its own tags regardless of what is already local under those names. Image pull is about 28s of the startup; it is real, but it is not separately attackable.

**2. Caching the WordPress source tree: slower, not faster.** `.wp-env.json` used to set `"core": "WordPress/WordPress"`, so wp-env git-cloned the WordPress repository into `~/.wp-env/<hash>/WordPress` rather than using the released image. On a CI runner that directory is cold every run, which makes "cache the clone" look obvious. Measured on `main`: cold startup ~90s, warm startup with the 391MB tree restored **103s and 129s**. Restoring the tree costs more than cloning it, and wp-env does its own work either way.

What that experiment *did* establish is that everything wp-env does before touching a container collapses once the tree is already on disk — 102s to 44s on the same commit. So the clone is expensive; it is *storing* it that is not worth it. `.wp-env.json` now sets **`"core": null`**, which drops the clone instead of caching it: wp-env uses the released WordPress image, which it pulls either way.

Measured over four cold `main` runs against two cold runs of the change, per workflow:

| workflow | before | after | change |
| --- | --- | --- | --- |
| `php-tests.yml` | 99, 100, 100, 102 (mean 100s) | 88, 91 (mean 90s) | −10s (−10%) |
| `e2e.yml` | 84, 93, 94, 101 (mean 93s) | 83, 98 (mean 91s) | no measurable effect |

Read that carefully, because it is smaller than the 44s number invites you to expect: the PHPUnit gate improves clearly — `main` clusters at 99–102 and the change sits outside that spread — and the E2E gate does not move at all, its own runs ranging 84–101 either way. Whatever dominates E2E's startup variance, it is not the clone.

The other half of the change is correctness rather than cost. Setting `core` to a GitHub repository was never a decision — it arrived with the Playwright scaffold in #12 — and it had both gating suites asserting against WordPress **trunk** rather than a release, which is a moving target for a gate.

An earlier variant of that experiment cached `~/.wp-env` **wholesale** and appeared to cut startup to 44s. It had not: that directory also holds wp-env's own generated docker-compose project, so restoring it convinced wp-env the environment was already up while the containers and database volume were not, and the job died a step later on `Error establishing a database connection`. **A startup step that returns quickly is not evidence. Check the job went green before believing a timing.**

That attempt also failed silently in a second way worth knowing: pointed at `~/.wp-env`, which is only wp-env's default on some platforms and versions, `actions/cache` reported `Path Validation Error: Path(s) specified in the action for caching do(es) not exist` — a **warning**, on a green job, having cached nothing. Both jobs now pin `WP_ENV_HOME` so the timing marker below measures the directory wp-env actually uses.

**3. Skipping the `dev` instance: no supported way to do it.** `wp-env start` boots both a `dev` and a `tests` environment while both jobs only ever use the tests one — `php-tests.yml` runs `wp-env run tests-cli` and Playwright's `baseURL` is `localhost:8889`. Nothing on port 8888 is touched by either. Halving the containers looked like the obvious remaining win.

`@wordpress/env` v10 does not expose it. `wp-env start` accepts `--debug`, `--update`, `--runtime`, `--xdebug`, `--spx`, `--scripts` and `--auto-port`, and nothing else. The `testsEnvironment` config key that once controlled this is documented as *deprecated*, and its replacement — separate config files via `--config` — "still starts a complete development environment". Reaching into wp-env's generated docker-compose project to start a subset of services by hand would skip the WordPress install and configuration that `wp-env start` performs, which is the part that costs the time in the first place.

So this one is ruled out on availability rather than on a number: there is nothing to measure until wp-env offers a switch. If it ever does, the `TT_TIMING` markers below are what will price it.

**What is left.** After the `core` change the startup is whatever the released image plus the WordPress install and DB seeding costs. `wp-env start --runtime=playground` is the one large untried lever — WordPress Playground boots a WASM runtime instead of containers — but whether PHPUnit and Playwright can run against it at all is an open question, not a tuning change.

Both workflows print timing markers so the cost stays visible instead of being reconstructed with a stopwatch. Grep a run's log for `TT_TIMING`:

```
TT_TIMING wp_env_dir_size=…       # what was on disk before startup
TT_TIMING wp_env_start_seconds=…  # the whole of wp-env's startup
```

### Mandatory: a smoke test for every new REST endpoint

When you add a `register_rest_route(...)`, add a smoke test for it in `tests/php/` in the same PR. The bar is low — assert the **status code** and the **envelope shape** for at least the **denial path** (an unauthorised caller gets the expected 401/403) and the happy path. This is the cheapest insurance against the authorization-coverage bug class; full-content assertions are not required. (Trivial copy-only changes to an existing endpoint don't.)

**Enforced in CI (#1388).** `.github/workflows/rest-test-coverage.yml` (script: `scripts/rest-test-coverage.php`) is a diff-based, forward-only gate: a PR whose diff ADDS a `register_rest_route(` line under `src/` must, in the same diff, add or modify a file under `tests/php/` — otherwise the gate fails and names the offending controller(s). It grandfathers the existing routes (only diff-added registrations are in scope) and is coarse by design: it checks that the PR *touches* the PHP test dir, not that a specific test name matches the route — the reviewer confirms the test actually covers the new route. For the rare PR that genuinely needs no new test (a route moved verbatim between files, a trivial copy-only change), apply the `rest-test-exempt` label and the gate skips. The Tier 2 smoke pattern to copy lives in `tests/php/RestSmokeTest.php`.

### Mandatory: a surface that takes a record id checks that record (#4004)

Every capability in this plugin is club-wide. `tt_view_activities` says the caller reads activities; it never says *whose*. So a surface that loads a record by id and asks only a capability has answered a different question than the one it needed — and six fixes inside a fortnight were that single shape: an evaluation's archived branch (#3987), a goal opened by id (#3998), the print routers and file exporters (#4000), the detail and edit views (#4001), the REST routes taking a record id (#4002) and the wp-admin pages and bulk actions (#4003).

`tools/check-record-scope.php` (workflow: `record-scope-lint.yml`, parser: `tools/lib/record-scope.php`) finds by-id surfaces three ways and asks each one whether it performs a per-record check:

- **REST** — a `register_rest_route()` whose pattern carries a record-id parameter (`(?P<id>…)`, `(?P<player_id>…)`, …). The `callback` and the `permission_callback` are resolved together and judged as **one unit**: they are two halves of one decision and the check may sit in either. Asking them separately called about fifteen already-checked routes unchecked in the audit this came out of.
- **Views** — a method under `src/**/Frontend/` that reads an id out of the request and loads a record with it.
- **Handlers** — an `admin_post_*`, `wp_ajax_*` or `template_redirect` target doing the same, plus every `Exporters/*::collect()` reading the id it was asked for. `ExportService::run()` treats `collect()` as the authoritative check, so that is where an exporter's belongs.

**What passes.** A call — in the resolved method, or up to three same-class calls deep — to a name in [`config/record_scope_checks.php`](../config/record_scope_checks.php): `canViewPlayer`, `canEditPlayer`, `AllTeamsScope::canReadTeam`, `ActivityTeamScope::covers*`, `GoalAccess::may*`, `PdpAccess::canSeeFile` and the rest. The depth is not a round number — `set_status()` asks `refuseUnlessActivityWritable()`, which asks `refuseUnlessTeamWritable()`, which asks `gridAllowedTeamIds()`, and that is where `get_teams_for_coach()` finally appears.

**Adding a name to that file is a reviewable act, and that is the point.** The gate cannot tell a real check from a plausible-looking call; the list is where somebody decides that a given function actually answers "may you do this to *this* record". A capability roll-up or a feature toggle does not belong there however convenient it would be.

A `get_current_user_id()`-derived value reaching the loader also passes without a marker: the WHERE then names the caller, so it cannot return anybody else's row. That is a per-record check written as a query rather than as an `if` — `FrontendMyGoalsView` is the model.

**How an exception declares itself.** A block comment inside the method, following the `both-kinds-ok` precedent in the attendance gate:

```php
/* record-scope-ok: no player dimension */
```

Three reasons are recognised and the gate accepts no others:

| Reason | When |
| --- | --- |
| `no player dimension` | A lookup, config, vocabulary. There is no player or team the record belongs to, so there is nothing to narrow to. |
| `caller-scoped query` | The WHERE already names the caller's own player or team. |
| `token is the grant` | A share link or an invitation. The token IS the credential; there is often no session to ask about. |

A fourth reason is a decision about the access model, not a typo: it is a change to `tools/lib/record-scope.php` and a row in this table.

A marker on a surface that is actually wrong is worse than no gate at all. Do not add one to make a build pass — the comment is a sentence somebody will be held to.

**Grandfathering.** [`config/record_scope_grandfathered.php`](../config/record_scope_grandfathered.php) lists the surfaces that were already unchecked when the gate landed, so it could ship in one PR instead of waiting on a sweep across forty files — the inline-style gate's arrangement (#1389). A line there is a **finding, not a decision**: some want a marker, others are real gaps of the shape above. Either way, fix it or mark it and delete the line. The gate also fails on an entry that no longer names a surface, because an exemption that outlives its subject is how a gate goes quietly blind. When the file is empty, delete the file.

**What it cannot decide.** It catches an absent check, not a check asking the wrong question — a surface calling `canManage()` where `canManageForTeam()` was meant passes it. That is what review is still for.

`record-scope-exempt` on the PR is the escape hatch, following `docs-lint-exempt` and `rest-test-exempt`.

Whichever route the fix takes, a refusal should render what the surface already renders for a record that does not exist. Saying "you may not see this one" confirms the record is there, and a URL with a number in it is not meant to be a way to find out what the academy holds. `docs/access-control.md` carries the user-facing half of that rule.
