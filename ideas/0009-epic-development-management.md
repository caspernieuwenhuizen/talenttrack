<!-- type: epic -->

# Development management — staged ideas, lead-dev approval, auto-commit to ideas/ on GitHub

Raw idea:

Generate, store and refine ideas visually. Add them to development tracks and follow their progress. As a coach or even a player I want to be able to add an idea. As an admin I want to refine and add ideas to a development roadmap. Needs to be connected to the ideas folder.

Refined direction (from shaping chat):

Shape ideas in an in-plugin staging area. When the lead developer approves, the plugin commits the idea as a real `NNNN-<type>-<slug>.md` file directly to the `ideas/` folder on the talenttrack GitHub repo — fully automatic. From there Claude Code picks it up via the existing shaping workflow. The lead-dev approval inside the plugin *is* the gate; there's no second step on GitHub.

## Why this is an epic

New module, new schema, role-scoped submission + refinement UI, visual board, a gated promotion step that talks to the GitHub API, ID allocation that must not collide with the existing `ideas/` / `specs/` sequence, token/secret handling, failure-and-retry paths, and an audit trail. Minimum 3–4 sprints.

## The flow, end to end

1. Coach or player submits a raw idea from their dashboard → lands in staging with status `submitted`.
2. Admin refines it on a visual board: edits title/body, assigns a type (`feat` / `bug` / `epic` / `needs-triage`), tags it to a player or team, optionally drops it onto a development track. Status → `refining` → `ready-for-approval`.
3. Lead developer reviews the `ready-for-approval` queue. Two actions: **reject** (back to refining with a note) or **approve & promote**.
4. On promotion: the plugin calls the GitHub API — allocates the next free `NNNN`, and commits `ideas/NNNN-<type>-<slug>.md` directly to `main`. Status moves through `promoting` → `promoted` (with commit URL stored) or `promotion-failed` (with error + retry).
5. The file is now in the repo's `ideas/` folder and follows the normal Claude-Code-driven idea → spec → shipped path. The plugin's involvement ends here. The lead-dev approval inside the plugin *is* the gate — no second merge step on GitHub.

Progress tracking for *player* development (goals, tracks, completion) is separate — see "Two things in one epic" below.

## Decomposition (rough)

1. **Schema.** `tt_dev_ideas` (id, title, body, slug, type, status, author_user_id, player_id nullable, team_id nullable, track_id nullable, created_at, refined_at, refined_by, promoted_at, promoted_filename, promoted_commit_url, promotion_error, rejection_note). `tt_dev_tracks` (id, name, description, sort_order). Status enum: `submitted` / `refining` / `ready-for-approval` / `rejected` / `promoting` / `promoted` / `promotion-failed` / `in-progress` / `done`.
2. **Submission UI (coach + player).** Frontend form. Title + freeform body. Players can only submit ideas tagged to themselves; coaches can tag any player on a team they coach.
3. **Refinement UI (admin).** Admin page listing staged ideas. Inline edit title/body/slug, set type, assign to track, move status.
4. **Visual board.** Kanban columns keyed off status. Drag-drop moves status. Track view: per-track ordered list, reorderable.
5. **Lead-developer approval queue.** Separate page gated by `tt_promote_idea` capability. Shows only `ready-for-approval`. Actions: approve-and-promote, reject-with-note.
6. **GitHub promoter service.** Dedicated class `src/Modules/Development/GitHubPromoter.php`. See dedicated section below.
7. **Progress tracking for player development.** Once an idea hits `in-progress`, it can spawn a Goal in the existing Goals module. Completion lives in Goals, not here.
8. **Notifications.** Author gets notified on status change. Rejected: yes, with note. Promoted: yes, with assigned `#NNNN` + PR link.

## GitHub promoter — the tricky bit

### Transport

- Uses the GitHub REST API (`api.github.com`) via `wp_remote_*()`. No git binary required on the server — the plugin never shells out, never touches local git, never needs a working tree.
- Repo target is hardcoded to `caspernieuwenhuizen/talenttrack` for now, but put it in a constant so it can be overridden: `TT_IDEAS_REPO` (default: the plugin's own repo, derived from the Plugin URI header).

### Auth

- Fine-grained Personal Access Token, scoped to the one repo, with permission `contents: write`. Nothing else.
- Stored as a constant in `wp-config.php`: `TT_GITHUB_TOKEN`. Never in `wp_options`, never in the DB — DB values leak into backups, staging clones, migration exports.
- Promoter checks `defined('TT_GITHUB_TOKEN')` at runtime. If unset, the "approve & promote" button is disabled with a tooltip explaining what's needed.

### The actual sequence on promotion

1. Fetch `GET /repos/{owner}/{repo}/contents/ideas` → list filenames, extract the `NNNN` prefixes.
2. Same for `specs/` and `specs/shipped/` (404 on `specs/shipped/` is fine, means no shipped yet).
3. Take max `NNNN` across all three + 1 = assigned ID.
4. Assemble filename `NNNN-<type>-<slug>.md` and file body (type marker + title + raw body + any refinement notes).
5. `PUT /repos/{owner}/{repo}/contents/ideas/{filename}` with `branch: main` — commits directly to the default branch. Commit message: `Add idea #NNNN: <title>` with a trailer referencing the staging row + approver.
6. Store returned commit URL on the staging row. Status → `promoted`.

### ID allocation race condition

If someone commits a new `NNNN` to the repo between step 1 and step 5, we could collide. Guards:

- Always promote one at a time (DB lock on the `promoting` status, only one in flight).
- If the `PUT` fails with 422 ("already exists"), refetch and retry once with the new max + 1.
- If it fails a second time, mark `promotion-failed`, surface error in UI, lead dev retries manually.

### Failure modes

| Failure | Behavior |
| --- | --- |
| Token missing | Approve button disabled, admin notice shown |
| Token invalid / 401 | Status `promotion-failed`, error stored, retry button |
| Rate limit hit | Status `promotion-failed` with retry-after timestamp |
| Network timeout | Same — treat as retryable |
| ID collision (race) | One automatic retry, then fail as above |
| Repo/branch permissions revoked | Same as 401 |

The staging row is only marked `promoted` after the commit URL comes back successfully. Nothing gets lost.

### Idempotency

Once `promoted`, the row is frozen. Edits now happen directly on the file in the repo.

## Configuration

Three constants in `wp-config.php`:

```php
define('TT_GITHUB_TOKEN', 'github_pat_...');         // required
define('TT_IDEAS_REPO', 'caspernieuwenhuizen/talenttrack'); // optional, defaults from plugin header
define('TT_IDEAS_BASE_BRANCH', 'main');              // optional, defaults to main
```

Settings page reflects what's configured (token present / absent — never show the value). Documented in DEVOPS.md with a link to the fine-grained PAT creation page.

## Two things in one epic — worth naming

This idea bundles two features:

- **A.** A staging + approval pipeline that auto-commits ideas into the plugin's `ideas/` folder on GitHub. (Developer-workflow tool. Lead developer is gatekeeper. Output: commits on `main`.)
- **B.** A player-development roadmap: tracks, ideas tagged to players, progress. (Product feature. Coaches and players are the users. Output: goals, visible progress.)

One module, two views. The staged-idea object is the same; who owns the outcome differs.

## Open questions

- **Branch protection on `main`.** Direct commit to `main` assumes the default branch doesn't have a ruleset requiring PRs. If it does, the `PUT` will fail with 422 and we'd need to fall back to a branch + PR flow. Worth checking the repo's current settings; if protection is on, either relax it (this is a docs folder, not code) or switch the promoter to the PR variant.
- **`tt_promote_idea` capability** — added to `administrator` by default? Or only assigned manually to the lead developer's user? Manual is safer.
- **Multi-maintainer future.** Fine-grained PAT works for one person. If more than one lead dev ever needs to approve, we'd either share the token (bad) or switch to a GitHub App. Not worth building for day one, just noting the ceiling.
- **What if the repo moves or renames?** `TT_IDEAS_REPO` constant handles it — changeable without a code release.
- **Player-visibility of the repo side.** Players/coaches shouldn't see commit URLs. Their status display just says "Accepted" when internal status is `promoted`.
- **Rejected ideas in staging** — do they stay visible to the author, or get hidden? Probably visible with the rejection note, so they understand why.

## Touches

New module: `src/Modules/Development/`
New class: `src/Modules/Development/GitHubPromoter.php`
New migrations: `tt_dev_ideas`, `tt_dev_tracks`
Integrates with: `src/Modules/Authorization/` (new `tt_promote_idea` capability), `src/Modules/Goals/` (spawn goal from idea in-progress), `src/Shared/Frontend/` (submission forms on coach + player dashboards), `src/Modules/Evaluations/` (optional category tagging on tracks)
New config constants in `wp-config.php`: `TT_GITHUB_TOKEN` (required), `TT_IDEAS_REPO` (optional), `TT_IDEAS_BASE_BRANCH` (optional). Document in DEVOPS.md.
