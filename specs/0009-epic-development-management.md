<!-- type: epic -->

# #0009 — Development management: staged ideas + GitHub promotion

## Problem

Ideas for plugin improvements come from multiple sources — coaches using the tool, head-of-development users thinking about workflows, the lead developer himself. Today the only way to capture them is:

- Open a GitHub issue (requires a GitHub account + knowing the repo URL).
- Email the lead dev.
- Forget about it.

None of these work for the actual users of the plugin (mostly non-technical academy staff). GitHub is a wall; email gets lost; forgetting loses the idea.

What's missing: a way for plugin users to submit ideas *from inside the plugin*, with the lead dev having a review + promotion flow that ends with approved ideas landing in the repo's `ideas/` folder as proper markdown files.

This isn't an end-user-facing epic — it's infrastructure for making the whole shaping/spec/implement cycle work better across more than one person.

## Proposal

A staged ideas pipeline where:

1. Any user with a new capability (`tt_submit_idea`) can submit an idea via a simple frontend form.
2. Submitted ideas land in a `tt_dev_ideas` table with status `submitted`.
3. The lead dev (or anyone with `tt_promote_idea`) reviews each submitted idea via a wp-admin panel.
4. On approval, the idea is:
   - Promoted to a markdown file in the configured GitHub repo's `ideas/` folder via GitHub API.
   - Status flips to `promoted` with a link to the committed file.
5. On rejection, idea gets a rejection note, stays visible to the author.
6. Tracks (the second idea in this file — grouping related ideas into a planned development line) stay a future concept; v1 is just the submit-review-promote flow.

**Explicitly out of scope**: player/coach goal generation *from* development ideas (that was in the raw idea but is a separable concern; flag for future).

## Scope

Four sprints:

| Sprint | Focus | Effort |
| --- | --- | --- |
| 1 | Schema + capabilities + submit form | ~6–8h |
| 2 | Review panel + approve/reject flow (no GitHub yet) | ~6–8h |
| 3 | GitHub promotion integration + config | ~8–10h |
| 4 | Tracks + dashboard surfaces | ~6–8h (optional — can ship without) |

**Total: ~26–34 hours.**

### Sprint 1 — Schema + submit form

**Schema**:
```sql
CREATE TABLE tt_dev_ideas (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  submitted_by BIGINT UNSIGNED NOT NULL,
  title VARCHAR(255) NOT NULL,
  body TEXT NOT NULL,                  -- free-form, may contain markdown
  proposed_type VARCHAR(32) DEFAULT 'feat',  -- 'feat', 'bug', 'question', 'epic'
  status VARCHAR(32) DEFAULT 'submitted',    -- 'submitted', 'approved', 'rejected', 'promoted'
  review_notes TEXT DEFAULT NULL,      -- rejection reason or approval notes
  reviewed_by BIGINT UNSIGNED DEFAULT NULL,
  reviewed_at DATETIME DEFAULT NULL,
  promoted_to_url VARCHAR(500) DEFAULT NULL,  -- GitHub URL after promotion
  promoted_as_id VARCHAR(32) DEFAULT NULL,    -- '0045' — the idea number assigned
  promoted_at DATETIME DEFAULT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_status (status),
  KEY idx_submitter (submitted_by)
);
```

**Capabilities**:
- `tt_submit_idea` — submit new ideas. Granted to `tt_coach`, `tt_head_dev`, `administrator` by default. Not to players by default (can be granted manually if you want).
- `tt_promote_idea` — review + approve + promote ideas. **Manually assigned only.** Default: nobody has it. Lead dev grants to specific users.
- `tt_view_ideas_submissions` — see the list of submissions. Granted to anyone with `tt_submit_idea` (they see their own) + anyone with `tt_promote_idea` (they see everyone's).

**Submit form**:
- Frontend view `FrontendSubmitIdeaView`, accessible from a tile or menu item.
- Fields: title, body (textarea, supports markdown), proposed type (dropdown — feat/bug/question/epic).
- Preview pane: renders the body as markdown so submitter sees what the lead dev will see.
- Submit button → writes to `tt_dev_ideas` with status `submitted`.
- Confirmation: "Idea submitted. You'll be notified when reviewed."

**Submitter's ideas list**:
- View shows all ideas the current user has submitted.
- Per-idea: title, status, submitted date, review notes (if rejected), link to promoted file (if promoted).
- Status badge color-coded.

### Sprint 2 — Review panel

**Review panel**:
- Frontend view under Administration tile (or wp-admin) — anyone with `tt_promote_idea`.
- List of submissions, default-filtered to `status = 'submitted'`.
- Columns: submitted date, title, submitter, proposed type, actions.
- Click a row → opens detail view.

**Detail view**:
- Title (editable — lead dev can retitle before promoting).
- Body (editable — can clean up / expand / reshape).
- Proposed type (editable — can change).
- Suggested idea number: system auto-suggests the next available number, lead dev can override.
- Action buttons:
  - **Approve & promote** (Sprint 3): commits to GitHub.
  - **Approve locally** (no GitHub yet): marks as `approved`, stays in `tt_dev_ideas` without promotion.
  - **Reject**: captures rejection note, status → `rejected`.

Sprint 2 ships with the "approve locally" path working. Sprint 3 adds the GitHub leg.

**Notification**: on review action, submitter gets an email (via `wp_mail`) with the outcome + note.

### Sprint 3 — GitHub promotion

**Config** (in `wp-config.php`):
- `TT_GITHUB_TOKEN` — fine-grained PAT with write access to the repo, contents scope. Required.
- `TT_IDEAS_REPO` — e.g. `caspernieuwenhuizen/talenttrack`. Default sensible for the current install.
- `TT_IDEAS_BASE_BRANCH` — default `main`.

Document all three in `DEVOPS.md`.

**Promoter class**: `src/Modules/Development/GitHubPromoter.php`.

Flow when lead dev clicks "Approve & promote":
1. Validate token is configured; surface clear error if not.
2. Generate filename: `NNNN-{type}-{slug}.md` (e.g. `0045-feat-roster-print-view.md`).
3. Generate markdown content: `<!-- type: X -->\n\n# Title\n\nBody` + metadata footer (submitted by, submitted at).
4. Call GitHub API: `PUT /repos/{owner}/{repo}/contents/ideas/{filename}`.
5. On success: capture commit URL, save to `promoted_to_url`, mark status `promoted`.
6. On 422 (branch protected): surface error with remediation hint ("Branch protection on `main` is blocking commits to `ideas/`. Either relax protection for `ideas/*.md` or switch to PR flow.").
7. On other error: surface raw API error, keep idea in `approved` state (ready for retry).

**Direct-to-main** per shaping. If the repo later adds branch protection, swap to PR variant (small change — use `POST /repos/{owner}/{repo}/pulls` instead).

**Retry**: if promotion fails, idea stays `approved`. "Retry promotion" button in the review panel tries again.

### Sprint 4 — Tracks + dashboard

Optional — can ship without.

**Tracks**: a `track` is a named group of ideas (e.g. "Player journey improvements" might group 5 ideas). Schema:
```sql
CREATE TABLE tt_dev_tracks (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(128) NOT NULL,
  description TEXT,
  created_by BIGINT UNSIGNED NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

ALTER TABLE tt_dev_ideas ADD COLUMN track_id BIGINT UNSIGNED DEFAULT NULL;
```

UI: tracks as a separate tab on the review panel. Drag ideas into tracks. Track pages show all contained ideas.

**Dashboard tile**: for users with `tt_promote_idea`, a small tile showing "3 ideas awaiting review."

## Out of scope

- **Player-visibility of promoted ideas.** Players see their own submissions; the promoted-elsewhere output is private to the dev workflow.
- **Goal spawning from ideas.** Was in the raw idea text, dropped during shaping — separable concern.
- **Multi-maintainer workflow.** Fine-grained PAT works for one person. GitHub App would be needed for multi-maintainer; noted as future ceiling.
- **Automated spec drafting from approved ideas.** This tool gets ideas to the repo; shaping into specs stays the manual interactive process we used in this session.
- **Evaluation category tagging on ideas.** Nice-to-have; deferred.
- **Rich-text editing of idea bodies.** Markdown textarea with preview is enough.

## Acceptance criteria

- [ ] Any user with `tt_submit_idea` can submit an idea via the frontend form.
- [ ] Submitted ideas appear in the review panel for users with `tt_promote_idea`.
- [ ] Review panel allows approve-locally, reject with note, or approve-and-promote.
- [ ] Approve-and-promote writes a correctly-formatted markdown file to `ideas/` in the configured GitHub repo.
- [ ] Filename follows `NNNN-{type}-{slug}.md` convention with auto-suggested next number.
- [ ] On promotion failure (branch protection, network, etc.), clear error shown; idea stays retryable.
- [ ] Rejection captures a note, author sees the note in their ideas list.
- [ ] Rejected ideas stay visible to their author with a rejection label.
- [ ] Email notifications sent to submitter on review action.
- [ ] GitHub PAT required but safely handled (never logged, never surfaced to non-admins).

## Notes

### Cross-epic interactions

- **#0019 Sprint 5** — "Administration" tile group houses the review panel and tracks view.
- **#0021 (audit log viewer)** — when it ships, promotion actions should be auditable. Log via `tt_audit_log`.
- **#0012** — the shaping of this tool's own docs/comments should avoid AI fingerprints (eat your own dogfood).

### Security considerations

- The GitHub PAT is a production credential. Never log it, never display it, never include it in error messages surfaced to users.
- If the PAT is rotated, the user needs to update `wp-config.php`. Document this in DEVOPS.md.
- Promotion writes to a public-facing repo. Approve button should require confirmation ("You're about to commit to `caspernieuwenhuizen/talenttrack`. Continue?").
- Rate-limit submissions (e.g. max 5 submissions per user per day) to prevent spam if a player-role user ever gets the cap.

### Sequence position

Late in SEQUENCE.md — Phase 5 infra. Ships when shaping cadence justifies the tooling. Today, direct idea file creation (what we did in this session) is fine; this epic exists for when the user base grows and ideas come from more than just the lead dev.

### Touches

- New module: `src/Modules/Development/`
  - `DevelopmentModule.php`
  - `GitHubPromoter.php`
- New schema: `tt_dev_ideas`, `tt_dev_tracks` (Sprint 4)
- New REST: `Development_Controller.php` with submit/review/promote endpoints
- Frontend views: submit form, submitter's ideas list, review panel, tracks view
- Capability registration: three new caps
- Config: `TT_GITHUB_TOKEN`, `TT_IDEAS_REPO`, `TT_IDEAS_BASE_BRANCH` documented in DEVOPS.md
- Integration with `wp_mail` for notifications
