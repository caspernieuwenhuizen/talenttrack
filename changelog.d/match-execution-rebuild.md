# Match execution: minutes-authority arbiter, tracked players, frontend cleanup

Bump: minor

Rebuilt the match-execution surface to remove the "semi-connected parts"
friction:

- **Minutes have one owner.** When a match has a running/recorded execution,
  its per-player minutes are derived from the starting XI + substitution log,
  and the only way to hand-correct a figure is the per-player override on the
  match-execution screen (Recorded minutes → Correct). The manual minutes
  field on the attendance screen now defers to the execution (it reports that
  minutes are managed there). Matches that were never run through execution
  keep manual minute entry exactly as before.
- **Tracked players.** Players flagged in the match plan (a specific goal or an
  attention note) get a live +/- counter during the match to tally a
  development action. These are recorded as their own timed events — separate
  from goals, so they never affect the score.
- **Cleaner, faster surface.** The four match-execution stylesheets are
  consolidated into one, and the last inline styles and scripts were moved out
  of the page into the enqueued sheet and JS module.
