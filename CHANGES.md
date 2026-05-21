# TalentTrack v3.110.216 — Match execution surface for assistant coaches (closes #847)

## Why

Pilot 2026-05-21: assistant coach asked for a live-match surface they can run from a phone on the sideline, capturing score / time / substitutions / specific-goal counters. Hard dependency on Match Prep (#838) — the prep provides the starting XI, the bench, the specific-goal players, and the half length, so the live screen renders without the assistant typing anything on a phone.

## What's in

### New module: `src/Modules/MatchExecution/`

- `MatchExecutionModule` — registers the REST controller.
- `Repositories/MatchExecutionRepository` — three-table CRUD (execution header, substitution event log, goal-event log) plus a `computeMinutes()` helper that walks the substitution log to derive per-player minutes.
- `Rest/MatchExecutionRestController` — 9 endpoints (start-half / end-half / pause / resume / score / substitution / goal-event POST / goal-event DELETE / finish). All cap-gated on `tt_edit_activities`. Substitution + goal-event endpoints idempotent on a client-generated `event_uuid` so the offline-queue flush can replay safely.
- `Frontend/FrontendMatchExecutionView` — mobile-first view at `?tt_view=match-execution&activity_id=N`. Refuses to launch without a Match Prep row and points the user back to the prep wizard.

### Migration 0120

Three new tables — `tt_match_execution`, `tt_match_execution_substitutions`, `tt_match_execution_goal_events`. The execution table carries `uuid CHAR(36) UNIQUE` per the SaaS-ready guidance. Sub + goal-event tables also carry a `event_uuid` UNIQUE so retries from the offline queue are no-ops.

Plus column adds:
- `tt_activities.home_score TINYINT UNSIGNED` (end-of-match copy)
- `tt_activities.away_score TINYINT UNSIGNED`
- `tt_attendance.minutes_played SMALLINT UNSIGNED` (per-player minutes from the sub log)

Column adds are guarded by `SHOW COLUMNS LIKE` so re-runs don't error on already-migrated installs.

### Mobile layout

Fits the 360×640 phone viewport without scrolling:

- header (40px): match title + date
- score steppers (48px): home / away with ± buttons
- timer (44px): half label · MM:SS clock · Start/Pause toggle
- specific goals (20 + 3×48): tap = +1, long-press = -1 (single-level undo)
- bench (20 + 5×40): tap → opens bottom-sheet picker of on-pitch players, sub commits immediately with toast
- sticky bottom action (60): label cycles through `Start` → `End 1st half` → `Start 2nd half` → `End match` → `Match finished`

### Substitution flow

Two taps: tap → on the bench player, pick the on-pitch player to come off in the bottom-sheet picker. Swap commits immediately; the bench list and the on-pitch list update locally before the network round-trip; the substitution log captures `(half, minute_in_half, player_off_id, player_on_id, event_uuid)`. A re-entry later in the same half is allowed.

### End-of-match auto-flow

Tap "End match" → POST `/finish` →

1. Execution row marked `state = 'finished'`, `second_half_ended_at = now()`.
2. `tt_activities.activity_status_key = 'completed'` AND `plan_state = 'completed'` AND the home/away score copied.
3. Per-player minutes computed from the substitution log + starting XI walk + half lengths.
4. For each available player from the prep snapshot: `tt_attendance` row upserted with the right `status` and the computed `minutes_played`.

### Offline buffer

Online-first. Every action POSTs to REST immediately. Failures land in `localStorage` keyed `tt_match_exec_queue_<activity_id>`. A small "Offline — actions queued (N)" banner appears at the top when the queue is non-empty. On reconnect (or next successful response), the queue flushes in order. Endpoints are idempotent on `event_uuid` so a double-flush doesn't double-insert.

NOT full offline-first — the initial page load still requires a server. The assistant must reach the sideline with a live session loaded; once loaded, the page survives dead zones.

## How to test

1. Apply migrations — confirm `0120_match_execution` in `tt_migrations`; three new tables exist; `home_score`/`away_score` on `tt_activities`; `minutes_played` on `tt_attendance`.
2. On a match-type activity that has a Match Prep row, the "Start match" page-header action appears alongside "Plan match prep".
3. On a match without a prep row, "Start match" still appears (since the gate is just type=match/game + cap) but the view shows a "Plan this match first" notice with a link to the wizard.
4. Open the view on a phone (or 360×640 DevTools): everything fits without scrolling. Score steppers, timer toggle, specific-goal counters, sub flow, sticky bottom-bar all behave per the spec.
5. Tap "End match" → activity flips to `completed`, score copied, per-player minutes populated on `tt_attendance`.
6. Pull network connection mid-match (DevTools → Offline), tap a few subs + score changes — confirm the offline banner shows the queue count. Re-enable network — confirm the queue flushes and the banner clears.
7. Test idempotency: with the queue holding a substitution, manually re-POST the same `event_uuid` via curl — confirm only one row in `tt_match_execution_substitutions`.

## Player-centric framing

Helps answer **"Who came on, who came off, when, and how many minutes did each player get?"** — the substitution log + the auto-computed `minutes_played` give the coach a clean record of every player's match contribution without anyone typing it in after the fact.

## Out of scope (v1)

- Per-goal records (scorer / assist / minute) — score is a running tally only.
- Yellow / red cards.
- Multi-device sync — single device per match in v1.
- Stoppage / injury time tracking — the assistant keeps clicking past `half_length_minutes`.
- Automatic substitution suggestions ("Khalid suggested for Joeri based on positional fit").
- Voice-input shortcuts.
- Live spectator surface (players / parents watching the score remotely).
- Coach-side chat / annotations.
- Video integration.
- Post-match analyst-feedback capture (the analyst flag from #838 persists who's appointed; capturing their actual feedback is a future flow).
