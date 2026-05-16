# 0093 — Tournament planner — fair-share minutes across a multi-match weekend

## Problem

A head coach planning a tournament weekend has no surface in TalentTrack that says:

> *Across these 5 matches of 20 minutes each, here is who plays which position when, here is everyone's running minute total vs the fair-share target, and here is who started / who's still owed a start.*

Today the closest tools are:

- The **Activities** module, which can already model a single match (migration 0079 added `opponent`, `home_away`, `kickoff_time`, `formation` on `tt_activities`, and `lineup_role` + `position_played` on `tt_attendance`). But there's no grouping above the single match, no notion of substitution periods within a match, and no per-player minute roll-up across a set of matches.
- The **PDF team-sheet exporter** ([MatchDayTeamSheetPdfExporter.php](../src/Modules/Export/Exporters/MatchDayTeamSheetPdfExporter.php)), which renders one match's starting XI + bench. Single-match, single-period view.
- **Spond** integration, which surfaces matches as events but knows nothing about lineups, periods, or rotation.

The pilot's exact ask (recorded during shaping, 2026-05-16):

- Group multiple matches into one tournament container.
- Per-match configurable `duration_min` **and** `substitution_windows` (e.g. `[10]` for one swap mid-match on a 20-min game).
- Formation catalog with `1-3-4-3` and the other usual shapes; per-match override allowed.
- Per-player **eligible positions** — players typically have 2–3 they can cover.
- **Equal playing time** as the headline fairness goal.
- Per-match **opponent level** because it informs lineup judgement.
- Per-player **starts** + **full-matches** counters (minutes alone undercount the "always on the pitch" pattern).
- Minutes-played + minutes-expected **always visible** while planning — not buried behind a tab.

## Proposal

Introduce a first-class `Tournament` entity with a **hybrid relationship to Activities** (see Notes), a per-match planner grid driven by formation + substitution windows, and an **always-visible minutes ticker** as the headline UI element.

**Coach's mental loop:**

1. Land on tournaments list → **+ New tournament** opens the wizard.
2. Wizard captures basics + default formation + squad + matches (with sub-windows + opponent level).
3. On finish, coach lands on the tournament planner: matches stacked vertically, minutes ticker sticky to the bottom (mobile) or right sidebar (desktop).
4. Per match, coach taps **Auto-balance** to fill the grid (greedy assignment) or drags slots manually. Ticker updates live.
5. On match completion, lineup locks (with an override) and minutes flip from "expected" to "played" in the ticker.
6. A standalone match can later be promoted into an activity for the player journey rollup — but this v1 stays planning-only by default (link is optional).

### Architectural pivot from the idea draft

Four shaping decisions taken on 2026-05-16:

- **Hybrid storage**: `tt_tournament_matches` is its own table with an **optional `activity_id` FK**. A tournament match becomes an activity (and shows on the player's journey) only when the coach kicks it off / completes it. Planning-only matches stay free of activity-table churn.
- **Single anchor team required**: `tt_tournaments.team_id` is NOT NULL. Squad picker defaults to that team's active players; cross-team additions are explicitly allowed via the "Add player from another team" affordance (typical case: borrowing a keeper from a younger age group).
- **One ship, big PR**: this spec is the whole feature. No epic decomposition. Reviewer pain accepted in exchange for not landing a half-feature.
- **Player profile Tournament tab is a follow-up**: tracked as a new idea — `ideas/0094-feat-player-tournament-tab.md` — to be written alongside this spec. Out of scope here.

## Wizard plan

This work introduces a new wizard. Slug `new-tournament`, registered in `WizardRegistry`, reachable via `?tt_view=wizard&slug=new-tournament`. The flat-form path is `?tt_view=tournaments&action=new` from the list view's `+ New tournament` page-header action — wizard's final step hands off to the same `POST /tournaments` endpoint via `WizardEntryPoint::urlFor()`.

| Slug | Existing? | Notes |
| --- | --- | --- |
| `tournament-basics` | new | Name, start_date, end_date (optional), anchor team (required dropdown of teams the user coaches, or all teams for HoD/admin). |
| `tournament-formation` | new | Radio of seeded formations with mini pitch-diagram previews. `1-3-4-3`, `1-4-3-3`, `1-4-4-2`, `1-3-5-2`, `1-4-2-3-1`, `1-2-3-2` (8v8), `1-2-3-1` (7v7), `1-1-3-1` (6v6). "Custom" lets a coach type a shape; validated as `\d+(-\d+)+` summing to 7/8/9/11. |
| `tournament-squad` | new | Pre-fills with anchor team's active roster; each picked player gets a position-eligibility multi-select pre-filled from their profile's `preferred_positions` array. "Add player from another team" opens a slim player picker scoped to other teams the user can see. Optional `target_minutes` per player (escape hatch for recovering-from-injury cases). |
| `tournament-matches` | new | Repeatable mini-form: label, opponent_name, opponent_level dropdown, duration_min, substitution_windows chip-editor. Smart default: one window every 10 minutes for short games, every 15 for longer games. Reorder via drag. |
| `tournament-review` | new | Full summary + per-player target-minutes preview (computed live from squad size × total minutes). Submit → POST /tournaments → land on the planner. |

Each step uses the standard `WizardChrome` (Previous / Next / Cancel), no sticky bottom bar (removed in v3.110.126). Save+Cancel exempt per CLAUDE.md § 6 (c) — wizard chrome covers it.

## Scope

In:

### Schema (new tables + lookup seeding)

- `tt_tournaments` — `id, club_id, uuid, name, start_date, end_date, default_formation, team_id NOT NULL, created_by, created_at, archived_at, archived_by`.
- `tt_tournament_matches` — `id, tournament_id, sequence, label, opponent_name, opponent_level, formation NULL (override), duration_min, substitution_windows JSON, scheduled_at, kicked_off_at, completed_at, activity_id NULL, notes`. `activity_id` is the optional FK to `tt_activities`; set when the coach kicks off the match (auto-creates an activity of type `match`) or manually links an existing activity.
- `tt_tournament_squad` — `tournament_id, player_id, eligible_positions JSON, target_minutes NULL, notes`. Composite PK `(tournament_id, player_id)`.
- `tt_tournament_assignments` — `id, match_id, period_index, player_id, position_code, UNIQUE (match_id, period_index, player_id)`. Bench rows have `position_code='BENCH'`. Period 0 = opening lineup. Minutes per period derived as `duration_min / (windows + 1)` assuming even splits (uneven splits explicitly out of scope, see § Out of scope).
- All four tables carry `club_id` (tenancy scaffold per CLAUDE.md § 4); `tt_tournaments` carries `uuid` for the SaaS port.
- New lookup types seeded by the activator: `tournament_formation` + `tournament_opponent_level`. Operator-editable.

### REST API

All under `/wp-json/talenttrack/v1/`, all routed through `AuthorizationService::canViewTournament()` / `canEditTournament()`.

```
GET    /tournaments
POST   /tournaments
GET    /tournaments/{id}
PUT    /tournaments/{id}
DELETE /tournaments/{id}

POST   /tournaments/{id}/matches
PATCH  /tournaments/{id}/matches/{m_id}
DELETE /tournaments/{id}/matches/{m_id}
POST   /tournaments/{id}/matches/{m_id}/kickoff      -- creates linked activity, sets kicked_off_at
POST   /tournaments/{id}/matches/{m_id}/complete     -- sets completed_at, locks lineup (override flag respected)

PATCH  /tournaments/{id}/squad                       -- bulk replace
PATCH  /tournaments/{id}/squad/{player_id}
DELETE /tournaments/{id}/squad/{player_id}

POST   /tournaments/{id}/matches/{m_id}/auto-plan    -- greedy assignment
PATCH  /tournaments/{id}/matches/{m_id}/assignments  -- bulk replace assignments for one match
PATCH  /tournaments/{id}/matches/{m_id}/periods/{p_idx}/slots
                                                     -- granular slot update (drag-drop bumps this)

GET    /tournaments/{id}/totals                      -- per-player rollup: played, expected, starts, full_matches, target
```

List response uses the existing `{rows, total, page, per_page}` envelope. Detail response composes match + squad + assignments + totals so a single page-load hydrates the planner. Auth: new caps `tt_view_tournaments` + `tt_edit_tournaments` added to the matrix.

### Capabilities + AuthZ

**v1 ships admin-only.** Per the operator decision at the start of development (2026-05-16): the feature is gated to Academy Admin (WP admin / `manage_options`) for the first ship. Coaches / HoD / Scout / Player / Parent do NOT see the tournaments view or any nav entry for it. This is implementation simplicity for v1, not a long-term policy.

- New caps introduced now: `tt_view_tournaments`, `tt_edit_tournaments`.
- Default role mapping in v1: **Academy Admin only** for both caps.
- REST `permission_callback`s and the view-router gate on `current_user_can('tt_view_tournaments')` / `current_user_can('tt_edit_tournaments')`. The cap layer is built correctly; the role mapping is intentionally narrow.
- The richer per-entity check (`AuthorizationService::canViewTournament( $user_id, $tournament_id )` / `canEditTournament` — creator / coach-of-anchor-team / HoD / admin) is deliberately NOT built yet. It lands in the follow-up that opens the feature to Coach + HoD personas. Today's cap-only check is enough because only Academy Admin holds the cap.
- `docs/authorization-matrix.md` updated with the new caps × persona grid showing the v1 admin-only mapping and a `<!-- next-phase -->` comment line for the expansion targets.

### Follow-up: open to non-admin personas

Tracked as a future idea (to be opened after v1 ships and the pilot validates the surface). When that lands:

1. Map `tt_view_tournaments` → Coach + HoD + Scout, `tt_edit_tournaments` → Coach (own tournaments) + HoD.
2. Introduce `AuthorizationService::canViewTournament` / `canEditTournament` with creator / team-coach / global-staff logic.
3. Swap REST `permission_callback`s from cap-only to per-entity checks.
4. Add nav entries to the persona dashboards.

Until then, the planner is invisible to non-admins.

### Wizard

The `new-tournament` wizard described under Wizard plan above. Registered in `WizardRegistry`; flat-form fallback survives at `?tt_view=tournaments&action=new` and routes via `WizardEntryPoint::urlFor()` based on the `tt_wizards_enabled` config flag.

### Frontend views

- `?tt_view=tournaments` — list view via `FrontendListTable`. Row-link standard (`row_url_key: 'detail_url'`) per CLAUDE.md § 7. Columns: Name (with team chip + opponent-levels chip strip below — e.g. `[U13 →] [2 stronger]`), Dates, Squad size, Total minutes. Filters: status (active / archived), team, date range.
- `?tt_view=tournaments&id=N` — detail / planner. Top to bottom:
  1. **Header** — name + dates + anchor team chip + default formation pill + Edit / Archive actions in `tt-page-actions`.
  2. **Minutes ticker (sticky)** — see § Minutes ticker below.
  3. **Matches list** — one card per match. Card header: opponent name + level pill + duration + status pill (planning / in progress / completed). Tap-to-expand reveals the planner grid.
  4. **Auto-balance** button per match. One-tap fills the grid by the greedy algorithm in § Notes.

### Minutes ticker (the headline component)

Sticky. Mobile: horizontal-scroll strip pinned above the safe-area inset at the bottom of the viewport, ~120px tall. Desktop (≥ 1024px): fixed right sidebar, 280px wide. Each squad player gets one card:

```
┌────────────────────────┐
│ [photo] Casper N.      │
│ ▓▓▓▓▓░░░░  60/100 min  │   ← played (filled bar) + expected total vs target
│ ⚡ 3 starts  🏆 1 full   │
└────────────────────────┘
```

- Bar colour: green if `expected ≥ target`, amber if `target * 0.85 ≤ expected < target`, red if `expected < target * 0.85`.
- Tap-to-sort row at the top: `Default` / `Fewest minutes first` / `Fewest starts first` / `No full matches yet`.
- Updates live as drag-drops mutate the grid (PATCH response carries fresh totals; client merges).
- Same component reused at the top of each per-match planner grid for in-context decision making.

### Per-match planner grid

Period × position layout. Mobile: stacked-card reflow — one period per screenful, swipe between periods. Desktop: full grid.

```
         P1 (0–10')   P2 (10–20')
GK       Casper       Casper
RB       Pim          Lukas
CB       Lukas        Alex
LB       Sven         Sven
RM       Marek        Marek
RCM      Tom          Tom
LCM      Daan         Daan
LM       Ruben        Pim
RW       Bram         Bram
ST       Niels        Niels
LW       Mees         Mees
BENCH    Alex, Tom    Pim, Bram
```

Above the grid:

```
Starting now: Casper (5th start), Pim (1st start), Lukas (3rd start)…
```

Drag-and-drop on desktop; tap-to-swap on mobile (long-press picks up, second tap drops). Each cell shows player photo + initials. Bench section is its own row at the bottom of the period column. Cells visually flag eligibility violations (player in a slot they're not eligible for) with an amber dot — soft warning, not blocked.

### Auto-balance — greedy algorithm

`POST /tournaments/{id}/matches/{m_id}/auto-plan`:

```
For each period p in 0..N:
    For each formation slot s in match.formation.slot_labels:
        candidates = squad where:
            s.position_type IN player.eligible_positions
            player NOT already assigned in this period
        rank candidates by:
            1. (target_minutes - expected_minutes) DESC    -- under-served first
            2. (if p == 0) starts ASC                      -- fewer starts wins openers
            3. (player not benched in p-1) ASC             -- avoid back-to-back bench
        assign top candidate to slot
    remaining squad members → BENCH for period p
```

No backtracking. Coach manually fixes the (rare) cases where greedy paints itself into a corner — surfaced via the eligibility-violation flag on the cell.

**Position eligibility uses position TYPES, not slot codes.** A formation slot like `RB` has position_type `DEF`; a player eligible for `DEF` matches. This keeps eligibility manageable (3 type buckets per player rather than 11 specific slot codes). Slot-specific eligibility is an explicit follow-up if/when a coach requests it.

### Lineup lock semantics

- `kicked_off_at` set: the linked activity is created (POST `/activities` of type `match`, opponent / formation / kickoff_time copied from the tournament match). Assignments remain editable but the UI shows an "Match in progress" badge on the card.
- `completed_at` set: assignments lock by default; minutes flip from "expected" to "played" in the ticker; coach gets an **Edit anyway** button gated on `tt_edit_tournaments` for backfilling forgotten swaps. The lineup syncs to the linked activity's `tt_attendance` rows with `lineup_role='start'` for period-0 non-bench players, `'bench'` for everyone else. Per-player `position_played` set from the period-0 position.

### i18n + Dutch (DEVOPS.md ship-along)

All user-visible strings via `__()` / `_e()`. New `.po` entries: tournament, match, squad, eligible positions, target minutes, opponent level (+ each seeded level value), formation (+ each seeded formation name), starts, full matches, substitution window, kick off, lineup locked, edit anyway, auto-balance. Dutch translations alongside in the same PR.

### Docs

- New `docs/tournaments.md` (and `docs/nl_NL/tournaments.md`) covering the coach's mental model + the schema for developers.
- `docs/rest-api.md` updated with the new endpoints.
- `docs/authorization-matrix.md` updated with `tt_view_tournaments` + `tt_edit_tournaments`.
- `docs/architecture.md` § Modules gets a new entry for `Modules/Tournaments/`.
- `SEQUENCE.md` updated if #0093 was referenced.

## Out of scope

- **Player profile Tournament tab.** Tracked as `ideas/0094-feat-player-tournament-tab.md` — the per-player rollup with minutes/starts/full-matches stats + per-match row strip. Created in this same PR but as an idea, not implementation.
- **Solver-based auto-balance with constraints.** No "Casper must play GK, Pim and Lukas can't both be benched at once". Greedy assignment + manual override only. Solver upgrade is a separate ship triggered when ≥ 2 coaches request the same constraint shape.
- **Auto-favour top players against tough opponents.** Opponent level is data only; the tool does not weight the greedy algorithm by it. Coach applies judgement via manual override.
- **Uneven substitution windows.** `substitution_windows: [15, 45]` (period lengths 15 / 30 / 35) is technically supported by the JSON schema but the period-duration calculation assumes even splits in v1. UI snaps windows to even splits; uneven splits stored as `[duration_min / (N+1) × k]` and noted as a follow-up.
- **Spond match-import linkage.** v1 matches are coach-entered. Future ship can link `tt_tournament_matches.scheduled_at` to a Spond event id.
- **Slot-specific position eligibility.** Eligibility is by position type (GK / DEF / MID / FWD), not by specific slot code (RB vs LB vs CB).
- **Multi-tournament cohort comparison reports.** Derivable from the schema but a separate export ship.
- **Printable team sheet for tournaments.** The existing `MatchDayTeamSheetPdfExporter` is per-activity; extending it to a tournament-wide sheet is a separate ship.
- **PWA / native-app mode.** Uses the existing dashboard shell.

## Acceptance criteria

### Lifecycle

- Coach lands on `?tt_view=tournaments`, taps **+ New tournament**, completes the 5-step wizard, lands on the planner with the matches stacked and the minutes ticker rendered.
- A tournament with 5 matches × 20 minutes and a 12-player squad yields a per-player target of `5 × 20 × 11 / 12 ≈ 91.7 min`. The ticker shows that target on every card before any auto-balance runs.
- The list view at `?tt_view=tournaments` renders via `FrontendListTable` with the row-link standard wired (`row_url_key: 'detail_url'`); tapping anywhere on a row navigates to the detail view; tapping the team chip navigates to the team detail.

### Per-match planning

- Each match card opens to a period × position grid driven by the match's `formation` (or tournament default) and `substitution_windows` array.
- A 20-min match with `substitution_windows: [10]` shows two columns (P1: 0–10', P2: 10–20'). A 60-min match with `[20, 40]` shows three columns (P1: 0–20', P2: 20–40', P3: 40–60').
- Coach can drag a player from BENCH to any slot in the same period (desktop), or tap-to-swap (mobile). The grid PATCHes `/periods/{p_idx}/slots`; ticker updates.
- Tapping **Auto-balance** on a match's card POSTs `/auto-plan`; the grid fills; the ticker updates; per-player totals roll up correctly.
- A player not eligible for a slot's position type, placed there manually, renders with the amber-dot warning but is not blocked.
- The "Starting now: …" line above the grid lists the period-0 non-bench players with their running starts count.

### Minutes ticker

- Ticker is sticky on every tournament screen — bottom strip on mobile, right sidebar on desktop.
- Each card shows: photo + name + played-so-far / expected-total vs target (bar + numbers), ⚡ N starts, 🏆 N full matches.
- Bar colour: green / amber / red against the equal-share target.
- Sort row swaps order: Default / Fewest minutes / Fewest starts / No full matches.
- Updates reactively to grid changes (no full-page reload).

### Opponent level

- Each match card shows the opponent level as a coloured pill (green → grey → amber → red for weaker → equal → stronger → much stronger) using the `tournament_opponent_level` lookup's `meta.color`.
- New levels added by an operator via the Lookups admin appear in the wizard's match step dropdown without code changes.

### Lifecycle states

- `kicked_off_at` action: tap **Kick off match** on a match card → POST `/kickoff` → creates a `tt_activities` row of type `match`, links via `activity_id`, sets `kicked_off_at`, shows "Match in progress" badge.
- `completed_at` action: tap **Complete match** → POST `/complete` → lineup locks, ticker minutes flip from expected → played, attendance rows synced to the linked activity with `lineup_role` + `position_played`.
- **Edit anyway** button on a completed match's card (gated on `tt_edit_tournaments`) unlocks the grid for backfill; saving syncs back to attendance.

### Mobile

- Renders at 360px viewport with no horizontal page scroll (grid is the only scrollable region, and only on the per-match expanded view).
- Minutes ticker pinned above safe-area inset; bottom-strip cards 48px+ tappable.
- Per-match planner grid reflows to single-period stacked cards on < 640px; swipe-left/right navigates periods.
- Inputs: `duration_min` is `type="number" inputmode="numeric"`; sub-window chip editor accepts numeric input; all form inputs hit the ≥ 16px font-size floor.

### Wizard

- 5 steps, mobile-first, ≥ 48px touch targets, no sticky bottom bar (v3.110.126 removed those).
- Cancel exits to `?tt_view=tournaments`; Previous walks back through steps.
- Final step **Create + open planner** POSTs `/tournaments` with the full payload (basics + formation + squad + matches) in one request; success redirects to `?tt_view=tournaments&id=<new_id>`.
- Mid-wizard validation surfaces on Next (e.g. matches step blocks Next until ≥ 1 match is added).

### Capabilities (v1 admin-only)

- `tt_view_tournaments` granted to **Academy Admin only** in v1.
- `tt_edit_tournaments` granted to **Academy Admin only** in v1.
- All other personas (Coach, HoD, Scout, Player, Parent) → no view, no nav entry, no list-view access. The feature is invisible to them until the follow-up expansion ships.
- REST `permission_callback`s gate on `current_user_can('tt_view_tournaments')` / `current_user_can('tt_edit_tournaments')`. The check is cap-only in v1; per-entity (`canViewTournament(user, tournament_id)`) lands with the persona-expansion follow-up.
- A non-admin hitting `?tt_view=tournaments` directly → standard "not authorized" view via `FrontendAccessControl`.

### SaaS-readiness (CLAUDE.md § 4)

- All four new tables carry `club_id` + `tt_tournaments.uuid`.
- All REST routes use `permission_callback` via `AuthorizationService`, not `__return_true`.
- Business logic (greedy auto-balance, totals computation, eligibility checks) lives in `Modules/Tournaments/` services, NOT in view files.
- Every feature is reachable through the REST API even when the PHP renderer is not the consumer (e.g. an external SaaS app could fetch `/tournaments/{id}` and render the same data).

### Documentation (DEVOPS.md ship-along)

- `docs/tournaments.md` + `docs/nl_NL/tournaments.md` exist.
- `docs/rest-api.md` updated.
- `docs/authorization-matrix.md` updated.
- `languages/talenttrack-nl_NL.po` updated with Dutch translations.

## Notes

### Why hybrid storage over reuse-or-separate

The shaping decision (2026-05-16) was hybrid: tournament matches live in their own table, but carry an optional `activity_id` link. Reasoning:

- Pure reuse (every tournament match IS an activity) forced an activity row per planning entry even if the match never happens. Tournaments are scratch-pads as much as schedules; the coach drafts 6 matches, drops one, and we'd be left with a phantom activity unless we soft-deleted on the match drop.
- Pure separation (parallel tables with duplicated `opponent`, `formation`, `kickoff_time`) loses the player-journey rollup the existing activity-completion flow already provides. A coach completing a match would have to record it twice.
- Hybrid: planning is free of activity-table churn, but the moment a match becomes "real" (kickoff or completion), it joins the player journey via the activity link. Best of both, at the cost of one optional FK + a small `complete` endpoint that syncs the attendance rows.

### Why position TYPES not slot codes for eligibility

Per-player eligibility stored as `["GK", "DEF", "MID", "FWD"]` rather than `["GK", "RB", "CB", "LB", …]`. A coach knowing "Casper plays GK or CB" doesn't translate cleanly to "Casper plays GK, CB1 (right CB), CB2 (left CB)". The position-type bucket matches the coach's mental model and keeps the eligibility multi-select to 4 chips per player in the wizard. If/when a coach asks for "Pim plays RB but not LB", we add a per-tournament override map without breaking the type-level default.

### Why greedy not solver

Greedy assignment is ~50 LOC. A solver (even a small ILP) is closer to ~500 LOC plus a dependency on a PHP linear-programming library or an external service. The pilot's first ask was *"more or less equal"* — greedy delivers that. The day a second coach hits a real constraint corner the greedy can't handle, we upgrade. Day-1 assumption: manual override carries the long tail.

### Why minutes ticker is the headline UI

The pilot's exact words: *"there should be always an easy way to see the number of minutes played and expected to play."* That's not a stat displayed in a sub-tab — that's the most important number on the screen. Treating it as a sticky element with bar + numbers + sort options elevates it to "primary surface" rather than "incidental readout". Bench / start / full-match counters live on the same card because they're sibling fairness signals the coach uses in the same breath.

### Migrations

Three migrations expected:

- `00XX_tournaments.php` — creates `tt_tournaments`.
- `00YY_tournament_matches_squad_assignments.php` — creates the three child tables (one migration, three tables — they ship together).
- `00ZZ_seed_tournament_lookups.php` — seeds the `tournament_formation` + `tournament_opponent_level` rows. Idempotent (skip if names already exist).

All three additive, no backfill. The IDs slot into whatever is free at PR time.

### Schema for `tt_tournament_assignments.position_code`

Bench rows: `position_code = 'BENCH'`. Non-bench rows: a slot code from the active formation's `slot_labels` (e.g. `RB`, `CB`, `LB`, `RM`, …). The slot code is stored on the row even though it's derivable from the formation + slot index — denormalised so the planner grid query is a single `SELECT * FROM tt_tournament_assignments WHERE match_id = ? ORDER BY period_index, position_code`. Validation: the application layer enforces that the code is in the match's effective formation, but the schema doesn't.

### Definition-of-done expectations (CLAUDE.md § 9)

Standard DoD applies. The two checkboxes most likely to surface issues during review:

- **Player-centric**: The Tournament module's value is in the player rollup. Even though the profile tab is deferred to #0094, the schema (assignments keyed on `player_id` with an index for the rollup query) and the REST contract (`GET /tournaments/{id}/totals` returns per-player aggregates) must be in place for #0094 to land cleanly behind it.
- **Mobile-first**: The per-match planner grid is the densest UI in the plugin if rendered as a desktop grid on a 360px viewport. Stacked-card reflow with swipe-between-periods is non-negotiable; the spec calls it out and the AC enforces it.

### Triage hint

One feat ship, but big. Reasonable PR layout: one PR covering the whole thing, with the migrations + lookup seeds + REST controllers + frontend views + wizard + minutes ticker JS + docs + Dutch all in one. Reviewers should pair with the spec open. Expect ~25-30 files touched, ~3,500-4,500 LOC. The follow-up profile-tab idea (#0094) ships behind it.
