<!-- audience: user -->

# Player Development Plan (PDP)

A **PDP file** is a per-season development plan for a single player. It pulls together what would otherwise be scattered across evaluations, goals, and ad-hoc notes — and gives the academy a structured, repeatable rhythm: configurable conversations across the season, polymorphic goal links into the methodology vocabulary, and a deliberate end-of-season verdict signed off by the head of academy.

## Who sees what

- **Coaches** — full edit on PDP files for players on their own teams. Tile: **Performance → PDP**.
- **Head of academy** — global edit on every file plus exclusive write access to the end-of-season verdict.
- **Players** — read-only of their own file, plus an editable self-reflection section before each conversation. Tile: **Me → My PDP**.
- **Parents / guardians** — read-only of their child's file (after sign-off) plus a per-conversation acknowledgement button.
- **Read-only observer** — read-only across all files; no edit, no acknowledgement.

## The flow

### 1. Open the file

From the **PDP** tile, pick a player and click *Open new PDP file*. The file is created with one row per conversation in the cycle (2, 3, or 4 — configurable per club, overridable per team) with each `scheduled_at` distributed evenly across the season's start and end dates.

A native calendar entry is written for every conversation. When the Spond integration ships (#0031), the same entries are pushed to the Spond calendar.

### 2. Run the conversations

Each conversation has two halves:

- **Pre-meeting** — agenda + the player's self-reflection.
- **Post-meeting** — notes, agreed actions, and a sign-off.

The form's **evidence sidebar** lists every evaluation, activity, and goal change for that player since the previous conversation — read-only, just there to anchor the discussion.

The player can fill in their self-reflection any time before the coach signs off. Once the coach signs off, the field locks.

### 3. Acknowledgement

After sign-off the conversation appears on the player's *My PDP* view (and the parent's, if they're linked). Both can click *Acknowledge* — a lightweight "I've seen this" timestamp.

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
