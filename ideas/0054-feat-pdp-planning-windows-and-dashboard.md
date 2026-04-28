<!-- type: feat -->

# #0054 — PDP planning windows + HoD dashboard

## Problem

Today the PDP cycle (#0044) seeds conversations with a `scheduled_at` distributed evenly across the season's start/end dates — but a coach has no way to *plan* the actual conversation date inside a defined window, and the head of academy has no way to see, for a given team, "how is the planning going?"

Specifically:

- Each block of the cycle (start / mid / end, or 4-block variants) should have a **3-week window** during which all conversations for that block are expected to be planned.
- The head of academy needs to see, for each team and block, **how many conversations are planned in the window** and **how many results are recorded after the window**.

Worked example: U11 has 12 players. The second block's window opens. After 3 weeks, 10 conversations have been planned in-window, 2 are missing. After the window closes, the HoD needs to see that only 8 of those 10 planned conversations have a recorded result. This visibility is missing today.

## Proposal

Three changes:

1. **Planning windows** on `tt_pdp_conversations`. Add `planning_window_start` / `planning_window_end` columns, populated on file creation by chunking the season into N×3-week windows around each conversation's `scheduled_at`. A coach editing a conversation can move the actual date inside the window without affecting other blocks.

2. **Coach planning surface** on the file detail. Each conversation row gets a "Plan in window" inline editor (date picker constrained to `[planning_window_start, planning_window_end]`). Visual cues: green when planned in window, amber when planned outside, red when window closed without a plan or result.

3. **HoD planning dashboard** at `?tt_view=pdp-planning` (gated by `tt_view_pdp` + admin/head-of-development). Per team × per block matrix:

| | Block 1 | Block 2 | Block 3 |
| --- | --- | --- | --- |
| U11 | 12/12 planned · 12/12 conducted | 10/12 planned · 8/10 conducted | window not yet open |
| U13 | 14/14 planned · 14/14 conducted | 12/14 planned · 11/12 conducted | window not yet open |

Each cell is a link to the team's PDP files filtered by that block. Drill-down shows the missing players.

## Scope

### Schema

```sql
ALTER TABLE tt_pdp_conversations
  ADD COLUMN planning_window_start DATE NULL,
  ADD COLUMN planning_window_end   DATE NULL;
```

Backfill on migration: for every existing conversation, derive the window from `scheduled_at` ± 10 days (clamped to the season bounds).

Future-creation: `PdpConversationsRepository::createCycle()` writes the window alongside `scheduled_at`. Window length comes from a new `tt_config.pdp_planning_window_days` (default 21).

### Coach UX

- File detail: each conversation row shows the window dates next to `scheduled_at`. Inline date picker constrained to the window.
- Status pill per row:
  - **Green** — planned in window AND conducted on time
  - **Amber** — planned in window, not yet conducted
  - **Orange** — planned outside window
  - **Red** — window closed without a plan, or planned but never conducted

### HoD UX

New view `?tt_view=pdp-planning`:
- Filter: season (default current), team (default all)
- Matrix: rows = teams in selected season, columns = block index (1..N)
- Cell content: `<planned-in-window>/<roster-size>` + `<conducted>/<planned>` if window past
- Color-coded same scale as coach view, aggregated
- Click a cell → link to `?tt_view=pdp&filter[team_id]=N&filter[block]=K` showing the underlying files

### Acceptance criteria

- [ ] Migration adds the two window columns and backfills existing conversations.
- [ ] Coach can plan a conversation inside its window from the file detail.
- [ ] Visual status pill reflects in-window / out-of-window / past-window state.
- [ ] HoD dashboard shows the planning matrix per team × block.
- [ ] Cell links drill into the filtered file list.
- [ ] `pdp_planning_window_days` config setting respected on new file creation.
- [ ] PHP lint, msgfmt, docs-audience CI all green.
- [ ] NL .po updated.

## Out of scope

- Calendar export of planning windows (Spond integration owns calendar surface — #0031).
- Email reminders when a window opens/closes (workflow templates already cover this — `pdp_conversation_due` could be extended later).
- Auto-rescheduling when a window is missed.

## Estimated effort

~3-4h actual (compression pattern). Schema + 1 query + 1 view + UI polish on the file detail.

## Cross-references

- **#0044** — PDP cycle owner. This is a Sprint 3 follow-up to that epic.
- **#0017** — trial player module (when it ships, trialists may need their own planning windows; design in then).
- **#0022** — workflow engine; `pdp_conversation_due` template already nudges coaches as `scheduled_at` approaches; this idea adds the visual state without changing the nudge logic.
