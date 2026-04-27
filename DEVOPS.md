# DevOps workflow

This document describes how work moves from idea to shipped release. All automation described here already exists in .github/workflows/release.yml — this guide covers how to drive it.

## Stages

**Idea → Spec → Build → Release → Deploy**

## Idea capture

Any rough thought goes in `ideas/NNNN-<type>-<slug>.md`. One file per idea. First heading is the title. Rest is freeform.

**Filename:** `NNNN-<type>-<slug>.md` — e.g. `0012-bug-session-save-fails-on-empty-date.md`

- `NNNN` — 4-digit zero-padded global ID. Assign the next unused (highest existing + 1). Permanent, never renumbered.
- `<type>` — `feat` | `bug` | `epic` | `question` | `needs-triage`. Matches the marker inside the file.
- `<slug>` — short kebab-case description.

**Type marker at the top of the file** (must match the filename type segment):

- `<!-- type: feat -->` — standard spec-able feature
- `<!-- type: bug -->` — a bug that needs investigation
- `<!-- type: epic -->` — too big for one sprint
- `<!-- type: question -->` — a question, not an idea
- `<!-- type: needs-triage -->` — unclear, more info needed

Reference ideas in chat as `#0012` (or `#12` — Claude Code matches on the number). Full spec in `ideas/README.md`.

Keep `TRIAGE.md` at repo root updated with the 3–7 ideas most relevant right now.

## Picking up ideas by type

Tell Claude Code in any of these forms:

  pick up the next 4 bugs
  grab 2 feats
  shape the next epic

Claude Code will:
1. List `ideas/*-<type>-*.md` sorted by ID ascending
2. Skip any that already have a matching file in `specs/` (same ID)
3. Take the first N
4. For each: confirm with you, then start shaping or implementing (your call per item)

"Next" means lowest ID first — simple FIFO. Rearrange by editing `TRIAGE.md` if you want a different order for a specific batch.

## Shaping — idea → spec

Open Claude Code at repo root. Ask:

  help me turn #NNNN into a proper spec — ask me whatever you need

Claude Code reads `ideas/NNNN-*.md`, asks clarifying questions, iterates with you until the spec is clear. When done, Claude Code:

1. Writes the shaped spec to `specs/NNNN-<type>-<slug>.md` using the structure in `specs/README.md`. ID is preserved from the idea.
2. Removes the original from `ideas/` (the spec is now the source of truth; git history preserves provenance).
3. Optionally: opens a GitHub issue referencing the spec path (ask Claude Code to do this if you want it).

## Build — spec → feature branch → PR

In Claude Code:

  implement #NNNN

Claude Code will:
1. Create a feature branch: `git checkout -b feature/NNNN-<slug>`
2. Implement the code changes, editing files directly in the repo
3. Run `composer install` and `vendor/bin/phpstan analyse` if those tools exist
4. Run `find src -name "*.php" -print0 | xargs -0 -n1 php -l` for syntax check
5. **Update translations and docs in the same PR** (see Ship-along rules below)
6. Commit: `git add . && git commit -m "<type>: <title> (#NNNN)"`
7. Push: `git push origin feature/NNNN-<slug>`
8. Open a PR: `gh pr create --fill`

Ask "show me what you did" before saying "ship it" if you want to review in the Claude Code chat. Or check the PR on github.com.

To ship: "merge the PR and delete the branch". Claude Code runs `gh pr merge --squash --delete-branch`.

### Ship-along rules — non-optional, part of every feature PR

These three are always bundled with the code change, not chased later.

**1. Reference data is translatable and extensible by default.**
Never hardcode a list of user-facing values. When you need a finite set the admin might edit, translate, or extend, use a `tt_lookups` lookup type (or `tt_config` for singletons). Strings that appear in the UI go through `__('...', 'talenttrack')`. If you're about to type a PHP `const ARR = ['Foo','Bar'];` for something a user might see, stop and add a lookup instead.

**2. Translations updated in the same PR.**
Any change that adds, renames, or removes a `__()` / `_e()` / `esc_html__()` string must also edit `languages/talenttrack-nl_NL.po`. Dutch msgstr filled in. If the repo has a `.pot` regeneration script, run it; otherwise edit the `.po` by hand. `.mo` recompile noted in the PR description if `msgfmt` isn't available locally.

**3. Documentation updated in the same PR.**
If a change alters user-visible behaviour — new field, moved button, changed wording, new admin page, new workflow — the relevant topic in `docs/<slug>.md` **and** its Dutch translation `docs/nl_NL/<slug>.md` must be updated alongside the code. If there's no matching topic yet, add the topic entry in `src/Modules/Documentation/HelpTopics.php` and create both files. `CHANGES.md` is a separate artifact (per-release); the docs are the *current* state of the feature.

**4. SEQUENCE.md kept current in the release commit.**
When a release ships work on any item referenced by number or name in `SEQUENCE.md`, the release commit must update SEQUENCE.md alongside the version bump. The update shows:

- What was done, tied to the release tag (short bullets).
- Phase status moved forward where applicable (Phase 0 → Shipped, In progress → Done).
- Remaining work phrased as "still to do", not a re-listing of everything ever planned.
- Time — original estimate vs actual hours where known. Columns grow into a calibration tool over time.

Default behaviour on every release that touches SEQUENCE.md-referenced work, not a conscious step.

PR review check: if a reviewer can't point at the `.po` lines and the `docs/*` lines, it's not done. And a release that leaves SEQUENCE.md stale isn't done either.

## Coding style — no AI fingerprints

Best-practice code quality is non-negotiable: docblocks for public APIs, sensible naming, type hints, tests where they earn their keep. *Showing* that the code was machine-generated is not part of "best practice" — it's noise. Every PR should read like a human wrote it carefully.

Concretely, drop these LLM signatures:

- **Commit trailers** — no `Co-Authored-By: Claude …` lines. Just the change summary and the why.
- **PR bodies** — no `🤖 Generated with [Claude Code]` footer. Section structure (Summary / Test plan) is fine when it serves the reader; cut whatever doesn't.
- **Comment banners** — no Unicode box-drawing (`/* ═══════ Foo ═══════ */`). When a heading genuinely helps, use plain ASCII (`// ===== Foo =====`); usually you don't need one at all.
- **Class docblocks** — keep them short (3–5 lines for most classes). Multi-paragraph "v2.10.1: …; v2.10.0: …; v2.9.0: …" version-history recaps belong in `CHANGES.md`, not the source.
- **Inline comments** — keep the *why* (constraint, gotcha, non-obvious invariant). Drop the over-explanatory *what* — the code says what it does. "Idempotent: every step is a no-op when the target state is already reached" is the kind of line to delete; "Refuse to drop if rows still reference this table" is the kind to keep.
- **Method docblocks** — 2–4 lines for public methods. If the method's name + signature + parameter types already tell the story, a docblock is optional.

When in doubt: would a careful senior engineer write this? If the answer is "they'd compress it" or "they'd skip it", compress or skip.

## Plugin constants in wp-config.php

Some features rely on secrets that must never live in the database (DB values leak into backups, staging clones, and migration exports). Add these to `wp-config.php` on the server, not via wp-admin.

| Constant | Required for | Notes |
| --- | --- | --- |
| `TT_GITHUB_TOKEN` | #0009 Development management — promoting ideas to GitHub | Fine-grained PAT scoped to the talenttrack repo with `Contents: Read & write`. Until set, the **Approve & promote** button is disabled and a banner shows on the Approval queue. Submitting and refining still work. |
| `TT_IDEAS_REPO` | #0009 (optional) | Override the target repo, e.g. `myorg/myrepo`. Defaults to `caspernieuwenhuizen/talenttrack`. |
| `TT_IDEAS_BASE_BRANCH` | #0009 (optional) | Override the branch the promoter commits to. Defaults to `main`. The branch must not have protection enabled — if it does, the GitHub API `PUT` returns 422 and a fallback PR-flow would be needed (not currently implemented). |

Example block at the bottom of `wp-config.php`:

```php
define('TT_GITHUB_TOKEN', 'github_pat_...');
// define('TT_IDEAS_REPO',        'caspernieuwenhuizen/talenttrack'); // optional
// define('TT_IDEAS_BASE_BRANCH', 'main'); // optional
```

The fine-grained PAT creation page is at https://github.com/settings/personal-access-tokens/new. Pick **Only select repositories** → talenttrack, **Repository permissions** → Contents: Read & write. Nothing else.

## Release — tag a version, automation takes over

After merging, in Claude Code:

  cut a release <version>

where `<version>` is the new semver tag (e.g. 3.1.0). Claude Code will:

1. Bump Version in talenttrack.php to 3.1.0
2. Bump Stable tag in readme.txt to 3.1.0
3. Prepend a new section to CHANGES.md for 3.1.0 summarising the merged PRs since the previous tag
4. Commit: `chore: release v3.1.0`
5. Push to main
6. Tag: `git tag v3.1.0 && git push origin v3.1.0`

**Tag format is `vX.Y.Z` (NOT `v.X.Y.Z` — the stray dot in v.2.21.0 and v.2.22.0 tags is a formatting accident that confuses some tooling. Don't repeat it.)**

The existing GitHub Action (.github/workflows/release.yml) fires on the tag push:
- Runs PHP syntax lint
- Runs PHPStan static analysis  
- Builds talenttrack.zip (folder-in-zip shaped correctly for PUC)
- Creates a GitHub Release with auto-generated release notes
- Attaches talenttrack.zip as a release asset

You do nothing. ~30 seconds after the tag push, the release exists.

## Deploy — WordPress picks it up

Your running WordPress install has the Plugin Update Checker (PUC) plugin that polls GitHub.

When a new release exists, PUC caches on a 12-hour timer by default. To force an immediate check:

  wp-admin → Dashboard → Updates → Check again

Or add `?puc_check_now=1&puc_slug=talenttrack` to any wp-admin URL.

Once the update appears in wp-admin → Plugins, click Update. The TalentTrack v3.0.0+ SchemaStatus banner catches any migration needed — click "Run migrations now" if shown. Done.

## What to do when something goes wrong

- Workflow failed: github.com → Actions tab → click the failed run → read the log
- PR can't be merged: conflicts on main. Tell Claude Code to rebase the branch.
- PUC not seeing the new release: see DEPLOY_DEBUG.md

## When to ask Claude for help in chat vs in Claude Code

- **chat.claude.ai (this chat)**: spec shaping for epics where you want the long-form thinking, architectural decisions, cross-sprint planning, when you're away from your laptop and want to think out loud
- **Claude Code**: any action on the repo, implementing specs, fixing bugs, cutting releases, diagnosing workflow failures
