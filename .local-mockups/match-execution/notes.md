# Match Execution — design notes

## Target

Sideline phone budget: **360×640px viewport, gloves possible, daylight glare**. Coach is standing, holding a phone in one hand, scoreboard pen in the other. Tap targets must be huge; reading must work at arm's length in sun.

## Current shipped behaviour (v4.1.7 baseline)

Reached via:
- Coach dashboard hero (`MarkAttendanceHeroWidget`) when there's a live row OR a prepped match scheduled today.
- "Start match" detail-action on activity detail (match/game-type activities only).
- Direct URL `?tt_view=match-execution&activity_id=<N>`.

State machine (current):

```
not_started → first_half → half_time → second_half → finished
```

State machine (proposed by #1033 — extends with two-step finalisation):

```
not_started → first_half → half_time → second_half → pending_review → finalized
```

`pending_review` is what `finished` used to mean from the coach's first tap.
It's still editable — score, goals, subs all writable — but the timer is
frozen and a sticky `Finalize match` CTA replaces the live half-end CTA.
`finalized` is the new terminal: read-only, replaces the live editing zones
with a summary card. Chemistry analytics only consume `finalized` rows.

Rendered zones (per `FrontendMatchExecutionView.php`):
- Header (team + opponent + date)
- Score steppers (home/away ±)
- Timer (half label · MM:SS · Start/Pause)
- Specific Goals list (tap = +1, long-press = undo)
- Bench list (→ button → modal "who comes off?")
- On Pitch (hidden until first sub)
- Sticky footer (state-aware primary CTA + connection state)

## Friction points the mockup should address

| # | Friction (from Explore audit) | Mockup response |
|---|---|---|
| 1 | Sub modal: scrolling 15 players one-handed on a phone | Inline tap-to-swap inside the on-pitch list — no modal. Tap a bench player → on-pitch zone goes into "tap to replace" highlight mode → tap the player to swap. Single one-handed gesture. |
| 2 | Goal counters in a separate scroll zone, easy to lose context | Goal counters folded **inline into the on-pitch player row** (same line as the name). One flagged action per player by design — the action label sits in the player subtitle (`RW · crosses`) and the chip itself is just `−  count  +`. Tap `+` to log, tap `−` to undo a miscount. `−` disables at zero so the count never goes negative. |
| 3 | No half-length validation, no minute correction | Show the planned half length next to the timer (`23' / 45'`). After the half is ended, show actual vs. planned. Manual minute correction is out of scope for v1 — `notes.md` follow-up. |
| 4 | Page reload loses pause state | Reflect this in the timer block — show "Last synced 2s ago" sub-label and a tap-to-resync. Out of v1 ship; just visible affordance. |
| 5 | Sticky footer state-aware CTA hard to read | Big, full-width button + state label above it ("First half · 23' running") so the coach reads context + action in one glance. |

## Open questions for the design pass

- Does the score block need full-width thumb-reachable steppers, or are tighter centered steppers OK? (Mockup uses full-width.)
- Should we surface "specific goals" (flagged actions the coach wanted to count per player) as inline chips on the player row, or keep a separate top-of-page section?
- Is the "End first half" / "End match" CTA in the sticky footer enough, or do we need a duplicate inside the timer block for discoverability?
- Half-time view: should the timer be paused and visible, or replaced with a "Half-time break" placeholder?

## Pending review — what's different vs. live

| Zone | Live (first_half / second_half) | Pending review |
|---|---|---|
| Header | Team · opponent · kickoff time | Same |
| Banner | none | Orange "Match ended · pending review" with finish wall-clock time |
| Score | Steppers ± enabled | Steppers ± still enabled — coach can correct |
| Timer | Half pulse + Start/Pause/Resume | Half label desaturated, no buttons, clock shows full-time |
| Tracked players | +action buttons | +action buttons still enabled |
| Bench | → on starts swap | → on still starts swap (same UX) |
| Late event | hidden | Dashed-warn section with `+ Late goal` / `+ Late sub` buttons; minute-picker form expands inline |
| Subs recorded | hidden | Editable list of every sub that already happened; per-row minute number input + auto-derived half pill (1st/2nd) + on↔off pair + delete `×`. Changed minutes turn the input wrap warn-orange so the coach sees what's unsaved at a glance. Open question: same treatment for **goals recorded** — symmetric, but the user only asked for subs in the latest design pass. |
| Footer CTA | "End first/second half" | **Red `Finalize match`** — opens confirm dialog before transition |

The late-event affordance solves the "I forgot to log the goal at 31'"
problem. Without it the coach would have to either (a) live-tap the goal
button now and accept it gets the wrong minute, or (b) leave the data
wrong forever. The form's minute picker is a `type=number inputmode=numeric`
input — same pattern as the rest of the surface.

The **Subs recorded** section is the companion: it handles the "I logged
the sub but at the wrong minute" case. Every recorded sub gets a row with
an inline minute input — type a new value and the half pill auto-flips
(≤45 → 1st half, >45 → 2nd half) and the row gains an orange "Unsaved"
badge until the coach finalises. Delete (`×`) removes a sub entirely
(production needs a confirm dialog; the mock uses `window.confirm`).

### Surface width

Pending review and finalized states are at-home / desk review activities,
not sideline — the user explicitly OK'd these breaking the 360px mobile
budget. The frame widens to 720px on tablet and 880px on desktop in
those two states only. Live-match states stay locked at 360px (gloves-
on-phone). Within the wider frame, the score and timer blocks keep
their phone-size width so they don't get stretched into awkward empty
space; the editable subs list and bench naturally use the extra room.

## Finalized — what replaces the live UI

The entire editing surface (score, timer, on-pitch, bench, sub-target,
late-event) is hidden. A `.summary` block renders in its place containing:

- Score block with team abbreviations, big numerals, result label
- Meta rows: kickoff time, final whistle, finalised-by, attendance count
- Goals list (chronological, minute · player · kind pill)
- Substitutions list (chronological, minute · pair · sub pill)

The sticky footer's only CTA is `Back to match executions`, linking to
the new list surface (mocked in `.local-mockups/match-executions-list/`).

The `[Finished]` state in the mockup state picker is the legacy v4.1.7
behaviour — kept for diffing during the port, removable after ship.

## State picker

The mockup has a top picker (visible only because this is a mockup — strip in production) that flips the `body[data-state="..."]` attribute. Each state's affordances are toggled via CSS attribute selectors.

The picker now offers six states: `not_started`, `first_half`, `half_time`,
`second_half`, `pending_review`, `finalized`. The legacy `finished` button
remains for visual diff with the v4.1.7 baseline.

## What to test on real device

1. **Tap accuracy** for the score steppers at arm's length.
2. **Sub flow**: can a non-design user (one of the pilot coaches) figure out the bench → on-pitch swap without a tutorial?
3. **Glare**: does the colour palette hold up outdoors? (We currently use ink-on-paper light theme; may need a high-contrast "outdoor" variant.)
4. **Goal counter**: now an inline `−  count  +` stepper in the right column of the player row, on the same line as the name. One flagged action per player by design (the action type lives in the player subtitle). Test: at 360px, does the chip + name + position label fit on one line without truncation for typical Dutch names? If a player has a long name (e.g. "Tim van der Berg-Hendrikse"), the name truncates with ellipsis — verify the chip stays whole.
