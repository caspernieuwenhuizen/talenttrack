# Match Execution — design notes

## Target

Sideline phone budget: **360×640px viewport, gloves possible, daylight glare**. Coach is standing, holding a phone in one hand, scoreboard pen in the other. Tap targets must be huge; reading must work at arm's length in sun.

## Current shipped behaviour (v4.1.7 baseline)

Reached via:
- Coach dashboard hero (`MarkAttendanceHeroWidget`) when there's a live row OR a prepped match scheduled today.
- "Start match" detail-action on activity detail (match/game-type activities only).
- Direct URL `?tt_view=match-execution&activity_id=<N>`.

State machine:

```
not_started → first_half → half_time → second_half → finished
```

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
| 2 | Goal counters in a separate scroll zone, easy to lose context | Goal counters folded into the on-pitch player row. The flagged players get a `+goal` button inline; long-press undoes the most recent. |
| 3 | No half-length validation, no minute correction | Show the planned half length next to the timer (`23' / 45'`). After the half is ended, show actual vs. planned. Manual minute correction is out of scope for v1 — `notes.md` follow-up. |
| 4 | Page reload loses pause state | Reflect this in the timer block — show "Last synced 2s ago" sub-label and a tap-to-resync. Out of v1 ship; just visible affordance. |
| 5 | Sticky footer state-aware CTA hard to read | Big, full-width button + state label above it ("First half · 23' running") so the coach reads context + action in one glance. |

## Open questions for the design pass

- Does the score block need full-width thumb-reachable steppers, or are tighter centered steppers OK? (Mockup uses full-width.)
- Should we surface "specific goals" (flagged actions the coach wanted to count per player) as inline chips on the player row, or keep a separate top-of-page section?
- Is the "End first half" / "End match" CTA in the sticky footer enough, or do we need a duplicate inside the timer block for discoverability?
- Half-time view: should the timer be paused and visible, or replaced with a "Half-time break" placeholder?

## State picker

The mockup has a top picker (visible only because this is a mockup — strip in production) that flips the `body[data-state="..."]` attribute. Each state's affordances are toggled via CSS attribute selectors.

## What to test on real device

1. **Tap accuracy** for the score steppers at arm's length.
2. **Sub flow**: can a non-design user (one of the pilot coaches) figure out the bench → on-pitch swap without a tutorial?
3. **Glare**: does the colour palette hold up outdoors? (We currently use ink-on-paper light theme; may need a high-contrast "outdoor" variant.)
4. **Goal counter**: tap-to-add + long-press-to-undo — does the long-press feel discoverable enough, or do we need an explicit undo affordance?
