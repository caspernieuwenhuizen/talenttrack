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
5. Commit: `git add . && git commit -m "<type>: <title> (#NNNN)"`
6. Push: `git push origin feature/NNNN-<slug>`
7. Open a PR: `gh pr create --fill`

Ask "show me what you did" before saying "ship it" if you want to review in the Claude Code chat. Or check the PR on github.com.

To ship: "merge the PR and delete the branch". Claude Code runs `gh pr merge --squash --delete-branch`.

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
