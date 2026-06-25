<!-- audience: user -->

# Player Development Plan (PDP)

A **PDP file** is a per-season development plan for a single player. It pulls together what would otherwise be scattered across evaluations, goals, and ad-hoc notes — and gives the academy a structured, repeatable rhythm: configurable conversations across the season, polymorphic goal links into the methodology vocabulary, and a deliberate end-of-season verdict signed off by the head of academy.

## Who sees what

- **Coaches** — full edit on PDP files for players on their own teams. Tile: **Performance → PDP**.
- **Head of academy** — global edit on every file plus exclusive write access to the end-of-season verdict.
- **Players** — read-only of their own file, plus an editable self-reflection section before each conversation. Tile: **Me → My PDP**.
- **Parents / guardians** — read-only of their child's file (after sign-off) plus a per-conversation acknowledgement button.
- **Read-only observer** — read-only across all files; no edit, no acknowledgement.

## PDP setup: who has a PDP this season

The **PDP** tile opens on a **player-centric coverage list** for the current season instead of a bare list of files. It starts from the player (CLAUDE.md §1): every player you coach is shown once, with a clear indicator of whether their PDP **for this season** exists yet.

- A summary line at the top reads, for example, *"14 of 18 players have a PDP for the current season (2025/26)."*
- Each row shows the **player** (linked to their record), their **team**, and a **PDP this season** status:
  - **Created** — a green *PDP ✓* pill, with conversation progress where available (e.g. *PDP ✓ 1/3*), linking straight to the file.
  - **Not started** — a grey *Not started* pill plus a **Create PDP** button that opens the create flow pre-filled for that player and team.
- **Filters** — team dropdown + player search, scoped the same way as the rest of the app: coaches see only their own teams' players; admins see everyone.
- **Only players without a PDP** — a one-click toggle to hide everyone who already has a file, so you can work straight through the gaps.
- Clicking a covered row opens the player's PDP file; clicking a missing row jumps into the create flow.

A secondary **Files** tab keeps the historical file-by-file list (with the *Show archived* toggle and per-row archive / restore controls) for power users.

The coverage data is also available over REST at `GET /wp-json/talenttrack/v1/pdp-files/coverage` (`season_id`, `filter[team_id]`, `search`, `only_missing`), so a future front end gets the same answer.

## The flow

### 1. Open the file

From the **PDP** tile, click *Create PDP* on a player's row (or *Open new PDP file*), pick a player and click *Open new PDP file*. The file is created with one row per conversation in the cycle (2, 3, or 4 — configurable per club, overridable per team) with each `scheduled_at` distributed evenly across the season's start and end dates.

A native calendar entry is written for every conversation. When the Spond integration ships (#0031), the same entries are pushed to the Spond calendar.

### 2. Run the conversations

Each conversation has two halves:

- **Pre-meeting** — agenda + the player's self-reflection.
- **Post-meeting** — notes, agreed actions, and a sign-off.

The form's **evidence sidebar** lists every evaluation, activity, and goal change for that player since the previous conversation — read-only, just there to anchor the discussion.

The player can fill in their self-reflection any time before the coach signs off. Once the coach signs off, the field locks.

### 3. Acknowledgement

After sign-off the conversation appears on the player's *My PDP* view (and the parent's, if they're linked). Both can click *Acknowledge* — a lightweight "I've seen this" timestamp.

### What the player sees: a state-aware My PDP (#1851)

*My PDP* now opens with a short lead block that tells the player **what to do now**, derived from where they are in the talk cycle. It only re-orders and highlights what is already there — every conversation card, the self-reflection editor and the acknowledgement buttons stay exactly where they were below.

- **Working period** (before the next talk's planning window) — the page leads with **Your focus**: the player's top active goals and the **next development talk** date. The conversation list recedes underneath.
- **Review window** (the planning window is open) — the page leads with **Prepare for your talk on &lt;date&gt;**, and the upcoming conversation is promoted to the top so its self-reflection editor and agenda are front-and-centre. The self-review is framed as helpful, never required — nothing is blocked if the player skips it.
- **After the talk** (signed off, awaiting acknowledgement) — the page leads with **Your last development talk**, pointing at the notes, agreed actions and the acknowledgement button below, with the goals to carry forward.

Parents see the same state surface for their child, read-only and possessive ("&lt;Child&gt;'s development plan"). The state is derived from the seeded conversations and their planning windows — no schedule or window data changes.

### 4. End-of-season verdict

When the cycle's last conversation is signed off, the head of academy (or head coach in some configurations) records a verdict: **promote**, **retain**, **release**, or **transfer**. The verdict is its own row, signed off independently from the conversation rows.

## Carryover

Setting a new season as current triggers a one-shot job: every open PDP file in the previous season's roster is replicated for the new season — fresh conversations, fresh `created_at`, but the player's open goals (anything not `completed` or `archived`) carry forward.

Conversation prose does **not** carry over. Each season starts with a clean slate; the previous season's history stays where it is.

## Goal links

A goal can now link to one or more methodology entities:

- a **principle** (e.g. *playing out from the back*)
- a **football action** (e.g. *passing under pressure*)
- a **position** (e.g. *number 8*)
- a **player value** (commitment, coachability, leadership, resilience, communication, work ethic, fair play, ambition — editable from Configuration → Lookups)

The links are surfaced on the player profile and in the print template; they let you query "all goals tied to *resilience* across the academy" or "every player working on *playing out from the back*".

## Print

The detail view's **Print / PDF** button opens a clean A4 layout: photo, season label, current goals + status, agreed actions per conversation, and signature lines for coach / player / parent. Toggle *Re-render with evidence page* to add a second A4 with recent evaluations and activities.

## Configuration

- **Configuration → Lookups → Player values** — edit the value vocabulary.
- **Top-level menu → Seasons** — list, add, set current. Setting a new season as current triggers carryover.
- **Configuration → System** — the *PDP cycle default* (2 / 3 / 4) and *Print: include evidence by default* toggle.
- **Per-team override** — on the team edit page, set *PDP cycle size* to override the club default.

## Workflow nudges

Two task templates are registered:

- `pdp_conversation_due` — reminds the owning coach as a conversation's `scheduled_at` approaches.
- `pdp_verdict_due` — reminds the head of academy at season-end.

Both go through the standard #0022 workflow & tasks engine — the same inbox, the same nudge cadence configurable from Configuration → Workflow.

## Cycle progress + ack visibility (v3.79.0)

The PDP file detail page now shows cycle progress as **(X of N signed off)** next to the cycle size, so you see at a glance how much of the cycle is complete. Each conversation row carries:

- a derived **status** badge (Scheduled / Held / Signed off) instead of three separate date+yes columns
- an **Acks** column with parent (👤) and player (⚽) acknowledgement icons — `✓` once acked, `·` while pending

The summary card has a context-sensitive help button that opens the PDP topic in the docs drawer.

## Archive vs. permanently delete

PDP files have **two** removal paths so destructive cleanup never costs an accidental click on the wrong row.

- **Archive** — soft-delete. The file disappears from the default list, but every row stays in the database. Coaches with edit reach can archive an active file (`Archive` button in the actions column). Admins can flip the *Show archived* toggle on the PDP list and click *Restore* to bring it back. This is the right answer when a player leaves mid-season or the cycle was opened by mistake.
- **Permanently delete** — irreversible hard-delete. Available only to operators holding the `tt_delete_pdp` capability (admin only by seed). Two entry points: the *Permanently delete PDP* button on the PDP file detail page, **and** a per-row *Delete permanently* action on archived files (flip the *Show archived* toggle on the PDP list — operators with `tt_delete_pdp` see the toggle even without restore reach). Both open the same confirm subview that:
  - shows a **Cascade summary** — how many conversations / verdicts / calendar links / PDP blocks / goal-link rows will vanish.
  - requires the operator to **type the player's name** verbatim before the *Permanently delete PDP* button enables (case-insensitive, trim-tolerant).
  - writes a **pre-delete CSV snapshot** to `wp-content/uploads/tt-pdp-deletes/pdp-<file-id>-<timestamp>.csv` before the cascade runs. The CSV's absolute path is recorded in the `pdp.deleted_with_cascade` audit-log entry alongside the per-table row counts.
  - runs the five-table cascade inside a single transaction. Any failure rolls the whole thing back; partial state on failure is impossible.

Use *Archive* by default. Reach for *Permanently delete* only for GDPR-erasure, parental-request, or other legitimate data-retention cases. The CSV snapshot is your audit trail — keep it.
