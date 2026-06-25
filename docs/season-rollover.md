<!-- audience: admin -->

# Season rollover

Season rollover moves whole squads up an age group at the end of a season in
one guided pass, and records a dated journey event for every player it
touches. It is reached from the dashboard at **People → Season rollover**
(`?tt_view=season-rollover`) and requires the **Manage players**
capability (`tt_manage_players`).

## What it does — and what it does not

In this version the rollover does exactly two things per player:

1. **Moves the player to a target team** (for a promotion), and
2. **Writes a dated journey event** describing the change.

It does **not** create or assign a season entity, and it does **not**
archive anyone. In particular, a player you mark **Released** is recorded
with a dated `released` journey event but is **left active** — releasing a
player here never starts the data-retention clock or removes them from the
roster. Archiving stays a separate, deliberate action.

## The three steps

The flow is a dedicated multi-step screen. Only the final step changes any
data.

### 1. Map teams

For each existing (non-archived) team you pick a **target team** to promote
its players into, or leave it on *No promotion / stays*. You also set:

- **Effective date** — the date stamped on every journey event this run
  creates. It defaults to the current season's end date when one is set,
  otherwise today.
- **Reason** (optional) — free text added to the release / graduation event
  summaries (for example *End of 2025/26 season*).

### 2. Choose players

For every mapped team you get a checklist of its active players. Each
selected player has an action:

- **Promote** — move to the mapped target team (the default when the team has
  a target). Writes an *Age group promoted* event.
- **Release** — write a *Released* event. The player **stays active**.
- **Graduate** — write a *Graduated* event.
- **Skip** — do nothing for this player.

Uncheck a player to leave them untouched.

### 3. Review & confirm

A read-only table lists the exact change for every selected player — player,
from-team, to-team, action and the journey event that will be written. When
you confirm:

- A **full backup runs first**. If the backup fails, the whole rollover is
  **aborted** and nothing is changed.
- Only after a successful backup are the team moves and journey events
  written.
- You are redirected back to a summary banner (promoted / released /
  graduated / skipped counts). Refreshing that page cannot re-run the
  rollover.

## REST API

The same logic is available over REST for non-WordPress front ends:

- `POST /wp-json/talenttrack/v1/season-rollover/plan` — dry-run. Returns the
  per-player change list and counts without changing anything.
- `POST /wp-json/talenttrack/v1/season-rollover/execute` — runs the rollover
  (backup-first), returning the counts and the backup filename.

Both require the `tt_manage_players` capability. The request body carries a
`mapping` object (`source_team_id` → `target_team_id`), a `selections`
object (`player_id` → action), an `effective_date` (`YYYY-MM-DD`) and an
optional `reason`.
