# TalentTrack v4.1.7 — Coach hero pivots to live-match CTA (closes #879)

## Pilot ask

> I need 2 to be done. Add it to the coach hero and only show the button when relevant

Follow-up to #847 (Match Execution). The only entry to the live-match surface was Dashboard → Activities → tap the match → "Start match" page-header action — four taps deep on a phone, awkward for an assistant on the sideline mid-match. The coach hero should surface the live-match CTA when there's actually something live to do.

## Changes

### `MatchExecutionRepository` — two new helpers

```php
public function findLiveForTeams( array $team_ids ): ?object;
public function findStartableForTeams( array $team_ids ): ?object;
```

- `findLiveForTeams()` joins `tt_match_execution × tt_activities`, filters `state ∈ {first_half, half_time, second_half}`, returns the most-recently-updated row enriched with title + opponent + session_date + team_id + score + location.
- `findStartableForTeams()` joins `tt_activities × tt_match_prep`, left-joins `tt_match_execution`, filters `session_date = current_time('Y-m-d')` + `activity_type_key IN ('match','game')` + `e.state IS NULL OR 'not_started'`. Returns the earliest-kickoff row.

Both tenancy-scoped via `CurrentClub::id()`.

### `MarkAttendanceHeroWidget` — early-return pivot

`render()` now calls `renderMatchExecutionBranch()` first. The branch:

1. Pulls the coach's teams via `QueryHelpers::get_teams_for_coach($user_id)`.
2. Tries `findLiveForTeams()` — if non-null, renders the **Resume match** variant:
    - Eyebrow: "Live · 1e 23'" (or "HT" during half-time)
    - Title: `<team> · <opponent>`
    - Detail: current score
    - Primary CTA: "Resume match" → `?tt_view=match-execution&activity_id=N`
    - No secondary
3. Otherwise tries `findStartableForTeams()` — if non-null, renders the **Start match** variant:
    - Eyebrow: "Today"
    - Title: `<team> · <opponent>`
    - Detail: kickoff time + location
    - Primary CTA: "Start match"
    - Secondary: ghost link "Edit prep" → `?tt_view=match-prep&activity_id=N`
4. Returns `null` to fall through to the existing mark-attendance code path.

The minute label for the live eyebrow is computed in PHP from `first_half_started_at` (or `second_half_started_at`) + the `pause_seconds` accumulator already on the execution row. UTC throughout.

### Persona fall-through

HoDs / admins who don't actually coach a team get an empty `team_ids` list from `get_teams_for_coach()` — both repository helpers short-circuit to null, and the existing mark-attendance hero renders unchanged.

## Files touched

- `src/Modules/MatchExecution/Repositories/MatchExecutionRepository.php` — two new methods (~80 lines).
- `src/Modules/PersonaDashboard/Widgets/MarkAttendanceHeroWidget.php` — imports + `renderMatchExecutionBranch()` + `liveMinuteLabel()` / `formatMinute()` helpers (~150 lines added).
- `talenttrack.php` + `readme.txt` + `CHANGES.md` — version bump.

## Out of scope

- A dashboard tile or quick-action entry for match execution — keep the hero as the single surfacing point.
- The "today date ≥ session date" gate on the existing page-header "Start match" action on the activity detail.
- Resume-pill on the activity list. The hero pivot covers the "where do I resume from?" need.
- Dutch translation strings — not added in this ship; the existing translation workflow handles new English strings in a follow-up i18n pass.

## Verification

- Coach with a live execution row on one of their teams → hero shows "Live · …", current score, "Resume match" CTA.
- Coach with a prepped match scheduled today and no execution started → hero shows "Today" + "Start match" + "Edit prep" secondary.
- Coach finishes a match (state goes to `completed`) → hero falls back to the default mark-attendance flow on next dashboard load.
- HoD without a personal team assignment → default hero, no pivot.
- Mobile 360px: existing hero layout, no extra CSS needed.

## Versioning

Patch bump (4.1.6 → 4.1.7). Same `4.1.x` series.

## Closes

- #879 — Coach-hero match-execution surface
