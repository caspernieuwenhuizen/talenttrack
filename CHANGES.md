# TalentTrack v4.1.3 — Bulk behaviour grid (closes #872, closes epic #867)

## Pilot context

Sub-ship C — the final piece of epic #867 (behaviour + potential discoverability gap).

The natural coach workflow is "I just finished a session; rate the 18 players who showed up". The per-player surfaces (sub-ships A + B) suit exception recording — a single rating after a specific incident. Bulk post-session entry needs its own surface. This is that surface.

## Scope

New view at `?tt_view=team-behaviour-capture&team_id=N`. One form-level activity picker at the top (defaults to the team's most recent completed activity). Below it, one row per active player on the team with a rating dropdown + notes input. Submit writes one `tt_player_behaviour_ratings` row per non-blank rating, all sharing the chosen `related_activity_id`.

- **Cap**: `tt_rate_player_behaviour`. Users without it see "not authorised".
- **Team scope**: coaches see only teams they're assigned to via `QueryHelpers::get_teams_for_coach`; HoDs + admins see all teams.
- **Skippable**: blank rating fields write no row.
- **Partial success**: out-of-range ratings produce a per-row error; the valid rows on the same submit still write.
- **Entry**: a "Bulk-record behaviour" button on the team detail view's Roster section, cap-gated.

## Files

### New

- `src/Shared/Frontend/FrontendTeamBehaviourCaptureView.php` — view class with `render()`, `handlePost()`, helpers. Pattern mirrors `FrontendPlayerStatusCaptureView` (form POST + nonce + flash).
- `assets/css/frontend-team-behaviour-capture.css` — mobile-first grid. Rows stack at 360px; controls side-by-side at 600px+. Sticky footer (with `env(safe-area-inset-bottom)`) keeps Save accessible on phones; on desktop the footer floats with the page.

### Edited

- `src/Shared/Frontend/DashboardShortcode.php` — new `case 'team-behaviour-capture'` dispatch.
- `src/Shared/Frontend/FrontendTeamDetailView.php`:
    - `renderRoster()` now takes an optional `$team_id` so the new "Bulk-record behaviour" button can link to the view.
    - The call site at `self::renderRoster( $roster )` becomes `self::renderRoster( $roster, $team_id )`.

## Out of scope

- A REST endpoint `POST /teams/{id}/behaviour-ratings/bulk`. Form-POST is enough for v1; a REST path makes sense once the SaaS front-end consumes it. Tracked in the issue body as a deferred enhancement.
- Bulk grid for **potential** band. Quarterly cadence; the bulk pattern doesn't fit.
- An "after activity completion" link from the activity flow that pre-fills the picker. Future polish.

## Verification

- Coach with 18 players on U17-1: rate 12, leave 6 blank → 12 rows in `tt_player_behaviour_ratings` all with the same `related_activity_id` + `rated_by`. The 6 blank players → 0 rows.
- Submit with one rating below `rating_min`: row-level error message; the other 11 valid rows still write.
- Coach attempts `?tt_view=team-behaviour-capture&team_id=999` for a team they don't have access to → matrix-scope gate denies.
- Mobile 360px: vertical stack, sticky footer above iOS home indicator, no horizontal scroll.

## Epic close-out

This is the last of the four child ships under epic #867. The full set:

| # | Ship | Closes |
|---|---|---|
| D | Optional Behaviour step in evaluation wizard | #869 |
| A | Hero quick-record popovers + cap-aware rendering | #870 |
| B | "Behaviour pending" dashboard widget | #871 |
| C | Per-team bulk behaviour grid | #872 ← this ship |

Acceptance for the epic: *"a coach who has never used the feature can record a behaviour rating within 60 seconds of landing on the dashboard, without external instruction."* With four parallel entry points (wizard step, hero popover, pending widget, bulk grid) and the cap-aware affordances on the player hero, behaviour recording is now self-discoverable across the surfaces a coach actually uses day-to-day.

## Versioning

Patch bump (4.1.2 → 4.1.3). Still inside the `4.1.x` epic-feature series.

## Closes

- #872 — Behaviour discoverability — C: bulk grid
- #867 — parent epic (all four sub-ships now merged)
